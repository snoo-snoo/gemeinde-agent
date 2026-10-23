<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Source extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed_hosts' => 'array',
            'rules' => 'array',
            'respect_robots' => 'boolean',
            'active' => 'boolean',
            'last_crawled_at' => 'datetime',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
