import { useEffect, useState } from 'react';
import { apiClient } from '../api/client';
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
import { printReceipt } from '../lib/printAgent';
import { Button, Card, Input, PageHeader, Select } from '../components/ui';
import type { Invoice, Product, SaleRequest, UsinType } from '../types';

/** How long to keep polling for a fiscalization result before giving up and
 *  letting the cashier print with a "still pending" banner instead. FBR being
 *  slow/unreachable must never leave the till stuck forever. */
const FISCAL_POLL_INTERVAL_MS = 1500;
const FISCAL_POLL_TIMEOUT_MS = 45000;

type Status =
  | { kind: 'idle' }
  | { kind: 'waiting'; invoice: Invoice }
  | { kind: 'ready'; invoice: Invoice; timedOut: boolean }
  | { kind: 'queued'; message: string }
  | { kind: 'error'; message: string };

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export default function CheckoutPage() {
  const { terminalId, branchId } = useTerminalStore();
  const config = useConfigStore((s) => s.config);
  const loadConfig = useConfigStore((s) => s.load);

  const lines = useCartStore((s) => s.lines);
  const tenders = useCartStore((s) => s.tenders);
  const buyer = useCartStore((s) => s.buyer);
  const setBuyer = useCartStore((s) => s.setBuyer);
  const customer = useCartStore((s) => s.customer);
  const addLine = useCartStore((s) => s.addLine);
  const usinType = useCartStore((s) => s.usinType);
  const setUsinType = useCartStore((s) => s.setUsinType);
  const reset = useCartStore((s) => s.reset);

  const [status, setStatus] = useState<Status>({ kind: 'idle' });
  const [printState, setPrintState] = useState<{ kind: 'idle' } | { kind: 'printing' } | { kind: 'done' } | { kind: 'failed'; error: string }>({
    kind: 'idle',
  });

  useEffect(() => {
    loadConfig();
  }, [loadConfig]);

  if (!terminalId || !branchId) {
    return <TerminalPicker />;
  }

  const totals = calculateCart(lines);

  const buyerCaptureThreshold = config?.buyer_capture_threshold ?? 100000;
  const requiresBuyer = totals.totalBillAmount > buyerCaptureThreshold;
  const buyerSatisfied = customer
    ? Boolean(customer.name && (customer.ntn || customer.cnic))
    : Boolean(buyer.name && (buyer.ntn || buyer.cnic));
  const tenderedSum = tenders.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
  const canFinalize =
    status.kind === 'idle' &&
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

  /** Polls the invoice until FBR has actually responded (synced or permanently
   *  failed), rather than offering "print" the instant checkout returns - the
   *  request only creates the invoice locally; fiscalization runs a moment
   *  later in the background queue worker. */
  async function waitForFiscalization(invoice: Invoice) {
    const deadline = Date.now() + FISCAL_POLL_TIMEOUT_MS;

    let current = invoice;
    while (current.fiscal_status === 'pending' && Date.now() < deadline) {
      await sleep(FISCAL_POLL_INTERVAL_MS);
      try {
        const { data } = await apiClient.get<Invoice>(`/sales/${invoice.id}`);
        current = data;
        setStatus({ kind: 'waiting', invoice: current });
      } catch {
        // Transient poll failure - keep trying until the deadline.
      }
    }

    setStatus({ kind: 'ready', invoice: current, timedOut: current.fiscal_status === 'pending' });
  }

  async function submit() {
    setStatus({ kind: 'idle' });
    setPrintState({ kind: 'idle' });

    const request: SaleRequest = {
      branch_id: branchId!,
      terminal_id: terminalId!,
      usin_type: usinType,
      items: lines.map((l) => ({
        product_id: l.product_id,
        variant_id: l.variant_id,
        quantity: l.quantity,
        unit_price_excl_tax: l.unit_price_excl_tax,
        line_discount: l.line_discount,
        further_tax: l.further_tax,
      })),
      tenders,
      buyer: !customer && requiresBuyer ? buyer : undefined,
      customer_id: customer?.id,
    };

    try {
      const result = await submitSale(request, totals.totalBillAmount.toFixed(2));
      if (result.mode === 'synced') {
        setStatus({ kind: 'waiting', invoice: result.invoice });
        await waitForFiscalization(result.invoice);
      } else {
        setStatus({
          kind: 'queued',
          message: `Offline - sale saved locally and will sync automatically (ref ${result.localId.slice(0, 8)}).`,
        });
        reset();
      }
    } catch {
      setStatus({ kind: 'error', message: 'Could not complete the sale. Please check the cart and try again.' });
    }
  }

  async function handlePrint(invoiceId: number) {
    setPrintState({ kind: 'printing' });
    try {
      const { data: receipt } = await apiClient.get(`/sales/${invoiceId}/receipt`);
      const result = await printReceipt(receipt);
      setPrintState(result.ok ? { kind: 'done' } : { kind: 'failed', error: result.error });
    } catch {
      setPrintState({ kind: 'failed', error: 'Could not load the receipt to print.' });
    }
  }

  function startNewSale() {
    reset();
    setStatus({ kind: 'idle' });
    setPrintState({ kind: 'idle' });
  }

  /** Alternative to the ESC/POS thermal-printer path above - opens the same
   *  receipt as a browser-rendered PDF (no till printer hardware needed), which
   *  the browser's own print dialog can then send to a real printer or save. */
  async function handleViewPdf(invoiceId: number) {
    // Open the tab synchronously, in direct response to the click - opening it
    // after the await below is what browsers' popup blockers silently swallow.
    const pdfWindow = window.open('', '_blank');
    const response = await apiClient.get(`/sales/${invoiceId}/receipt.pdf`, { responseType: 'blob' });
    if (pdfWindow) {
      // Create the object URL in the popup's own realm, not the opener's -
      // a blob URL registered here isn't reliably resolvable from a
      // different window/tab (Chrome partitions blob storage per window),
      // which is why the tab opened but rendered blank.
      const blobUrl = (pdfWindow as Window & typeof globalThis).URL.createObjectURL(response.data as Blob);
      pdfWindow.location.href = blobUrl;
    } else {
      const blobUrl = URL.createObjectURL(response.data as Blob);
      window.open(blobUrl, '_blank');
    }
  }

  return (
    <div>
      <OfflineBanner />
      <PageHeader title="POS Checkout" subtitle="Scan or search an item to begin a sale." />

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <section className="lg:col-span-2">
          <div className="mb-4">
            <ProductSearch onSelect={handleSelectProduct} />
          </div>
          <Card className="p-4">
            <CartTable />
          </Card>
        </section>

        <aside className="space-y-4">
          <Card className="space-y-1.5 p-4 text-sm text-ink-muted">
            <div className="flex justify-between">
              <span>Sale value</span>
              <span className="tabular-nums text-ink">Rs.{totals.totalSaleValue.toFixed(2)}</span>
            </div>
            <div className="flex justify-between">
              <span>Tax charged</span>
              <span className="tabular-nums text-ink">Rs.{totals.totalTaxCharged.toFixed(2)}</span>
            </div>
            <div className="flex justify-between border-t border-border pt-1.5 text-base font-semibold text-ink">
              <span>Total</span>
              <span className="tabular-nums">Rs.{totals.totalBillAmount.toFixed(2)}</span>
            </div>
          </Card>

          <Card className="p-4">
            <Select
              label="Invoice Type"
              hint="Which USIN sequence this sale is numbered under."
              value={usinType}
              onChange={(e) => setUsinType(e.target.value as UsinType)}
              disabled={status.kind !== 'idle'}
            >
              <option value="SIR">SIR (e.g. SIR-1056)</option>
              <option value="SS">SS (e.g. SS_1034)</option>
            </Select>
          </Card>

          <Card className="p-4">
            <CustomerAttach />
          </Card>

          {requiresBuyer && !customer && (
            <Card className="space-y-2 p-4">
              <p className="text-xs text-warning">Buyer details required for invoices over Rs.{buyerCaptureThreshold.toLocaleString()}.</p>
              <Input placeholder="Buyer name" value={buyer.name ?? ''} onChange={(e) => setBuyer({ ...buyer, name: e.target.value })} />
              <Input placeholder="Buyer NTN or CNIC" value={buyer.ntn ?? ''} onChange={(e) => setBuyer({ ...buyer, ntn: e.target.value })} />
            </Card>
          )}

          <Card className="p-4">
            <TenderPanel billTotal={totals.totalBillAmount} />
          </Card>

          {status.kind === 'idle' && (
            <Button variant="primary" size="md" onClick={submit} disabled={!canFinalize} className="w-full py-2.5">
              Finalize Sale
            </Button>
          )}

          {status.kind === 'waiting' && (
            <div className="space-y-2 rounded-card border border-transparent bg-info-bg p-3 text-sm shadow-card">
              <p className="flex items-center gap-2 text-info">
                <span className="h-2.5 w-2.5 animate-pulse rounded-full bg-info" />
                Waiting for FBR - USIN {status.invoice.usin}…
              </p>
              <p className="text-xs text-ink-muted">The sale is saved; finalizing once FBR confirms the invoice.</p>
            </div>
          )}

          {status.kind === 'ready' && (
            <div className="space-y-3 rounded-card border border-transparent bg-success-bg p-3 text-sm shadow-card">
              <div>
                <p className="font-medium text-success">Sale complete - USIN {status.invoice.usin}</p>
                <p className="text-xs text-ink-muted">
                  {status.timedOut
                    ? 'FBR sync is taking longer than usual - it will finish in the background. Printing now shows a "SYNC PENDING" banner.'
                    : status.invoice.fiscal_status === 'synced'
                      ? `FBR Invoice No: ${status.invoice.fbr_invoice_number}`
                      : 'FBR rejected this submission permanently - check the compliance dashboard.'}
                </p>
              </div>

              <div className="flex gap-2">
                <Button variant="primary" size="sm" onClick={() => handlePrint(status.invoice.id)} loading={printState.kind === 'printing'} className="flex-1">
                  Print Receipt
                </Button>
                <Button variant="secondary" size="sm" onClick={() => handleViewPdf(status.invoice.id)} className="flex-1">
                  View/Print PDF
                </Button>
                <Button variant="secondary" size="sm" onClick={startNewSale}>
                  New Sale
                </Button>
              </div>

              {printState.kind === 'done' && <p className="text-xs text-success">Printed.</p>}
              {printState.kind === 'failed' && <p className="text-xs text-danger">{printState.error}</p>}
            </div>
          )}

          {status.kind === 'queued' && <p className="text-sm text-warning">{status.message}</p>}
          {status.kind === 'error' && <p className="text-sm text-danger">{status.message}</p>}
        </aside>
      </div>
    </div>
  );
}
