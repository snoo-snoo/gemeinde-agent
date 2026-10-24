<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapeSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_ran_at' => 'datetime',
        ];
    }
}
