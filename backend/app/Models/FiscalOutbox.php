<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalOutbox extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    protected $table = 'fiscal_outbox';

    protected $fillable = [
        'invoice_id', 'idempotency_key', 'status', 'attempts',
        'next_attempt_at', 'last_error', 'locked_by', 'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function attemptLogs(): HasMany
    {
        return $this->hasMany(FiscalOutboxAttempt::class);
    }
}
