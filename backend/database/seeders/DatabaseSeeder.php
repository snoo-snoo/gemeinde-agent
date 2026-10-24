<?php

namespace Database\Seeders;

use App\Models\AgentConfig;
use App\Models\AiProvider;
use App\Models\Municipality;
use App\Models\ScrapeSchedule;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@kommunalaudit.local')],
            [
                'name' => 'Verwaltung',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'change-me-admin')),
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        $municipality = Municipality::query()->updateOrCreate(
            ['slug' => 'aigen-schlaegl'],
            [
                'name' => 'Aigen-Schlägl',
                'official_name' => 'Marktgemeinde Aigen-Schlägl',
                'bundesland' => 'Oberösterreich',
                'district' => 'Rohrbach',
                'postal_code' => '4160',
                'city' => 'Aigen-Schlägl',
                'website_url' => 'https://www.aigen-schlaegl.at',
                'notes' => 'Pilotgemeinde. Finanzunterlagen unter /Verwaltung/Finanzen. robots.txt verlangt Crawl-delay 20.',
                'active' => true,
            ],
        );

        $source = Source::query()->updateOrCreate(
            ['municipality_id' => $municipality->id, 'url' => 'https://www.aigen-schlaegl.at/Verwaltung/Finanzen'],
            [
                'name' => 'Finanzseite',
                'source_type' => 'finance_page',
                'allowed_hosts' => ['www.aigen-schlaegl.at'],
                'respect_robots' => true,
                'max_documents' => (int) env('KOMMUNAL_MAX_DOCUMENTS', 2),
                'active' => true,
                'rules' => ['document_types' => ['rechnungsabschluss', 'voranschlag', 'nachtragsvoranschlag', 'finanzplan']],
            ],
        );

        ScrapeSchedule::query()->updateOrCreate(
            ['municipality_id' => $municipality->id, 'source_id' => $source->id],
            ['cron' => '0 4 * * 1', 'enabled' => false],
        );

        $drivers = [
            ['name' => 'OpenAI', 'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1'],
            ['name' => 'Anthropic', 'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1'],
            ['name' => 'Google Gemini', 'driver' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
            ['name' => 'xAI', 'driver' => 'xai', 'base_url' => 'https://api.x.ai/v1'],
            ['name' => 'OpenRouter', 'driver' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1'],
        ];
        foreach ($drivers as $driver) {
            AiProvider::query()->updateOrCreate(
                ['driver' => $driver['driver'], 'name' => $driver['name']],
                ['base_url' => $driver['base_url'], 'enabled' => false, 'is_fallback' => false],
            );
        }

        $catalog = json_decode(file_get_contents(dirname(base_path()).'/shared/agent-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($catalog as $agent) {
            AgentConfig::query()->updateOrCreate(
                ['agent_key' => $agent['key']],
                [
                    'name' => $agent['name'],
                    'task' => $agent['task'],
                    'system_prompt' => $agent['system_prompt'],
                    'prompt_version' => $agent['prompt_version'],
                    'timeout_seconds' => $agent['timeout_seconds'],
                    'max_retries' => $agent['max_retries'],
                    'enabled' => true,
                    'implementation' => $agent['implementation'],
                    'allows_llm' => $agent['allows_llm'],
                    'dependencies' => $agent['dependencies'],
                    'quality_rules' => ['documents_are_untrusted' => true, 'no_allegation_of_misconduct' => true],
                ],
            );
        }
    }
}
