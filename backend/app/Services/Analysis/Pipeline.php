<?php

namespace App\Services\Analysis;

use App\Models\AccountingValidation;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AnalysisRun;
use App\Models\BudgetLine;
use App\Models\CrawlRun;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ExtractionRun;
use App\Models\FinancialAccount;
use App\Models\FinancialFact;
use App\Models\FinancialPeriod;
use App\Models\Finding;
use App\Models\FindingEvidence;
use App\Models\Question;
use App\Models\Report;
use App\Models\Source;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class Pipeline
{
    public function __construct(private AnalysisClient $client, private AuditLogger $audit)
    {
    }

    public function handle(AnalysisRun $run): void
    {
        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'error' => null]);
        try {
            $source = Source::query()->where('municipality_id', $run->municipality_id)->where('active', true)->firstOrFail();
            $this->transition($run, 'discovering');
            $discovery = $this->step($run, 'source_discovery', [
                'url' => $source->url,
                'allowed_hosts' => $source->allowed_hosts ?? [],
            ]);
            $documents = $this->prioritize($this->persistDiscovery($run, $source, $discovery['output'] ?? []));
            $this->transition($run, 'acquiring');
            $facts = [];
            $statements = [];
            $downloaded = 0;
            $max = $source->max_documents ?? 2;
            $delay = (int) ($discovery['output']['robots']['crawl_delay'] ?? $source->crawl_delay_seconds ?? 0);
            foreach ($documents as $index => $document) {
                $this->guardCancel($run);
                if (! $document->document_type || $document->document_type === 'unbekannt') {
                    continue;
                }
                if ($downloaded >= $max) {
                    $document->update(['status' => 'discovered']);
                    continue;
                }
                if ($downloaded > 0 && $delay > 0) {
                    sleep($delay);
                }
                $version = $this->acquire($run, $document);
                if (! $version) {
                    continue;
                }
                $downloaded++;
                $this->transition($run, 'extracting');
                $extracted = $this->step($run, 'financial_extraction', [
                    'path' => Storage::disk($version->storage_disk)->path($version->storage_path),
                    'document_key' => 'doc-'.$document->id,
                    'source_url' => $document->canonical_url,
                    'title' => $document->title,
                ], 'doc-'.$document->id);
                $facts = array_merge($facts, $this->persistFacts($run, $document, $version, $extracted['output'] ?? []));
                foreach ($extracted['output']['statements'] ?? [] as $statement) {
                    $statements[] = $statement;
                }
            }
            $this->finish($run, $source, $facts, $statements, count($documents), $downloaded, []);
        } catch (Throwable $exception) {
            $this->fail($run, $exception);
            throw $exception;
        }
    }

    /**
     * @param  list<array{path:string,url:string,title:string,document_type:string,fiscal_year:int}>  $captures
     * @param  list<string>  $gaps
     */
    public function importCaptured(AnalysisRun $run, array $captures, array $gaps = []): void
    {
        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'error' => null]);
        try {
            $source = Source::query()->where('municipality_id', $run->municipality_id)->where('active', true)->firstOrFail();
            $facts = [];
            $statements = [];
            foreach ($captures as $capture) {
                $this->guardCancel($run);
                $bytes = file_get_contents($capture['path']);
                if ($bytes === false || ! str_starts_with($bytes, '%PDF')) {
                    throw new RuntimeException('Die Datei ist kein lesbares PDF: '.$capture['path']);
                }
                $host = parse_url($capture['url'], PHP_URL_HOST);
                $allowed = array_map('strtolower', $source->allowed_hosts ?? []);
                if (! is_string($host) || ! in_array(strtolower($host), $allowed, true)) {
                    throw new RuntimeException('Die Quelladresse liegt außerhalb der erlaubten Hosts.');
                }
                $document = Document::query()->updateOrCreate(
                    ['municipality_id' => $run->municipality_id, 'canonical_url' => $capture['url']],
                    [
                        'source_id' => $source->id,
                        'title' => $capture['title'],
                        'document_type' => $capture['document_type'],
                        'fiscal_year' => $capture['fiscal_year'],
                        'status' => 'archived',
                    ],
                );
                $directory = 'municipalities/'.$run->municipality_id;
                $relative = $directory.'/document-'.$document->id.'.bin';
                Storage::disk('documents')->put($relative, $bytes);
                $sha = hash('sha256', $bytes);
                $version = DocumentVersion::query()->firstOrCreate(
                    ['document_id' => $document->id, 'sha256' => $sha],
                    [
                        'version_number' => ($document->versions()->max('version_number') ?? 0) + 1,
                        'mime' => 'application/pdf',
                        'byte_size' => strlen($bytes),
                        'storage_disk' => 'documents',
                        'storage_path' => $relative,
                        'source_url' => $capture['url'],
                        'fetched_at' => \Carbon\Carbon::createFromTimestamp((int) filemtime($capture['path'])),
                        'http_status' => null,
                    ],
                );
                $this->transition($run, 'extracting');
                $extracted = $this->step($run, 'financial_extraction', [
                    'path' => Storage::disk($version->storage_disk)->path($version->storage_path),
                    'document_key' => 'doc-'.$document->id,
                    'source_url' => $document->canonical_url,
                    'title' => $document->title,
                ], 'doc-'.$document->id);
                $facts = array_merge($facts, $this->persistFacts($run, $document, $version, $extracted['output'] ?? []));
                foreach ($extracted['output']['statements'] ?? [] as $statement) {
                    $statements[] = $statement;
                }
            }
            $this->finish($run, $source, $facts, $statements, count($captures), count($captures), $gaps);
        } catch (Throwable $exception) {
            $this->fail($run, $exception);
            throw $exception;
        }
    }

    private function finish(AnalysisRun $run, Source $source, array $facts, array $statements, int $documentsSeen, int $downloaded, array $extraGaps): void
    {
            $this->transition($run, 'validating');
            $validation = $this->step($run, 'accounting_validation', ['facts' => $facts]);
            $this->persistValidations($run, $validation['output']['validations'] ?? []);
            $this->transition($run, 'harmonizing');
            $comparison = $this->step($run, 'historical_comparison', ['facts' => $facts]);
            $this->transition($run, 'anomalies');
            $anomalies = $this->step($run, 'anomaly_detection', [
                'facts' => $facts,
                'validations' => $validation['output']['validations'] ?? [],
                'investments' => $comparison['output']['investments'] ?? [],
            ]);
            $this->transition($run, 'evidence_review');
            $evidence = $this->step($run, 'evidence_review', [
                'facts' => $facts,
                'findings' => $anomalies['output']['findings'] ?? [],
            ]);
            $findingMap = $this->persistFindings($run, $evidence['output']['findings'] ?? [], $facts);
            $this->step($run, 'cfo_sustainability', [
                'municipality' => ['name' => $run->municipality->name],
                'facts' => $facts,
                'findings' => $evidence['output']['findings'] ?? [],
            ]);
            $this->step($run, 'administration_context', ['texts' => [], 'statements' => $statements]);
            $questions = $this->step($run, 'opposition_accountability', [
                'findings' => $evidence['output']['findings'] ?? [],
            ]);
            $this->persistQuestions($run, $questions['output']['questions'] ?? [], $findingMap);
            $this->step($run, 'citizen', [
                'municipality' => ['name' => $run->municipality->name],
                'facts' => $facts,
                'findings' => $evidence['output']['findings'] ?? [],
            ]);
            $this->step($run, 'document_classification', [
                'title' => $source->name,
                'url' => $source->url,
            ]);
            $this->transition($run, 'reporting');
            $limit = $source->max_documents ?? 'unbegrenzt';
            $reports = $this->step($run, 'reporting', [
                'municipality' => ['name' => $run->municipality->official_name],
                'facts' => $facts,
                'findings' => $evidence['output']['findings'] ?? [],
                'questions' => $questions['output']['questions'] ?? [],
                'comparisons' => $comparison['output']['comparisons'] ?? [],
                'validations' => $validation['output']['validations'] ?? [],
                'generated_on' => now()->toDateString(),
                'data_gaps' => array_values(array_filter(array_merge($extraGaps, [
                    ($downloaded < $documentsSeen) ? 'Es wurden '.$downloaded.' von '.$documentsSeen.' entdeckten Dokumenten heruntergeladen. Das Limit der Quelle ist '.$limit.'.' : null,
                    'Schuldenanfangsbestand, Zugänge und Abgänge wurden nicht als vollständige Fortschreibung extrahiert.',
                ]))),
            ]);
            $this->persistReports($run, $reports['output']['reports'] ?? []);
            $source->update(['last_crawled_at' => now(), 'last_status' => $downloaded > 0 ? 'completed' : 'data_gap']);
            $run->update([
                'status' => 'completed',
                'workflow_state' => 'awaiting_approval',
                'finished_at' => now(),
                'summary' => [
                    'documents_seen' => $documentsSeen,
                    'documents_downloaded' => $downloaded,
                    'facts' => count($facts),
                    'findings' => count($evidence['output']['findings'] ?? []),
                ],
            ]);
            $this->audit->log('analysis.completed', 'analysis_run', $run->id, $run->summary ?? [], $run->user_id);
    }

    private function fail(AnalysisRun $run, Throwable $exception): void
    {
        $run->update([
            'status' => str_contains($exception->getMessage(), 'cancelled') ? 'cancelled' : 'failed',
            'workflow_state' => str_contains($exception->getMessage(), 'cancelled') ? 'cancelled' : 'failed',
            'finished_at' => now(),
            'error' => $exception->getMessage(),
        ]);
    }

    private function step(AnalysisRun $run, string $key, array $context, ?string $scope = null): array
    {
        $config = AgentConfig::query()->where('agent_key', $key)->firstOrFail();
        $existingQuery = AgentRun::query()
            ->where('analysis_run_id', $run->id)
            ->where('agent_config_id', $config->id)
            ->where('status', 'completed');
        if ($scope !== null) {
            $existingQuery->where('input_excerpt', $scope);
        }
        $existing = $existingQuery->first();
        if ($existing) {
            return ['agent_key' => $key, 'status' => 'completed', 'output' => $existing->output ?? []];
        }
        if (! $config->enabled) {
            AgentRun::query()->create([
                'analysis_run_id' => $run->id,
                'agent_config_id' => $config->id,
                'status' => 'skipped',
                'error' => 'Agent ist deaktiviert.',
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            return ['agent_key' => $key, 'status' => 'skipped', 'output' => []];
        }
        $attempt = 0;
        $lastError = null;
        while ($attempt < max(1, (int) $config->max_retries)) {
            $attempt++;
            $started = now();
            try {
                $result = $this->client->agent($key, $context, (int) $config->timeout_seconds);
                AgentRun::query()->create([
                    'analysis_run_id' => $run->id,
                    'agent_config_id' => $config->id,
                    'status' => $result['status'] ?? 'completed',
                    'attempt' => $attempt,
                    'input_excerpt' => $scope ?? mb_substr(json_encode(array_keys($context)) ?: '', 0, 500),
                    'output' => $result['output'] ?? [],
                    'error' => $result['error'] ?? null,
                    'started_at' => $started,
                    'finished_at' => now(),
                    'latency_ms' => (int) $started->diffInMilliseconds(now()),
                ]);
                if (($result['status'] ?? 'completed') === 'failed') {
                    throw new RuntimeException($result['error'] ?? 'Agent fehlgeschlagen');
                }

                return $result;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }
        AgentRun::query()->create([
            'analysis_run_id' => $run->id,
            'agent_config_id' => $config->id,
            'status' => 'failed',
            'attempt' => $attempt,
            'error' => $lastError,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        throw new RuntimeException($key.': '.$lastError);
    }

    private function persistDiscovery(AnalysisRun $run, Source $source, array $output): array
    {
        $crawl = CrawlRun::query()->create([
            'source_id' => $source->id,
            'analysis_run_id' => $run->id,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'documents_seen' => count($output['documents'] ?? []),
            'stats' => ['data_gaps' => $output['data_gaps'] ?? [], 'robots' => $output['robots'] ?? []],
        ]);
        $documents = [];
        foreach ($output['documents'] ?? [] as $item) {
            $documents[] = Document::query()->updateOrCreate(
                ['municipality_id' => $run->municipality_id, 'canonical_url' => $item['url']],
                [
                    'source_id' => $source->id,
                    'title' => $item['title'] ?: $item['url'],
                    'document_type' => $item['document_type'] ?? 'unbekannt',
                    'fiscal_year' => $item['fiscal_year'] ?? null,
                    'supplement_ordinal' => $item['supplement_ordinal'] ?? null,
                    'status' => 'discovered',
                ],
            );
        }
        $crawl->update(['documents_seen' => count($documents)]);

        return $documents;
    }

    private function acquire(AnalysisRun $run, Document $document): ?DocumentVersion
    {
        $directory = 'municipalities/'.$run->municipality_id;
        Storage::disk('documents')->makeDirectory($directory);
        $result = $this->step($run, 'scraper_acquisition', [
            'url' => $document->canonical_url,
            'dest_dir' => Storage::disk('documents')->path($directory),
            'filename' => 'document-'.$document->id.'.bin',
        ], $document->canonical_url);
        $output = $result['output'] ?? [];
        if (($result['status'] ?? '') !== 'completed' || empty($output['sha256'])) {
            $document->update(['status' => 'failed']);

            return null;
        }
        $version = DocumentVersion::query()->firstOrCreate(
            ['document_id' => $document->id, 'sha256' => $output['sha256']],
            [
                'version_number' => ($document->versions()->max('version_number') ?? 0) + 1,
                'mime' => $output['content_type'] ?? 'application/pdf',
                'byte_size' => $output['byte_size'] ?? 0,
                'storage_disk' => 'documents',
                'storage_path' => $directory.'/document-'.$document->id.'.bin',
                'source_url' => $document->canonical_url,
                'fetched_at' => now(),
                'http_status' => $output['status_code'] ?? null,
            ],
        );
        $document->update(['status' => 'archived']);

        return $version;
    }

    private function persistFacts(AnalysisRun $run, Document $document, DocumentVersion $version, array $output): array
    {
        $extraction = ExtractionRun::query()->create([
            'document_version_id' => $version->id,
            'analysis_run_id' => $run->id,
            'status' => empty($output['facts']) ? 'data_gap' : 'completed',
            'method' => $output['method'] ?? null,
            'started_at' => now(),
            'finished_at' => now(),
            'stats' => ['fact_count' => $output['fact_count'] ?? count($output['facts'] ?? []), 'warnings' => $output['warnings'] ?? [], 'data_gaps' => $output['data_gaps'] ?? []],
        ]);
        $stored = [];
        foreach ($output['facts'] ?? [] as $fact) {
            $period = null;
            if (! empty($fact['fiscal_year'])) {
                $period = FinancialPeriod::query()->firstOrCreate(
                    ['municipality_id' => $run->municipality_id, 'fiscal_year' => $fact['fiscal_year']],
                    ['label' => (string) $fact['fiscal_year']],
                );
            }
            $account = null;
            if (! empty($fact['code'])) {
                $account = FinancialAccount::query()->firstOrCreate(
                    ['code' => $fact['code'], 'statement' => $fact['statement'], 'vrv_version' => '2015'],
                    ['name' => mb_substr($fact['original_label'] ?? $fact['code'], 0, 255), 'level' => strlen((string) $fact['code'])],
                );
            }
            $line = BudgetLine::query()->create([
                'document_version_id' => $version->id,
                'extraction_run_id' => $extraction->id,
                'code' => $fact['code'] ?? null,
                'original_label' => $fact['original_label'] ?? '',
                'statement' => $fact['statement'] ?? 'unbekannt',
                'scope' => $fact['scope'] ?? 'unbekannt',
                'fiscal_year' => $fact['fiscal_year'] ?? null,
                'raw_line' => $fact['excerpt'] ?? null,
                'amounts' => ['amount' => $fact['amount'] ?? null, 'value_kind' => $fact['value_kind'] ?? null],
            ]);
            $model = FinancialFact::query()->updateOrCreate(
                ['document_version_id' => $version->id, 'local_key' => $fact['local_id']],
                [
                    'municipality_id' => $run->municipality_id,
                    'financial_period_id' => $period?->id,
                    'financial_account_id' => $account?->id,
                    'extraction_run_id' => $extraction->id,
                    'budget_line_id' => $line->id,
                    'code' => $fact['code'] ?? null,
                    'original_label' => $fact['original_label'] ?? '',
                    'statement' => $fact['statement'] ?? 'unbekannt',
                    'scope' => $fact['scope'] ?? 'unbekannt',
                    'value_kind' => $fact['value_kind'] ?? 'unbekannt',
                    'nature' => $fact['nature'] ?? 'sonstige',
                    'amount_original' => $fact['amount_original'] ?? (string) ($fact['amount'] ?? '0,00'),
                    'amount' => $fact['amount'] ?? 0,
                    'currency' => $fact['currency'] ?? 'EUR',
                    'page' => $fact['page'] ?? null,
                    'excerpt' => $fact['excerpt'] ?? null,
                    'extraction_method' => $fact['extraction_method'] ?? 'unknown',
                    'confidence' => $fact['confidence'] ?? 0,
                    'quality_flags' => $fact['quality_flags'] ?? [],
                    'comparable' => $fact['comparable'] ?? true,
                ],
            );
            $fact['database_id'] = $model->id;
            $stored[] = $fact;
        }

        return $stored;
    }

    private function persistValidations(AnalysisRun $run, array $validations): void
    {
        foreach ($validations as $validation) {
            AccountingValidation::query()->create([
                'analysis_run_id' => $run->id,
                'municipality_id' => $run->municipality_id,
                'rule_code' => $validation['rule_code'],
                'status' => $validation['status'],
                'formula' => $validation['formula'],
                'inputs' => $validation['inputs'] ?? [],
                'result' => $validation['result'] ?? [],
                'tolerance' => $validation['tolerance'] ?? null,
                'message' => $validation['message'] ?? '',
                'page' => $validation['page'] ?? null,
            ]);
        }
    }

    private function persistFindings(AnalysisRun $run, array $findings, array $facts): array
    {
        $byLocal = [];
        foreach ($facts as $fact) {
            $byLocal[$fact['local_id']] = $fact;
        }
        $map = [];
        foreach ($findings as $index => $finding) {
            $model = Finding::query()->create([
                'analysis_run_id' => $run->id,
                'municipality_id' => $run->municipality_id,
                'rule_code' => $finding['rule_code'],
                'title' => $finding['title'],
                'summary' => $finding['summary'],
                'years' => $finding['years'] ?? [],
                'positions' => $finding['positions'] ?? [],
                'inputs' => $finding['inputs'] ?? [],
                'absolute_deviation' => $finding['absolute_deviation'] ?? null,
                'relative_deviation' => $finding['relative_deviation'] ?? null,
                'method' => $finding['method'] ?? null,
                'possible_explanations' => $finding['possible_explanations'] ?? [],
                'counterarguments' => $finding['counterarguments'] ?? [],
                'relevance' => $finding['relevance'] ?? null,
                'priority' => $finding['priority'] ?? 3,
                'data_quality' => $finding['data_quality'] ?? null,
                'confidence' => $finding['confidence'] ?? null,
                'review_status' => $finding['review_status'] ?? 'unreviewed',
                'approval_status' => 'draft',
                'review_note' => $finding['review_note'] ?? null,
                'financial_relevance' => $finding['disclaimer'] ?? null,
            ]);
            foreach ($finding['evidence'] ?? [] as $evidence) {
                $fact = $byLocal[$evidence['local_fact_id'] ?? ''] ?? null;
                FindingEvidence::query()->create([
                    'finding_id' => $model->id,
                    'financial_fact_id' => $fact['database_id'] ?? null,
                    'document_version_id' => $fact ? FinancialFact::query()->find($fact['database_id'])?->document_version_id : null,
                    'source_url' => $evidence['source_url'] ?? null,
                    'page' => $evidence['page'] ?? null,
                    'excerpt' => $evidence['excerpt'] ?? null,
                    'role' => $evidence['role'] ?? 'supports',
                ]);
            }
            $map[$finding['rule_code']] = $model->id;
        }

        return $map;
    }

    private function persistQuestions(AnalysisRun $run, array $questions, array $findingMap): void
    {
        foreach ($questions as $question) {
            Question::query()->create([
                'municipality_id' => $run->municipality_id,
                'analysis_run_id' => $run->id,
                'finding_id' => $findingMap[$question['topic'] ?? ''] ?? null,
                'audience' => $question['audience'] ?? 'council',
                'topic' => $question['topic'] ?? 'allgemein',
                'body' => $question['body'],
                'status' => 'open',
                'priority' => $question['priority'] ?? 3,
            ]);
        }
    }

    private function persistReports(AnalysisRun $run, array $reports): void
    {
        foreach ($reports as $report) {
            Report::query()->create([
                'municipality_id' => $run->municipality_id,
                'analysis_run_id' => $run->id,
                'report_type' => $report['report_type'],
                'title' => $report['title'],
                'status' => 'draft',
                'version_number' => 1,
                'body_markdown' => $report['markdown'],
                'data_as_of' => $report['data_as_of'] ?? now()->toDateString(),
                'period_start' => $report['period_start'] ?? null,
                'period_end' => $report['period_end'] ?? null,
                'limitations' => $report['limitations'] ?? null,
                'source_index' => $report['sources'] ?? [],
            ]);
        }
    }

    private function prioritize(array $documents): array
    {
        usort($documents, function (Document $left, Document $right): int {
            $score = function (Document $document): string {
                $type = match ($document->document_type) {
                    'rechnungsabschluss' => 0,
                    'voranschlag' => 1,
                    'nachtragsvoranschlag' => 2,
                    'finanzplan' => 3,
                    default => 9,
                };
                $preferredYear = $document->fiscal_year === 2024 ? 0 : 1;

                return sprintf('%d-%d-%04d', $preferredYear, $type, 9999 - (int) $document->fiscal_year);
            };

            return $score($left) <=> $score($right);
        });

        return $documents;
    }

    private function transition(AnalysisRun $run, string $state): void
    {
        $this->guardCancel($run);
        $run->update(['workflow_state' => $state]);
    }

    private function guardCancel(AnalysisRun $run): void
    {
        if ($run->fresh()->cancel_requested) {
            throw new RuntimeException('cancelled');
        }
    }
}
