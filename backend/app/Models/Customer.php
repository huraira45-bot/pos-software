<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    public const TYPE_WALK_IN = 'walk_in';
    public const TYPE_B2B = 'b2b';

    protected $fillable = [
        'name', 'company_name', 'contact_person', 'phone', 'email', 'ntn', 'cnic', 'strn',
        'address', 'billing_address', 'shipping_address', 'customer_type',
        'payment_terms_days', 'credit_limit', 'opening_balance',
        'price_level', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Accepts "1234567-8"-style input; stores digits only. */
    public function setNtnAttribute(?string $value): void
    {
        $this->attributes['ntn'] = $value ? preg_replace('/\D/', '', $value) : null;
    }

    /** Accepts "12345-1234567-1"-style input; stores digits only. */
    public function setCnicAttribute(?string $value): void
    {
        $this->attributes['cnic'] = $value ? preg_replace('/\D/', '', $value) : null;
    }

    public function formattedNtn(): ?string
    {
        return $this->ntn ? substr($this->ntn, 0, 7) : null;
    }

    public function formattedCnic(): ?string
    {
        if (! $this->cnic) {
            return null;
        }

        return substr($this->cnic, 0, 5) . '-' . substr($this->cnic, 5, 7) . '-' . substr($this->cnic, 12, 1);
    }

    public function isB2b(): bool
    {
        return $this->customer_type === self::TYPE_B2B;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
