<?php

namespace App\Jobs;

use App\Models\AnalysisRun;
use App\Services\Analysis\Pipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public int $analysisRunId)
    {
    }

    public function handle(Pipeline $pipeline): void
    {
        $run = AnalysisRun::query()->findOrFail($this->analysisRunId);
        if ($run->status === 'completed') {
            return;
        }
        $pipeline->handle($run);
    }
}
