<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'name', 'sku', 'barcode', 'price_excl_tax', 'is_active'];

    protected function casts(): array
    {
        return [
            'price_excl_tax' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function effectivePriceExclTax(): string
    {
        return $this->price_excl_tax ?? $this->product->price_excl_tax;
    }
}
