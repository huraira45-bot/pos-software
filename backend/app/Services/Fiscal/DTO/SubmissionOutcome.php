<?php

namespace App\Services\Fiscal\DTO;

final class SubmissionOutcome
{
    private function __construct(
        public readonly string $status, // synced | already_synced | retry | failed_permanent
        public readonly int $retryDelaySeconds = 0,
    ) {
    }

    public static function synced(): self
    {
        return new self('synced');
    }

    public static function alreadySynced(): self
    {
        return new self('already_synced');
    }

    public static function retry(int $delaySeconds): self
    {
        return new self('retry', $delaySeconds);
    }

    public static function failedPermanent(): self
    {
        return new self('failed_permanent');
    }

    public function shouldRetry(): bool
    {
        return $this->status === 'retry';
    }
}
