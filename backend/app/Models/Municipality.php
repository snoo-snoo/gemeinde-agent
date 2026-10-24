<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipality extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }
}
