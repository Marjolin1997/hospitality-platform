<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency', 'quote_currency', 'rate', 'source', 'effective_at', 'fetched_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'effective_at' => 'datetime',
            'fetched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
