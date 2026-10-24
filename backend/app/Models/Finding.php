<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Finding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'years' => 'array',
            'positions' => 'array',
            'inputs' => 'array',
            'comparison' => 'array',
            'possible_explanations' => 'array',
            'counterarguments' => 'array',
            'absolute_deviation' => 'decimal:2',
            'relative_deviation' => 'decimal:4',
        ];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(FindingEvidence::class);
    }
}
