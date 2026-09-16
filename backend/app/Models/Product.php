<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'product_category_id', 'name', 'sku', 'barcode',
        'sale_price', 'tax_rate', 'preparation_station', 'tracks_stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tracks_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }
}
