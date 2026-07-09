<?php

namespace Tests\Unit\Sales;

use App\Exceptions\Sales\InvalidCartException;
use App\Services\Sales\SaleTotalsCalculator;
use PHPUnit\Framework\TestCase;

class SaleTotalsCalculatorTest extends TestCase
{
    private function line(array $overrides = []): array
    {
        return array_merge([
            'item_code' => 'SKU-1',
            'item_name' => 'Widget',
            'pct_code' => '1234.5678',
            'tax_rate' => '18.00',
            'unit_price_excl_tax' => '100.00',
            'quantity' => '1',
        ], $overrides);
    }

    public function test_single_line_no_discount(): void
    {
        $result = (new SaleTotalsCalculator())->calculate([$this->line(['quantity' => '2'])]);

        $this->assertSame('200.00', $result['header']['total_sale_value']);
        $this->assertSame('36.00', $result['header']['total_tax_charged']); // 200 * 18%
        $this->assertSame('0.00', $result['header']['discount']);
        $this->assertSame('236.00', $result['header']['total_bill_amount']);

        $this->assertSame('200.00', $result['lines'][0]['sale_value']);
        $this->assertSame('36.00', $result['lines'][0]['tax_charged']);
        $this->assertSame('236.00', $result['lines'][0]['total_amount']);
    }

    public function test_line_discount_reduces_taxable_value(): void
    {
        $result = (new SaleTotalsCalculator())->calculate([
            $this->line(['quantity' => '1', 'line_discount' => '10.00']),
        ]);

        // taxable = 100 - 10 = 90; tax = 90 * 18% = 16.20
        $this->assertSame('10.00', $result['lines'][0]['discount']);
        $this->assertSame('16.20', $result['lines'][0]['tax_charged']);
        $this->assertSame('106.20', $result['lines'][0]['total_amount']);
    }

    public function test_header_totals_always_equal_sum_of_lines(): void
    {
        $lines = [
            $this->line(['item_code' => 'A', 'unit_price_excl_tax' => '333.33', 'quantity' => '3', 'tax_rate' => '17.00']),
            $this->line(['item_code' => 'B', 'unit_price_excl_tax' => '19.99', 'quantity' => '7', 'tax_rate' => '18.00']),
            $this->line(['item_code' => 'C', 'unit_price_excl_tax' => '5.55', 'quantity' => '13', 'tax_rate' => '0.00']),
        ];

        $result = (new SaleTotalsCalculator())->calculate($lines, billDiscount: '50.00');

        $sums = ['sale_value' => '0', 'tax_charged' => '0', 'discount' => '0', 'further_tax' => '0', 'total_amount' => '0'];
        foreach ($result['lines'] as $line) {
            foreach (['sale_value', 'tax_charged', 'discount', 'further_tax', 'total_amount'] as $key) {
                $sums[$key] = bcadd($sums[$key], $line[$key], 2);
            }
        }

        $this->assertSame($result['header']['total_sale_value'], $sums['sale_value']);
        $this->assertSame($result['header']['total_tax_charged'], $sums['tax_charged']);
        $this->assertSame($result['header']['discount'], $sums['discount']);
        $this->assertSame($result['header']['further_tax'], $sums['further_tax']);
        $this->assertSame($result['header']['total_bill_amount'], $sums['total_amount']);

        // The full 50.00 bill discount must be fully allocated (no rounding leak).
        $this->assertSame('50.00', $result['header']['discount']);
    }

    public function test_bill_discount_rounding_remainder_goes_to_last_line_exactly(): void
    {
        // 3 lines of equal sale value with a discount that doesn't divide evenly by 3.
        $lines = [
            $this->line(['item_code' => 'A', 'unit_price_excl_tax' => '100.00', 'quantity' => '1']),
            $this->line(['item_code' => 'B', 'unit_price_excl_tax' => '100.00', 'quantity' => '1']),
            $this->line(['item_code' => 'C', 'unit_price_excl_tax' => '100.00', 'quantity' => '1']),
        ];

        $result = (new SaleTotalsCalculator())->calculate($lines, billDiscount: '10.00');

        $totalDiscount = array_sum(array_column($result['lines'], 'discount'));
        $this->assertEqualsWithDelta(10.00, $totalDiscount, 0.001);
        $this->assertSame('10.00', $result['header']['discount']);
    }

    public function test_rejects_empty_cart(): void
    {
        $this->expectException(InvalidCartException::class);
        (new SaleTotalsCalculator())->calculate([]);
    }

    public function test_rejects_non_positive_quantity(): void
    {
        $this->expectException(InvalidCartException::class);
        (new SaleTotalsCalculator())->calculate([$this->line(['quantity' => '0'])]);
    }

    public function test_rejects_bill_discount_exceeding_sale_value(): void
    {
        $this->expectException(InvalidCartException::class);
        (new SaleTotalsCalculator())->calculate([$this->line(['unit_price_excl_tax' => '10.00'])], billDiscount: '999.00');
    }

    public function test_rejects_line_discount_exceeding_sale_value(): void
    {
        $this->expectException(InvalidCartException::class);
        (new SaleTotalsCalculator())->calculate([
            $this->line(['unit_price_excl_tax' => '10.00', 'line_discount' => '999.00']),
        ]);
    }

    public function test_zero_rated_item_has_no_tax(): void
    {
        $result = (new SaleTotalsCalculator())->calculate([
            $this->line(['tax_rate' => '0.00', 'unit_price_excl_tax' => '50.00']),
        ]);

        $this->assertSame('0.00', $result['lines'][0]['tax_charged']);
        $this->assertSame('50.00', $result['lines'][0]['total_amount']);
    }

    public function test_further_tax_is_additive_not_taxed(): void
    {
        $result = (new SaleTotalsCalculator())->calculate([
            $this->line(['unit_price_excl_tax' => '100.00', 'tax_rate' => '18.00', 'further_tax' => '3.00']),
        ]);

        // taxable = 100, tax = 18, total = 100 + 18 + 3 = 121
        $this->assertSame('3.00', $result['lines'][0]['further_tax']);
        $this->assertSame('121.00', $result['lines'][0]['total_amount']);
    }
}
