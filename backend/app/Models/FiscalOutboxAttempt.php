<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalOutboxAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fiscal_outbox_id', 'attempt_no', 'adapter', 'request_payload',
        'response_status_code', 'response_payload', 'error_message', 'duration_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(FiscalOutbox::class, 'fiscal_outbox_id');
    }
}
