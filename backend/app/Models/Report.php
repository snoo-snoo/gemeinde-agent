<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_as_of' => 'date',
            'approved_at' => 'datetime',
            'source_index' => 'array',
            'exports' => 'array',
        ];
    }
}
