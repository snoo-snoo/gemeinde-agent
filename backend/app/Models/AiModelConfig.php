<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelConfig extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'supports_json_mode' => 'boolean',
            'supports_tools' => 'boolean',
            'enabled' => 'boolean',
            'temperature' => 'decimal:2',
            'price_input_per_million' => 'decimal:4',
            'price_output_per_million' => 'decimal:4',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
