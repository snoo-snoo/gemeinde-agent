<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialFact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confidence' => 'decimal:4',
            'quality_flags' => 'array',
            'comparable' => 'boolean',
        ];
    }
}
