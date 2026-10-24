<?php

namespace App\Console\Commands;

use App\Jobs\RunAnalysisJob;
use App\Models\AnalysisRun;
use App\Models\Municipality;
use App\Services\Analysis\Pipeline;
use Illuminate\Console\Command;

class RunAnalysisCommand extends Command
{
    protected $signature = 'kommunal:run {slug} {--sync : Führt den Lauf im Vordergrund aus}';

    protected $description = 'Startet die Prüfung einer Gemeinde';

    public function handle(Pipeline $pipeline): int
    {
        $municipality = Municipality::query()->where('slug', $this->argument('slug'))->firstOrFail();
        $run = AnalysisRun::query()->create([
            'municipality_id' => $municipality->id,
            'status' => 'pending',
            'workflow_state' => 'registered',
            'trigger' => 'command',
        ]);
        if ($this->option('sync')) {
            $pipeline->handle($run);
            $this->info('Lauf '.$run->id.' abgeschlossen mit Status '.$run->fresh()->status);

            return self::SUCCESS;
        }
        RunAnalysisJob::dispatch($run->id);
        $this->info('Lauf '.$run->id.' in die Queue gestellt.');

        return self::SUCCESS;
    }
}
