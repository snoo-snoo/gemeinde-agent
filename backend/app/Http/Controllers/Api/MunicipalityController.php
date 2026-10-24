<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunAnalysisJob;
use App\Models\AccountingValidation;
use App\Models\AnalysisRun;
use App\Models\Document;
use App\Models\FinancialFact;
use App\Models\Finding;
use App\Models\Municipality;
use App\Models\Question;
use App\Models\Report;
use App\Models\Source;
use App\Services\Analysis\AnalysisClient;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MunicipalityController extends Controller
{
    public function index()
    {
        return Municipality::query()->withCount(['documents', 'sources'])->orderBy('name')->get();
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'official_name' => ['required', 'string', 'max:255'],
            'bundesland' => ['required', 'string', 'max:64'],
            'slug' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'url'],
            'gemeindekennziffer' => ['nullable', 'string', 'max:16'],
        ]);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $municipality = Municipality::query()->create($data);
        $audit->log('municipality.created', 'municipality', $municipality->id, ['slug' => $municipality->slug]);

        return response()->json($municipality, 201);
    }

    public function show(Municipality $municipality)
    {
        $latest = AnalysisRun::query()->where('municipality_id', $municipality->id)->latest()->first();
        $years = FinancialFact::query()->where('financial_facts.municipality_id', $municipality->id)
            ->join('financial_periods', 'financial_periods.id', '=', 'financial_facts.financial_period_id')
            ->distinct()->orderBy('fiscal_year')->pluck('fiscal_year');
        $facts = FinancialFact::query()->where('financial_facts.municipality_id', $municipality->id)
            ->join('financial_periods', 'financial_periods.id', '=', 'financial_facts.financial_period_id')
            ->where('scope', 'bereinigt')->where('value_kind', 'ist')->whereIn('financial_facts.code', ['SA0', '21', '22', '34', 'SA5', 'SA1'])
            ->orderBy('financial_periods.fiscal_year')
            ->get(['financial_facts.code', 'financial_facts.original_label', 'financial_facts.amount', 'financial_facts.amount_original', 'financial_facts.value_kind', 'financial_periods.fiscal_year', 'financial_facts.page', 'financial_facts.excerpt']);

        return response()->json([
            'municipality' => $municipality->load('sources'),
            'years' => $years,
            'documents' => Document::query()->where('municipality_id', $municipality->id)->count(),
            'archived_documents' => Document::query()->where('municipality_id', $municipality->id)->where('status', 'archived')->count(),
            'latest_run' => $latest,
            'headline' => $facts,
            'open_questions' => Question::query()->where('municipality_id', $municipality->id)->where('status', 'open')->count(),
            'findings' => Finding::query()->where('municipality_id', $municipality->id)->count(),
        ]);
    }

    public function documents(Request $request)
    {
        $query = Document::query()->with('versions')->orderByDesc('fiscal_year');
        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->integer('municipality_id'));
        }
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->string('document_type'));
        }
        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', $request->integer('fiscal_year'));
        }
        if ($request->filled('q')) {
            $query->where('title', 'ilike', '%'.$request->string('q').'%');
        }

        return $query->paginate(50);
    }

    public function facts(Request $request)
    {
        $query = FinancialFact::query()
            ->leftJoin('financial_periods', 'financial_periods.id', '=', 'financial_facts.financial_period_id')
            ->select('financial_facts.*', 'financial_periods.fiscal_year')
            ->where('financial_facts.scope', $request->string('scope', 'bereinigt'));
        if ($request->filled('municipality_id')) {
            $query->where('financial_facts.municipality_id', $request->integer('municipality_id'));
        }
        if ($request->filled('code')) {
            $query->where('financial_facts.code', $request->string('code'));
        }
        if ($request->filled('codes')) {
            $codes = array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) $request->string('codes')),
            )));
            $query->whereIn('financial_facts.code', $codes);
        }
        if ($request->filled('value_kind')) {
            $query->where('financial_facts.value_kind', $request->string('value_kind'));
        }

        return $query->orderBy('financial_facts.code')->orderBy('financial_periods.fiscal_year')->limit(500)->get();
    }

    public function downloadDocument(Document $document)
    {
        $version = $document->versions()->orderByDesc('version_number')->first();
        abort_unless($version, 404, 'Keine archivierte Version.');
        $disk = $version->storage_disk ?: 'documents';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($version->storage_path), 404, 'Datei fehlt im Speicher.');

        return \Illuminate\Support\Facades\Storage::disk($disk)->download(
            $version->storage_path,
            Str::slug($document->title).'.pdf',
        );
    }

    public function findings(Request $request)
    {
        return Finding::query()->with('evidence')
            ->when($request->filled('municipality_id'), fn ($q) => $q->where('municipality_id', $request->integer('municipality_id')))
            ->orderBy('priority')->orderByDesc('id')->limit(200)->get();
    }

    public function reviewFinding(Request $request, Finding $finding, AuditLogger $audit)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected,needs_manual_review'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $finding->update([
            'approval_status' => $data['decision'] === 'approved' ? 'approved' : ($data['decision'] === 'rejected' ? 'rejected' : 'draft'),
            'review_status' => $data['decision'] === 'needs_manual_review' ? 'needs_manual_review' : $finding->review_status,
            'review_note' => $data['comment'] ?? $finding->review_note,
        ]);
        $audit->log('finding.reviewed', 'finding', $finding->id, ['decision' => $data['decision']]);

        return $finding->fresh();
    }

    public function questions(Request $request)
    {
        return Question::query()
            ->when($request->filled('municipality_id'), fn ($q) => $q->where('municipality_id', $request->integer('municipality_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('priority')->limit(200)->get();
    }

    public function answerQuestion(Request $request, Question $question, AuditLogger $audit)
    {
        $data = $request->validate(['answer' => ['required', 'string', 'max:5000'], 'status' => ['nullable', 'in:open,answered,closed']]);
        $question->update([
            'answer' => $data['answer'],
            'status' => $data['status'] ?? 'answered',
            'answered_at' => now(),
        ]);
        $audit->log('question.answered', 'question', $question->id, ['status' => $question->status]);

        return $question;
    }

    public function reports(Request $request)
    {
        return Report::query()
            ->when($request->filled('municipality_id'), fn ($q) => $q->where('municipality_id', $request->integer('municipality_id')))
            ->orderByDesc('id')->get(['id', 'municipality_id', 'analysis_run_id', 'report_type', 'title', 'status', 'version_number', 'data_as_of', 'period_start', 'period_end', 'created_at']);
    }

    public function showReport(Report $report)
    {
        return $report;
    }

    public function approveReport(Request $request, Report $report, AuditLogger $audit)
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'comment' => ['nullable', 'string']]);
        $report->update([
            'status' => $data['decision'],
            'approved_by' => $data['decision'] === 'approved' ? $request->user()->id : null,
            'approved_at' => $data['decision'] === 'approved' ? now() : null,
        ]);
        $audit->log('report.reviewed', 'report', $report->id, ['decision' => $data['decision']]);

        return $report;
    }

    public function exportReport(Report $report, Request $request, AnalysisClient $client)
    {
        $format = $request->string('format', 'md');
        if (! in_array($format, ['md', 'docx', 'pdf'], true)) {
            abort(422, 'Unbekanntes Format.');
        }
        if ($format === 'md') {
            return response($report->body_markdown, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        }
        $relative = 'exports/report-'.$report->id.'.'.$format;
        $absolute = \Illuminate\Support\Facades\Storage::disk('documents')->path($relative);
        $client->render($report->body_markdown, $format, $absolute);

        return response()->download($absolute);
    }

    public function analyses(Request $request)
    {
        return AnalysisRun::query()
            ->when($request->filled('municipality_id'), fn ($q) => $q->where('municipality_id', $request->integer('municipality_id')))
            ->latest()->limit(50)->get();
    }

    public function startAnalysis(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['municipality_id' => ['required', 'integer', 'exists:municipalities,id']]);
        $run = AnalysisRun::query()->create([
            'municipality_id' => $data['municipality_id'],
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'workflow_state' => 'registered',
            'trigger' => 'api',
        ]);
        RunAnalysisJob::dispatch($run->id);
        $audit->log('analysis.queued', 'analysis_run', $run->id, []);

        return response()->json($run, 202);
    }

    public function cancelAnalysis(AnalysisRun $analysis, AuditLogger $audit)
    {
        $analysis->update(['cancel_requested' => true]);
        $audit->log('analysis.cancel_requested', 'analysis_run', $analysis->id, []);

        return $analysis;
    }

    public function validations(Request $request)
    {
        return AccountingValidation::query()
            ->when($request->filled('analysis_run_id'), fn ($q) => $q->where('analysis_run_id', $request->integer('analysis_run_id')))
            ->orderBy('id')->limit(300)->get();
    }

    public function storeSource(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'name' => ['required', 'string'],
            'url' => ['required', 'url'],
            'source_type' => ['required', 'string'],
            'allowed_hosts' => ['required', 'array'],
            'max_documents' => ['nullable', 'integer', 'min:1', 'max:100'],
            'crawl_delay_seconds' => ['nullable', 'integer', 'min:0', 'max:300'],
        ]);
        $source = Source::query()->create($data);
        $audit->log('source.created', 'source', $source->id, ['url' => $source->url]);

        return response()->json($source, 201);
    }
}
