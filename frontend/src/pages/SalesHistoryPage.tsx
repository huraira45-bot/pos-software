import { useEffect, useState } from 'react';
import { apiClient } from '../api/client';
import FilterBar, { type FilterValues } from '../components/reports/FilterBar';
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  FiscalStatusBadge,
  LoadingState,
  PageHeader,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TR,
} from '../components/ui';
import type { Invoice } from '../types';

const PAYMENT_LABELS: Record<number, string> = {
  1: 'Cash',
  2: 'Card',
  3: 'Gift Voucher',
  4: 'Loyalty Card',
  5: 'Mixed',
  6: 'Cheque',
};

interface RawCustomer {
  id: number;
  name: string;
  company_name: string | null;
}

interface PaginationMeta {
  current_page: number;
  last_page: number;
}

function defaultRange(): FilterValues {
  const to = new Date();
  const from = new Date();
  from.setDate(from.getDate() - 30);
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) };
}

/** Reuses the existing paginated GET /api/sales (already supports branch_id/
 *  customer_id/from/to) - no backend work needed for this page. */
export default function SalesHistoryPage() {
  const [filters, setFilters] = useState<FilterValues>(defaultRange());
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [customerNames, setCustomerNames] = useState<Record<number, string>>({});
  const [meta, setMeta] = useState<PaginationMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pdfLoadingId, setPdfLoadingId] = useState<number | null>(null);

  useEffect(() => {
    apiClient
      .get<RawCustomer[]>('/customers', { params: { limit: 500 } })
      .then(({ data }) => {
        const map: Record<number, string> = {};
        data.forEach((c) => {
          map[c.id] = c.company_name || c.name;
        });
        setCustomerNames(map);
      })
      .catch(() => setCustomerNames({}));
  }, []);

  async function load(activeFilters: FilterValues, page = 1) {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string | number> = { per_page: 25, page };
      if (activeFilters.branch_id) params.branch_id = activeFilters.branch_id;
      if (activeFilters.from) params.from = activeFilters.from;
      if (activeFilters.to) params.to = activeFilters.to;
      if (activeFilters.customer_id) params.customer_id = activeFilters.customer_id;

      const { data } = await apiClient.get('/sales', { params });
      setInvoices(data.data);
      setMeta(data.meta ? { current_page: data.meta.current_page, last_page: data.meta.last_page } : null);
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not load sales history.';
      setError(message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(filters, 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleViewPdf(invoiceId: number) {
    // Open the tab synchronously, in direct response to the click - opening it
    // after the await below is what browsers' popup blockers silently swallow.
    const pdfWindow = window.open('', '_blank');
    setPdfLoadingId(invoiceId);
    try {
      const response = await apiClient.get(`/sales/${invoiceId}/receipt.pdf`, { responseType: 'blob' });
      const blobUrl = URL.createObjectURL(response.data as Blob);
      if (pdfWindow) {
        pdfWindow.location.href = blobUrl;
      } else {
        window.open(blobUrl, '_blank');
      }
    } finally {
      setPdfLoadingId(null);
    }
  }

  return (
    <div>
      <PageHeader title="Sales History" subtitle="Every completed sale, searchable by branch, customer, and date" />

      <FilterBar
        config={{ branch: true, dateRange: true, customer: true }}
        values={filters}
        onChange={setFilters}
        onApply={() => load(filters, 1)}
        loading={loading}
      />

      <Card className="p-4">
        {loading ? (
          <LoadingState />
        ) : error ? (
          <ErrorState message={error} onRetry={() => load(filters, meta?.current_page ?? 1)} />
        ) : invoices.length === 0 ? (
          <EmptyState title="No sales in this range." />
        ) : (
          <>
            <Table>
              <THead>
                <TH>Date</TH>
                <TH>USIN</TH>
                <TH>Customer</TH>
                <TH>Payment</TH>
                <TH align="right">Amount</TH>
                <TH>Fiscal</TH>
                <TH></TH>
              </THead>
              <TBody>
                {invoices.map((inv) => (
                  <TR key={inv.id}>
                    <TD>{new Date(inv.sold_at).toLocaleString()}</TD>
                    <TD>{inv.usin}</TD>
                    <TD>{inv.customer_id ? (customerNames[inv.customer_id] ?? `#${inv.customer_id}`) : 'Walk-in'}</TD>
                    <TD>{PAYMENT_LABELS[inv.payment_mode] ?? inv.payment_mode}</TD>
                    <TD align="right">Rs. {Number(inv.total_bill_amount).toLocaleString('en-PK', { minimumFractionDigits: 2 })}</TD>
                    <TD>
                      <FiscalStatusBadge status={inv.fiscal_status} />
                    </TD>
                    <TD align="right">
                      <button
                        onClick={() => handleViewPdf(inv.id)}
                        disabled={pdfLoadingId === inv.id}
                        className="text-xs font-medium text-primary-600 hover:text-primary-700 disabled:text-ink-faint"
                      >
                        {pdfLoadingId === inv.id ? 'Loading…' : 'View PDF'}
                      </button>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>

            {meta && meta.last_page > 1 && (
              <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
                <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => load(filters, meta.current_page - 1)}>
                  Previous
                </Button>
                <span>
                  Page {meta.current_page} of {meta.last_page}
                </span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => load(filters, meta.current_page + 1)}
                >
                  Next
                </Button>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  );
}
