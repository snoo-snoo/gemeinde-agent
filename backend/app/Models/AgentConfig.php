<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConfig extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'output_schema' => 'array',
            'dependencies' => 'array',
            'quality_rules' => 'array',
            'enabled' => 'boolean',
            'allows_llm' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function modelConfig(): BelongsTo
    {
        return $this->belongsTo(AiModelConfig::class, 'model_config_id');
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiCredential::class, 'credential_id');
    }
}
