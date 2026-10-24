<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cost_estimated' => 'boolean',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(AgentConfig::class, 'agent_config_id');
    }
}
