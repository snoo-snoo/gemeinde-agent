<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiCredential;
use App\Models\AiModelConfig;
use App\Models\AiProvider;
use App\Models\ScrapeSchedule;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Ai\LlmGateway;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function providers()
    {
        return AiProvider::query()->with(['credentials' => function ($query) {
            $query->select('id', 'provider_id', 'label', 'hint', 'status', 'last_tested_at', 'last_test_status', 'rotated_at');
        }, 'models'])->orderBy('name')->get();
    }

    public function storeProvider(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', Rule::in(['openai', 'anthropic', 'gemini', 'xai', 'openrouter', 'openai_compatible'])],
            'base_url' => ['nullable', 'url'],
            'enabled' => ['boolean'],
            'is_fallback' => ['boolean'],
            'requests_per_minute' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);
        if ($data['driver'] === 'openai_compatible' && empty($data['base_url'])) {
            abort(422, 'Ein kompatibler Endpunkt braucht eine Basis-URL.');
        }
        $provider = AiProvider::query()->create($data);
        $audit->log('provider.created', 'ai_provider', $provider->id, ['driver' => $provider->driver]);

        return response()->json($provider, 201);
    }

    public function updateProvider(Request $request, AiProvider $provider, AuditLogger $audit)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'base_url' => ['nullable', 'url'],
            'enabled' => ['boolean'],
            'is_fallback' => ['boolean'],
            'requests_per_minute' => ['nullable', 'integer', 'min:1'],
        ]);
        if (($data['is_fallback'] ?? false) === true) {
            AiProvider::query()->where('id', '!=', $provider->id)->update(['is_fallback' => false]);
        }
        $provider->update($data);
        $audit->log('provider.updated', 'ai_provider', $provider->id, $data);

        return $provider->fresh();
    }

    public function destroyProvider(AiProvider $provider, AuditLogger $audit)
    {
        $audit->log('provider.deleted', 'ai_provider', $provider->id, ['name' => $provider->name]);
        $provider->delete();

        return response()->json(['ok' => true]);
    }

    public function storeCredential(Request $request, AiProvider $provider, AuditLogger $audit)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'secret' => ['required', 'string', 'min:8', 'max:4000'],
        ]);
        $hint = '••••'.substr($data['secret'], -4);
        $credential = $provider->credentials()->create([
            'label' => $data['label'],
            'secret' => $data['secret'],
            'hint' => $hint,
            'status' => 'active',
        ]);
        $audit->log('credential.stored', 'ai_credential', $credential->id, ['provider_id' => $provider->id, 'hint' => $hint]);

        return response()->json(['id' => $credential->id, 'label' => $credential->label, 'hint' => $credential->hint, 'status' => $credential->status], 201);
    }

    public function destroyCredential(AiCredential $credential, AuditLogger $audit)
    {
        $audit->log('credential.deleted', 'ai_credential', $credential->id, ['hint' => $credential->hint]);
        $credential->delete();

        return response()->json(['ok' => true]);
    }

    public function testCredential(AiCredential $credential, LlmGateway $gateway, AuditLogger $audit)
    {
        try {
            $result = $gateway->testConnection($credential);
            $credential->update(['last_tested_at' => now(), 'last_test_status' => 'ok']);
            $audit->log('credential.tested', 'ai_credential', $credential->id, ['model_count' => $result['model_count']]);

            return $result;
        } catch (\Throwable $exception) {
            $credential->update(['last_tested_at' => now(), 'last_test_status' => 'failed']);
            $audit->log('credential.test_failed', 'ai_credential', $credential->id, ['error' => $exception->getMessage()]);

            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function storeModel(Request $request, AiProvider $provider)
    {
        $data = $request->validate([
            'model_id' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'supports_json_mode' => ['boolean'],
            'supports_tools' => ['boolean'],
            'price_input_per_million' => ['nullable', 'numeric', 'min:0'],
            'price_output_per_million' => ['nullable', 'numeric', 'min:0'],
            'price_source' => ['nullable', Rule::in(['manual', 'provider', 'unknown'])],
            'enabled' => ['boolean'],
        ]);
        $data['price_source'] = $data['price_source'] ?? 'manual';

        return response()->json(AiModelConfig::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'model_id' => $data['model_id']],
            $data,
        ), 201);
    }

    public function agents()
    {
        return AgentConfig::query()->with(['provider:id,name,driver', 'modelConfig:id,model_id,display_name'])->orderBy('agent_key')->get();
    }

    public function updateAgent(Request $request, AgentConfig $agent, AuditLogger $audit)
    {
        $data = $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'model_config_id' => ['nullable', 'integer', 'exists:ai_model_configs,id'],
            'credential_id' => ['nullable', 'integer', 'exists:ai_credentials,id'],
            'fallback_provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
            'system_prompt' => ['sometimes', 'string'],
            'enabled' => ['boolean'],
            'token_budget' => ['nullable', 'integer', 'min:1'],
            'cost_budget_cents' => ['nullable', 'integer', 'min:0'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'parameters' => ['nullable', 'array'],
        ]);
        if (array_key_exists('system_prompt', $data) && $data['system_prompt'] !== $agent->system_prompt) {
            $data['prompt_version'] = $agent->prompt_version + 1;
        }
        $agent->update($data);
        $audit->log('agent.updated', 'agent_config', $agent->id, ['agent_key' => $agent->agent_key, 'prompt_version' => $agent->prompt_version]);

        return $agent->fresh();
    }

    public function agentRuns()
    {
        return AgentRun::query()->with('config:id,agent_key,name')->latest()->limit(100)->get([
            'id', 'analysis_run_id', 'agent_config_id', 'status', 'attempt', 'error',
            'started_at', 'finished_at', 'input_tokens', 'output_tokens', 'cost_cents',
            'cost_estimated', 'provider_driver', 'model', 'latency_ms', 'created_at',
        ]);
    }

    public function usage()
    {
        return UsageRecord::query()->latest()->limit(100)->get();
    }

    public function users()
    {
        return User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']);
    }

    public function storeUser(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'role' => ['required', Rule::in(['admin', 'analyst', 'reviewer', 'viewer'])],
        ]);
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);
        $audit->log('user.created', 'user', $user->id, ['role' => $user->role]);

        return response()->json(AuthController::userPayload($user), 201);
    }

    public function schedules()
    {
        return ScrapeSchedule::query()->get();
    }

    public function updateSchedule(Request $request, ScrapeSchedule $schedule, AuditLogger $audit)
    {
        $data = $request->validate([
            'cron' => ['sometimes', 'string', 'max:64'],
            'enabled' => ['boolean'],
        ]);
        $schedule->update($data);
        $audit->log('schedule.updated', 'scrape_schedule', $schedule->id, $data);

        return $schedule;
    }

    public function narrate(Request $request, AgentConfig $agent, LlmGateway $gateway)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'allowed_amounts' => ['array'],
            'allowed_amounts.*' => ['string'],
        ]);
        $result = $gateway->narrate($agent, $data['text'], $data['allowed_amounts'] ?? []);
        unset($result['secret']);

        return $result;
    }
}
