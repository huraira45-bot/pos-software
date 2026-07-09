import type { CartLine } from '../types';

/**
 * Client-side mirror of SaleTotalsCalculator (app/Services/Sales/SaleTotalsCalculator.php)
 * for instant on-screen totals while the cashier builds a cart. This is a
 * convenience preview only - the server recomputes from scratch with exact
 * decimal arithmetic and is the sole source of truth for what gets invoiced
 * and fiscalized. Uses plain floats (fine for on-screen rounding to 2dp);
 * never used to construct the actual API payload amounts.
 */
export interface LineTotals {
  saleValue: number;
  discount: number;
  taxableValue: number;
  taxCharged: number;
  furtherTax: number;
  totalAmount: number;
}

export function calculateLine(line: CartLine): LineTotals {
  const unitPrice = parseFloat(line.unit_price_excl_tax);
  const taxRate = parseFloat(line.tax_rate);
  const lineDiscount = parseFloat(line.line_discount ?? '0');
  const furtherTax = parseFloat(line.further_tax ?? '0');

  const saleValue = round2(unitPrice * line.quantity);
  const taxableValue = round2(saleValue - lineDiscount);
  const taxCharged = round2((taxableValue * taxRate) / 100);
  const totalAmount = round2(taxableValue + taxCharged + furtherTax);

  return { saleValue, discount: lineDiscount, taxableValue, taxCharged, furtherTax, totalAmount };
}

export interface CartTotals {
  totalSaleValue: number;
  totalTaxCharged: number;
  totalDiscount: number;
  totalFurtherTax: number;
  totalBillAmount: number;
}

export function calculateCart(lines: CartLine[], billDiscount = 0): CartTotals {
  const lineTotals = lines.map(calculateLine);
  const totalSaleValue = round2(lineTotals.reduce((sum, l) => sum + l.saleValue, 0));
  const totalTaxCharged = round2(lineTotals.reduce((sum, l) => sum + l.taxCharged, 0));
  const totalFurtherTax = round2(lineTotals.reduce((sum, l) => sum + l.furtherTax, 0));
  const totalLineDiscount = round2(lineTotals.reduce((sum, l) => sum + l.discount, 0));
  const totalDiscount = round2(totalLineDiscount + billDiscount);
  // NB: bill_discount's effect on tax (via re-allocation across lines) is
  // computed authoritatively server-side; this preview subtracts it from the
  // bill total directly, which is exact when tax rates are uniform across
  // lines and a very close approximation otherwise.
  const totalBillAmount = round2(
    lineTotals.reduce((sum, l) => sum + l.totalAmount, 0) - billDiscount,
  );

  return { totalSaleValue, totalTaxCharged, totalDiscount, totalFurtherTax, totalBillAmount };
}

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}
