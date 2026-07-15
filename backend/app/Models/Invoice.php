<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    // FBR InvoiceType
    public const TYPE_NEW = 1;
    public const TYPE_DEBIT = 2;
    public const TYPE_CREDIT = 3;

    // FBR PaymentMode
    public const PAYMENT_CASH = 1;
    public const PAYMENT_CARD = 2;
    public const PAYMENT_GIFT_VOUCHER = 3;
    public const PAYMENT_LOYALTY_CARD = 4;
    public const PAYMENT_MIXED = 5;
    public const PAYMENT_CHEQUE = 6;

    public const FISCAL_PENDING = 'pending';
    public const FISCAL_SYNCED = 'synced';
    public const FISCAL_FAILED_PERMANENT = 'failed_permanent';

    /** Buyer identity capture becomes mandatory above this bill value (B2B threshold). */
    public const BUYER_CAPTURE_THRESHOLD = 100000;

    protected $fillable = [
        'branch_id', 'terminal_id', 'customer_id', 'usin', 'usin_type', 'invoice_type', 'ref_invoice_id',
        'fbr_invoice_number', 'buyer_ntn', 'buyer_cnic', 'buyer_name', 'buyer_phone',
        'total_sale_value', 'total_tax_charged', 'discount', 'further_tax', 'total_bill_amount',
        'payment_mode', 'payment_breakdown', 'fiscal_status', 'printed_offline_pending',
        'synced_at', 'sold_at', 'cashier_id',
    ];

    protected function casts(): array
    {
        return [
            'total_sale_value' => 'decimal:2',
            'total_tax_charged' => 'decimal:2',
            'discount' => 'decimal:2',
            'further_tax' => 'decimal:2',
            'total_bill_amount' => 'decimal:2',
            'payment_breakdown' => 'array',
            'printed_offline_pending' => 'boolean',
            'synced_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function refInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'ref_invoice_id');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Invoice::class, 'ref_invoice_id');
    }

    public function fiscalOutbox(): HasOne
    {
        return $this->hasOne(FiscalOutbox::class);
    }

    public function isCredit(): bool
    {
        return $this->invoice_type === self::TYPE_CREDIT;
    }

    /** The USIN of refInvoice, formatted the way FBR's RefUSIN field expects. */
    public function refUsinValue(): ?string
    {
        return $this->refInvoice ? (string) $this->refInvoice->usin : null;
    }
}
