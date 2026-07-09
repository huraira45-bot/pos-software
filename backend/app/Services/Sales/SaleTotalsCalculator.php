<?php

namespace App\Services\Sales;

use App\Exceptions\Sales\InvalidCartException;
use App\Services\Fiscal\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pure computation: turns a list of cart lines (+ an optional whole-bill discount)
 * into exact, FBR-ready per-line and header totals. No I/O, no Eloquent - kept
 * this way specifically so tax/discount/rounding rules are unit-testable without
 * a database.
 *
 * Because header totals are literally the sum of the computed lines (never a
 * separately-entered figure), FbrInvoicePayloadBuilder's reconciliation check
 * passes by construction - there is no code path that could produce a mismatch.
 *
 * Tax model: tax is charged on (line sale value - line discount), i.e. on the
 * taxable value after discount, which is standard Pakistani sales tax practice.
 * A whole-bill discount is allocated pro-rata across lines by sale-value share,
 * with any rounding remainder assigned to the last line, because FBR's Items
 * array has no separate "bill discount" field - only per-item Discount, which
 * must sum to the header Discount.
 */
class SaleTotalsCalculator
{
    /**
     * @param list<array{
     *   item_code:string, item_name:string, pct_code:string,
     *   tax_rate:string, unit_price_excl_tax:string, quantity:string,
     *   line_discount?:string, further_tax?:string,
     * }> $lines
     */
    public function calculate(array $lines, string $billDiscount = '0'): array
    {
        if (empty($lines)) {
            throw new InvalidCartException('Cannot calculate totals for an empty cart.');
        }

        $billDiscountBd = Money::of($billDiscount);
        if ($billDiscountBd->isNegative()) {
            throw new InvalidCartException('Bill discount cannot be negative.');
        }

        $sumSaleValue = BigDecimal::zero();
        $rawLines = [];
        foreach ($lines as $i => $line) {
            $quantity = BigDecimal::of($line['quantity']);
            if ($quantity->isLessThanOrEqualTo(0)) {
                throw new InvalidCartException("Line {$i}: quantity must be positive.");
            }

            $saleValue = Money::of($line['unit_price_excl_tax'])
                ->multipliedBy($quantity)
                ->toScale(2, RoundingMode::HALF_UP);

            $rawLines[$i] = $line;
            $rawLines[$i]['sale_value'] = $saleValue;
            $sumSaleValue = $sumSaleValue->plus($saleValue);
        }

        if ($billDiscountBd->isGreaterThan($sumSaleValue)) {
            throw new InvalidCartException('Bill discount cannot exceed total sale value.');
        }

        $keys = array_keys($rawLines);
        $lastKey = end($keys);
        $allocatedSoFar = BigDecimal::zero();

        $computedLines = [];
        $totalSaleValue = BigDecimal::zero();
        $totalTaxCharged = BigDecimal::zero();
        $totalDiscount = BigDecimal::zero();
        $totalFurtherTax = BigDecimal::zero();
        $totalAmount = BigDecimal::zero();

        foreach ($rawLines as $i => $line) {
            $saleValue = $line['sale_value'];
            $lineDiscount = Money::of($line['line_discount'] ?? '0');

            if ($billDiscountBd->isGreaterThan(BigDecimal::zero())) {
                if ($i === $lastKey) {
                    $billShare = $billDiscountBd->minus($allocatedSoFar);
                } else {
                    $billShare = $billDiscountBd->multipliedBy($saleValue)
                        ->dividedBy($sumSaleValue, 2, RoundingMode::HALF_UP);
                    $allocatedSoFar = $allocatedSoFar->plus($billShare);
                }
            } else {
                $billShare = BigDecimal::zero();
            }

            $lineTotalDiscount = $lineDiscount->plus($billShare)->toScale(2, RoundingMode::HALF_UP);

            if ($lineTotalDiscount->isGreaterThan($saleValue)) {
                throw new InvalidCartException("Line {$i}: discount cannot exceed sale value.");
            }

            $taxableValue = $saleValue->minus($lineTotalDiscount);
            $taxRate = Money::of($line['tax_rate']);
            $taxCharged = $taxableValue->multipliedBy($taxRate)
                ->dividedBy(100, 2, RoundingMode::HALF_UP);
            $furtherTax = Money::of($line['further_tax'] ?? '0');
            $lineTotal = $taxableValue->plus($taxCharged)->plus($furtherTax);

            $computedLines[$i] = [
                'item_code' => $line['item_code'],
                'item_name' => $line['item_name'],
                'pct_code' => $line['pct_code'],
                'quantity' => $line['quantity'],
                'unit_price_excl_tax' => Money::toDecimalString($line['unit_price_excl_tax']),
                'tax_rate' => Money::toDecimalString($taxRate),
                'sale_value' => Money::toDecimalString($saleValue),
                'discount' => Money::toDecimalString($lineTotalDiscount),
                'tax_charged' => Money::toDecimalString($taxCharged),
                'further_tax' => Money::toDecimalString($furtherTax),
                'total_amount' => Money::toDecimalString($lineTotal),
            ];

            $totalSaleValue = $totalSaleValue->plus($saleValue);
            $totalTaxCharged = $totalTaxCharged->plus($taxCharged);
            $totalDiscount = $totalDiscount->plus($lineTotalDiscount);
            $totalFurtherTax = $totalFurtherTax->plus($furtherTax);
            $totalAmount = $totalAmount->plus($lineTotal);
        }

        return [
            'lines' => array_values($computedLines),
            'header' => [
                'total_sale_value' => Money::toDecimalString($totalSaleValue),
                'total_tax_charged' => Money::toDecimalString($totalTaxCharged),
                'discount' => Money::toDecimalString($totalDiscount),
                'further_tax' => Money::toDecimalString($totalFurtherTax),
                'total_bill_amount' => Money::toDecimalString($totalAmount),
            ],
        ];
    }
}
