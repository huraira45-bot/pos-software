<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'product_variant_id', 'ref_invoice_item_id',
        'item_code', 'item_name', 'pct_code', 'quantity', 'unit_price_excl_tax',
        'tax_rate', 'sale_value', 'tax_charged', 'discount', 'further_tax',
        'total_amount', 'invoice_type',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price_excl_tax' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'sale_value' => 'decimal:2',
            'tax_charged' => 'decimal:2',
            'discount' => 'decimal:2',
            'further_tax' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function refItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'ref_invoice_item_id');
    }
}
