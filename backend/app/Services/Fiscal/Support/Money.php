<?php

namespace App\Services\Fiscal\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * All monetary computation in this codebase uses Brick\Math\BigDecimal (exact
 * decimal arithmetic, no float drift) and Eloquent decimal:2 casts for storage.
 * FBR's PostData contract requires JSON numbers ("double") for amounts, so this
 * class is the single point where an exact decimal is converted to a float - only
 * ever at the outbound API boundary, never for internal computation or comparison.
 */
final class Money
{
    public static function of(string|int|float $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(2);
    }

    /** Convert an exact decimal string/BigDecimal to the float FBR's JSON payload expects. */
    public static function toApiDouble(string|BigDecimal $value): float
    {
        $decimal = $value instanceof BigDecimal ? $value : self::of($value);

        return (float) $decimal->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    public static function toDecimalString(string|BigDecimal $value): string
    {
        $decimal = $value instanceof BigDecimal ? $value : self::of($value);

        return $decimal->toScale(2, RoundingMode::HALF_UP)->__toString();
    }
}
