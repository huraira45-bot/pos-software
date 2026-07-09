import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useCartStore } from '../stores/cartStore';
import { useTerminalStore } from '../stores/terminalStore';
import { useConfigStore } from '../stores/configStore';
import ProductSearch from '../components/ProductSearch';
import CartTable from '../components/CartTable';
import TenderPanel from '../components/TenderPanel';
import TerminalPicker from '../components/TerminalPicker';
import OfflineBanner from '../components/OfflineBanner';
import CustomerAttach from '../components/CustomerAttach';
import { calculateCart } from '../lib/taxCalc';
import { submitSale } from '../lib/saleSubmission';
import type { Product, SaleRequest } from '../types';

const FURTHER_TAX_OVERRIDE_PERMISSION = 'pos.further-tax-override';

export default function CheckoutPage() {
  const session = useAuthStore((s) => s.session);
  const logout = useAuthStore((s) => s.logout);
  const { terminalId, branchId } = useTerminalStore();
  const config = useConfigStore((s) => s.config);
  const loadConfig = useConfigStore((s) => s.load);

  const lines = useCartStore((s) => s.lines);
  const tenders = useCartStore((s) => s.tenders);
  const buyer = useCartStore((s) => s.buyer);
  const setBuyer = useCartStore((s) => s.setBuyer);
  const customer = useCartStore((s) => s.customer);
  const confirmNonAtlB2b = useCartStore((s) => s.confirmNonAtlB2b);
  const setConfirmNonAtlB2b = useCartStore((s) => s.setConfirmNonAtlB2b);
  const waiveFurtherTax = useCartStore((s) => s.waiveFurtherTax);
  const setWaiveFurtherTax = useCartStore((s) => s.setWaiveFurtherTax);
  const addLine = useCartStore((s) => s.addLine);
  const reset = useCartStore((s) => s.reset);

  const [status, setStatus] = useState<
    | { kind: 'idle' }
    | { kind: 'success'; message: string }
    | { kind: 'queued'; message: string }
    | { kind: 'error'; message: string }
  >({ kind: 'idle' });
  const [nonAtlPrompt, setNonAtlPrompt] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    loadConfig();
  }, [loadConfig]);

  if (!terminalId || !branchId) {
    return <TerminalPicker />;
  }

  const baseTotals = calculateCart(lines);
  const isNonAtlB2b = customer?.customer_type === 'b2b' && customer.atl_status !== 'active';
  const canOverrideFurtherTax = session?.user.permissions?.includes(FURTHER_TAX_OVERRIDE_PERMISSION) ?? false;

  // Preview the same Further Tax the server will apply, so the tender amount
  // the cashier collects (and TenderPanel's auto-fill) already accounts for it
  // - otherwise confirming the non-ATL prompt would leave the sale under-tendered.
  const furtherTaxRate = config?.further_tax_rate_percent ?? 0;
  const estimatedFurtherTax =
    isNonAtlB2b && !waiveFurtherTax ? Math.round(baseTotals.totalSaleValue * (furtherTaxRate / 100) * 100) / 100 : 0;
  const totals = {
    ...baseTotals,
    totalBillAmount: Math.round((baseTotals.totalBillAmount + estimatedFurtherTax) * 100) / 100,
  };

  const buyerCaptureThreshold = config?.buyer_capture_threshold ?? 100000;
  const requiresBuyer = totals.totalBillAmount > buyerCaptureThreshold;
  const buyerSatisfied = customer
    ? Boolean(customer.name && (customer.ntn || customer.cnic))
    : Boolean(buyer.name && (buyer.ntn || buyer.cnic));
  const tenderedSum = tenders.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
  const canFinalize =
    lines.length > 0 &&
    Math.abs(tenderedSum - totals.totalBillAmount) < 0.01 &&
    (!requiresBuyer || buyerSatisfied);

  function handleSelectProduct(product: Product) {
    addLine({
      product_id: product.id,
      item_code: product.item_code,
      name: product.name,
      quantity: 1,
      unit_price_excl_tax: product.price_excl_tax,
      tax_rate: product.tax_rate,
    });
  }

  async function submit(confirmNonAtlOverride?: boolean) {
    setSubmitting(true);
    setStatus({ kind: 'idle' });

    const request: SaleRequest = {
      branch_id: branchId!,
      terminal_id: terminalId!,
      items: lines.map((l) => ({
        product_id: l.product_id,
        variant_id: l.variant_id,
        quantity: l.quantity,
        line_discount: l.line_discount,
        further_tax: l.further_tax,
      })),
      tenders,
      buyer: !customer && requiresBuyer ? buyer : undefined,
      customer_id: customer?.id,
      // confirmNonAtlOverride is used (rather than reading confirmNonAtlB2b from
      // the store) because handleConfirmNonAtl calls setConfirmNonAtlB2b(true)
      // then submit() in the same tick - the store update hasn't re-rendered
      // yet, so this closure would otherwise still see the stale `false`.
      confirm_non_atl_b2b: (confirmNonAtlOverride ?? confirmNonAtlB2b) || undefined,
      waive_further_tax: waiveFurtherTax || undefined,
    };

    try {
      const result = await submitSale(request, totals.totalBillAmount.toFixed(2));
      if (result.mode === 'synced') {
        setStatus({
          kind: 'success',
          message: `Sale complete - USIN ${result.invoice.usin}, FBR #${result.invoice.fbr_invoice_number ?? 'pending'}`,
        });
      } else {
        setStatus({
          kind: 'queued',
          message: `Offline - sale saved locally and will sync automatically (ref ${result.localId.slice(0, 8)}).`,
        });
      }
      reset();
      setNonAtlPrompt(null);
    } catch (err: unknown) {
      const response = (err as { response?: { status?: number; data?: { message?: string; error_code?: string } } })
        .response;
      if (response?.status === 409 && response.data?.error_code === 'non_atl_confirmation_required') {
        setNonAtlPrompt(response.data.message ?? 'This customer is not ATL-active. Confirm to proceed.');
      } else {
        setStatus({ kind: 'error', message: 'Could not complete the sale. Please check the cart and try again.' });
      }
    } finally {
      setSubmitting(false);
    }
  }

  async function handleFinalize() {
    if (!canFinalize) return;
    await submit();
  }

  async function handleConfirmNonAtl() {
    setConfirmNonAtlB2b(true);
    setNonAtlPrompt(null);
    await submit(true);
  }

  return (
    <div className="flex min-h-full flex-col">
      <OfflineBanner />

      <header className="flex items-center justify-between border-b border-slate-800 px-6 py-3">
        <div>
          <h1 className="text-lg font-semibold text-white">POS Checkout</h1>
          <p className="text-xs text-slate-400">
            {session?.user.name} · Terminal #{terminalId}
          </p>
        </div>
        <div className="flex items-center gap-4">
          <Link to="/products" className="text-sm text-sky-400 hover:text-sky-300">
            Products
          </Link>
          {session?.user.permissions?.includes('pos.customer-manage') && (
            <Link to="/atl-import" className="text-sm text-sky-400 hover:text-sky-300">
              ATL Import
            </Link>
          )}
          <button onClick={logout} className="text-sm text-slate-400 hover:text-white">
            Sign out
          </button>
        </div>
      </header>

      <main className="grid flex-1 grid-cols-1 gap-6 p-6 lg:grid-cols-3">
        <section className="lg:col-span-2">
          <div className="mb-4">
            <ProductSearch onSelect={handleSelectProduct} />
          </div>
          <div className="rounded-lg bg-slate-800 p-4">
            <CartTable />
          </div>
        </section>

        <aside className="space-y-4 rounded-lg bg-slate-800 p-4">
          <div className="space-y-1 text-sm text-slate-300">
            <div className="flex justify-between">
              <span>Sale value</span>
              <span>Rs.{totals.totalSaleValue.toFixed(2)}</span>
            </div>
            <div className="flex justify-between">
              <span>Tax charged</span>
              <span>Rs.{totals.totalTaxCharged.toFixed(2)}</span>
            </div>
            {estimatedFurtherTax > 0 && (
              <div className="flex justify-between text-amber-400">
                <span>Further Tax (non-ATL, est.)</span>
                <span>Rs.{estimatedFurtherTax.toFixed(2)}</span>
              </div>
            )}
            <div className="flex justify-between border-t border-slate-700 pt-1 text-base font-semibold text-white">
              <span>Total</span>
              <span>Rs.{totals.totalBillAmount.toFixed(2)}</span>
            </div>
          </div>

          <div className="border-t border-slate-700 pt-3">
            <CustomerAttach />
          </div>

          {requiresBuyer && !customer && (
            <div className="space-y-2 border-t border-slate-700 pt-3">
              <p className="text-xs text-amber-400">Buyer details required for invoices over Rs.{buyerCaptureThreshold.toLocaleString()}.</p>
              <input
                placeholder="Buyer name"
                value={buyer.name ?? ''}
                onChange={(e) => setBuyer({ ...buyer, name: e.target.value })}
                className="w-full rounded border border-slate-700 bg-slate-900 px-2 py-1 text-sm text-white"
              />
              <input
                placeholder="Buyer NTN or CNIC"
                value={buyer.ntn ?? ''}
                onChange={(e) => setBuyer({ ...buyer, ntn: e.target.value })}
                className="w-full rounded border border-slate-700 bg-slate-900 px-2 py-1 text-sm text-white"
              />
            </div>
          )}

          {isNonAtlB2b && canOverrideFurtherTax && (
            <label className="flex items-center gap-2 border-t border-slate-700 pt-3 text-xs text-slate-300">
              <input
                type="checkbox"
                checked={waiveFurtherTax}
                onChange={(e) => setWaiveFurtherTax(e.target.checked)}
              />
              Waive Further Tax for this non-ATL customer
            </label>
          )}

          {nonAtlPrompt && (
            <div className="space-y-2 rounded-md border border-amber-700 bg-amber-950/50 p-3">
              <p className="text-xs text-amber-300">{nonAtlPrompt}</p>
              <div className="flex gap-2">
                <button
                  onClick={handleConfirmNonAtl}
                  className="flex-1 rounded bg-amber-600 py-1.5 text-xs font-medium text-white hover:bg-amber-500"
                >
                  Confirm & proceed
                </button>
                <button
                  onClick={() => setNonAtlPrompt(null)}
                  className="rounded border border-slate-600 px-2 text-xs text-slate-300 hover:bg-slate-700"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          <div className="border-t border-slate-700 pt-3">
            <TenderPanel billTotal={totals.totalBillAmount} />
          </div>

          <button
            onClick={handleFinalize}
            disabled={!canFinalize || submitting}
            className="w-full rounded-md bg-emerald-600 py-2.5 font-medium text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {submitting ? 'Finalizing…' : 'Finalize Sale'}
          </button>

          {status.kind !== 'idle' && (
            <p
              className={`text-sm ${
                status.kind === 'success'
                  ? 'text-emerald-400'
                  : status.kind === 'queued'
                    ? 'text-amber-400'
                    : 'text-red-400'
              }`}
            >
              {status.message}
            </p>
          )}
        </aside>
      </main>
    </div>
  );
}
