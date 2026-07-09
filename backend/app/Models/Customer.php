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

    public const ATL_ACTIVE = 'active';
    public const ATL_INACTIVE = 'inactive';
    public const ATL_UNKNOWN = 'unknown';

    protected $fillable = [
        'name', 'phone', 'ntn', 'cnic', 'address',
        'customer_type', 'atl_status', 'atl_checked_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'atl_checked_at' => 'datetime',
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

    public function isAtlActive(): bool
    {
        return $this->atl_status === self::ATL_ACTIVE;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
