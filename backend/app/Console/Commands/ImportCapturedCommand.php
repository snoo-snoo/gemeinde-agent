<?php

namespace App\Console\Commands;

use App\Models\AnalysisRun;
use App\Models\Municipality;
use App\Services\Analysis\Pipeline;
use Illuminate\Console\Command;

class ImportCapturedCommand extends Command
{
    protected $signature = 'kommunal:import-captured {slug} {manifest : JSON-Datei mit path, url, title, document_type und fiscal_year} {--gap=* : Hinweis, der als Datenlücke im Bericht steht}';

    protected $description = 'Verarbeitet bereits abgerufene öffentliche PDFs und kennzeichnet die Herkunft';

    public function handle(Pipeline $pipeline): int
    {
        $municipality = Municipality::query()->where('slug', $this->argument('slug'))->firstOrFail();
        $manifest = json_decode((string) file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || $manifest === []) {
            $this->error('Das Manifest ist leer.');

            return self::FAILURE;
        }
        $run = AnalysisRun::query()->create([
            'municipality_id' => $municipality->id,
            'status' => 'pending',
            'workflow_state' => 'registered',
            'trigger' => 'captured_import',
        ]);
        $pipeline->importCaptured($run, $manifest, $this->option('gap'));
        $fresh = $run->fresh();
        $this->info('Lauf '.$run->id.' abgeschlossen mit Status '.$fresh->status);

        return $fresh->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
