<?php

namespace App\Events;

use App\Models\Terminal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a terminal's oldest pending FBR submission crosses
 * fiscal.pending_alert_threshold_minutes. Attach a listener (Notification to the
 * compliance officer via mail/Slack/SMS) per deployment - kept as a plain event so
 * the detection logic (CheckPendingAgeAlertCommand) never needs to know how any
 * given deployment wants to be notified.
 */
class FiscalOutageThresholdExceeded
{
    use Dispatchable;

    public function __construct(
        public readonly Terminal $terminal,
        public readonly int $ageMinutes,
        public readonly int $pendingCount,
    ) {
    }
}
