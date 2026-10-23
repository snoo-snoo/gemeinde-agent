<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingValidation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'result' => 'array',
        ];
    }
}
