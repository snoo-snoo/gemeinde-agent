<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_fallback' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AiCredential::class, 'provider_id');
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModelConfig::class, 'provider_id');
    }
}
