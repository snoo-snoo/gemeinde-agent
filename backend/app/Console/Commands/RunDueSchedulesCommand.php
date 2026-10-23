<?php

namespace App\Console\Commands;

use App\Jobs\RunAnalysisJob;
use App\Models\AnalysisRun;
use App\Models\ScrapeSchedule;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RunDueSchedulesCommand extends Command
{
    protected $signature = 'kommunal:schedule-due';

    protected $description = 'Stellt fällige, aktivierte Gemeindeabrufe in die Queue';

    public function handle(): int
    {
        $started = 0;
        foreach (ScrapeSchedule::query()->where('enabled', true)->get() as $schedule) {
            if (! CronExpression::isValidExpression($schedule->cron)) {
                $this->warn('Ungültiger Cron-Ausdruck für Zeitplan '.$schedule->id);

                continue;
            }
            $cron = new CronExpression($schedule->cron);
            if (! $cron->isDue()) {
                continue;
            }
            if ($schedule->last_ran_at && $schedule->last_ran_at->greaterThan(now()->subSeconds(50))) {
                continue;
            }
            $run = AnalysisRun::query()->create([
                'municipality_id' => $schedule->municipality_id,
                'status' => 'pending',
                'workflow_state' => 'registered',
                'trigger' => 'schedule',
            ]);
            RunAnalysisJob::dispatch($run->id);
            $schedule->update(['last_ran_at' => now()]);
            $started++;
        }
        $this->info('Gestartet: '.$started);

        return self::SUCCESS;
    }
}
