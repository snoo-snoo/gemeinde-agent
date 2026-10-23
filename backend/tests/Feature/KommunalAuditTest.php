<?php

namespace Tests\Feature;

use App\Models\AgentConfig;
use App\Models\AiCredential;
use App\Models\AiProvider;
use App\Models\AnalysisRun;
use App\Models\Municipality;
use App\Models\Source;
use App\Models\User;
use App\Services\Ai\LlmGateway;
use App\Services\Analysis\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KommunalAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_store_a_provider_and_secrets_stay_server_side(): void
    {
        $this->seed();
        $viewer = User::factory()->create(['role' => 'viewer', 'password' => Hash::make('viewer-password-1')]);
        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/providers', [
            'name' => 'Lokal',
            'driver' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:9/v1',
        ])->assertForbidden();

        Auth::forgetGuards();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $adminToken = $admin->createToken('admin')->plainTextToken;
        $created = $this->withToken($adminToken)->postJson('/api/providers', [
            'name' => 'Lokal',
            'driver' => 'openai_compatible',
            'base_url' => 'https://llm.example/v1',
        ])->assertCreated()->json();

        $stored = $this->withToken($adminToken)->postJson('/api/providers/'.$created['id'].'/credentials', [
            'label' => 'Hauptkey',
            'secret' => 'sk-test-secret-value',
        ])->assertCreated()->json();

        $this->assertSame('••••alue', $stored['hint']);
        $this->assertArrayNotHasKey('secret', $stored);
        $raw = AiCredential::query()->findOrFail($stored['id'])->getRawOriginal('secret');
        $this->assertStringNotContainsString('sk-test-secret-value', $raw);

        Http::fake(['https://llm.example/v1/models' => Http::response(['data' => [['id' => 'demo-model']]])]);
        $this->withToken($adminToken)->postJson('/api/credentials/'.$stored['id'].'/test')
            ->assertOk()
            ->assertJsonPath('model_count', 1);
    }

    public function test_pipeline_persists_real_shaped_facts_and_two_agent_runs(): void
    {
        $this->seed();
        $municipality = Municipality::query()->where('slug', 'aigen-schlaegl')->firstOrFail();
        Source::query()->where('municipality_id', $municipality->id)->update(['max_documents' => 1]);

        Http::fake(function ($request) {
            $url = $request->url();
            $payload = [
                'source_discovery' => ['documents' => [[
                    'title' => 'Rechnungsabschluss 2024',
                    'url' => 'https://www.aigen-schlaegl.at/system/web/getDocument.ashx?fileid=4017641',
                    'document_type' => 'rechnungsabschluss',
                    'fiscal_year' => 2024,
                    'relevant' => true,
                ]], 'robots' => ['crawl_delay' => 0, 'disallow' => []], 'data_gaps' => []],
                'scraper_acquisition' => ['sha256' => str_repeat('a', 64), 'byte_size' => 10, 'content_type' => 'application/pdf', 'status_code' => 200],
                'financial_extraction' => ['facts' => [[
                    'local_id' => 'doc-1:1',
                    'code' => 'SA0',
                    'original_label' => 'Nettoergebnis',
                    'statement' => 'ergebnis',
                    'scope' => 'bereinigt',
                    'fiscal_year' => 2024,
                    'value_kind' => 'ist',
                    'nature' => 'saldo',
                    'amount' => '60736.62',
                    'amount_original' => '60.736,62',
                    'currency' => 'EUR',
                    'page' => 66,
                    'excerpt' => 'SA0 Nettoergebnis 60.736,62',
                    'extraction_method' => 'vrv2015-layout-v1',
                    'confidence' => 0.95,
                    'quality_flags' => [],
                    'source_url' => 'https://www.aigen-schlaegl.at/system/web/getDocument.ashx?fileid=4017641',
                ]], 'statements' => [], 'method' => 'pdftotext-layout', 'fact_count' => 1, 'warnings' => [], 'data_gaps' => []],
                'accounting_validation' => ['validations' => [[
                    'rule_code' => 'plan_actual',
                    'status' => 'passed',
                    'formula' => 'ist - plan',
                    'inputs' => ['ist' => '60736.62'],
                    'result' => [],
                    'tolerance' => '0.02',
                    'message' => 'geprüft',
                ]]],
                'historical_comparison' => ['comparisons' => [], 'investments' => []],
                'anomaly_detection' => ['findings' => []],
                'evidence_review' => ['findings' => []],
                'opposition_accountability' => ['questions' => []],
                'reporting' => ['reports' => [
                    ['report_type' => 'citizen', 'title' => 'Bürgerbericht', 'markdown' => '# Bürgerbericht\n60.736,62 EUR', 'limitations' => 'Entwurf', 'sources' => [], 'period_start' => 2024, 'period_end' => 2024],
                    ['report_type' => 'executive_summary', 'title' => 'Executive Summary', 'markdown' => '# Executive', 'limitations' => 'Entwurf', 'sources' => [], 'period_start' => 2024, 'period_end' => 2024],
                ]],
            ];
            foreach ($payload as $key => $output) {
                if (str_contains($url, '/internal/agents/'.$key)) {
                    return Http::response(['agent_key' => $key, 'status' => 'completed', 'output' => $output]);
                }
            }

            return Http::response(['agent_key' => 'other', 'status' => 'completed', 'output' => []]);
        });

        $run = AnalysisRun::query()->create([
            'municipality_id' => $municipality->id,
            'status' => 'pending',
            'workflow_state' => 'registered',
            'trigger' => 'test',
        ]);
        app(Pipeline::class)->handle($run->fresh());
        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('financial_facts', ['amount_original' => '60.736,62', 'code' => 'SA0']);
        $this->assertDatabaseHas('documents', ['title' => 'Rechnungsabschluss 2024', 'status' => 'archived']);
        $this->assertGreaterThanOrEqual(2, $run->agentRuns()->where('status', 'completed')->count());
        $this->assertDatabaseCount('reports', 2);
    }

    public function test_llm_narration_rejects_unknown_amounts_without_calling_them_verified(): void
    {
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI',
            'driver' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'enabled' => true,
        ]);
        $credential = $provider->credentials()->create([
            'label' => 'key',
            'secret' => 'sk-live-looking-key',
            'hint' => '••••-key',
            'status' => 'active',
        ]);
        $model = $provider->models()->create([
            'model_id' => 'gpt-test',
            'display_name' => 'gpt-test',
            'supports_json_mode' => true,
            'price_source' => 'unknown',
            'enabled' => true,
        ]);
        $agent = AgentConfig::query()->create([
            'agent_key' => 'citizen',
            'name' => 'Bürgerbericht',
            'task' => 'erklären',
            'provider_id' => $provider->id,
            'model_config_id' => $model->id,
            'credential_id' => $credential->id,
            'system_prompt' => 'Nur vorhandene Beträge.',
            'prompt_version' => 1,
            'timeout_seconds' => 30,
            'max_retries' => 1,
            'enabled' => true,
            'implementation' => 'deterministic',
            'allows_llm' => true,
        ]);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"narrative":"Der Fehlbetrag beträgt 999.999,99 EUR."}']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
            ]),
        ]);

        $result = app(LlmGateway::class)->narrate($agent->fresh(['provider', 'credential', 'modelConfig']), 'Nettoergebnis 60.736,62 EUR', ['60.736,62']);
        $this->assertSame('rejected', $result['status']);
        $this->assertStringContainsString('60.736,62', $result['text']);
        $this->assertStringNotContainsString('999.999,99', $result['text']);
    }
}
