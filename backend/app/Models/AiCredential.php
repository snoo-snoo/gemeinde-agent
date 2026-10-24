<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiCredential extends Model
{
    use SoftDeletes;

    protected $hidden = ['secret'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_tested_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
