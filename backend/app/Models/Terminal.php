<?php

namespace App\Models;

use App\Observers\TerminalObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy(TerminalObserver::class)]
class Terminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'code', 'name', 'fbr_pos_id',
        'fiscal_mode', 'fiscal_endpoint_override', 'fiscal_token',
        'is_active', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'fiscal_token' => 'encrypted',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function usinCounter(): HasOne
    {
        return $this->hasOne(UsinCounter::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Resolved fiscalization mode: terminal override, else global config default. */
    public function effectiveFiscalMode(): string
    {
        return $this->fiscal_mode ?: config('fiscal.mode');
    }

    /** Resolved bearer token: terminal-specific, else the global fallback token. */
    public function effectiveFiscalToken(): ?string
    {
        return $this->fiscal_token ?: config('fiscal.default_token');
    }
}
