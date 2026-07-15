import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { apiClient } from '../api/client';
import { Card, EmptyState, ErrorState, FiscalStatusBadge, LoadingState, PageHeader, StatCard } from '../components/ui';
import type { Invoice } from '../types';

interface DashboardSummary {
  today: { invoice_count: string; net_bill_amount: string };
  range: { from: string; to: string; invoice_count: string; total_revenue: string };
  pending_payments: { customer_balance_total: string };
  low_stock_count: number;
  top_products: Array<{ item_code: string; item_name: string; qty_sold: string; total_revenue: string }>;
  recent_sales: Invoice[];
  sales_trend: Array<{ period: string; invoice_count: string; total_revenue: string }>;
  payment_breakdown: Array<{ label: string; count: string; total: string }>;
  fiscal_health: Array<{
    terminal_id: number;
    terminal_code: string;
    pending_count: number;
    failed_permanent_count: number;
    is_breaching_threshold: boolean;
  }>;
}

function money(value: string | number): string {
  const n = Number(value);
  return `Rs. ${n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Fires the single composed GET /api/dashboard/summary (DashboardService)
 *  rather than 6+ parallel report requests. Same pos.reports-view gate as
 *  the Reports page - the AppShell nav item is gated to match. */
export default function DashboardPage() {
  const [data, setData] = useState<DashboardSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const { data: summary } = await apiClient.get<DashboardSummary>('/dashboard/summary');
      setData(summary);
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not load the dashboard.';
      setError(message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  if (loading) return <LoadingState label="Loading dashboard…" />;
  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!data) return null;

  const trendData = data.sales_trend.map((t) => ({ period: t.period, total_revenue: Number(t.total_revenue) }));
  const paymentData = data.payment_breakdown.map((p) => ({ label: p.label, total: Number(p.total) }));
  const breachingTerminals = data.fiscal_health.filter((t) => t.is_breaching_threshold);

  return (
    <div>
      <PageHeader title="Dashboard" subtitle={`Last 30 days (${data.range.from} to ${data.range.to})`} />

      <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard label="Today's Sales" value={data.today.invoice_count} hint="invoices" />
        <StatCard label="Today's Revenue" value={money(data.today.net_bill_amount)} tone="success" />
        <StatCard label="Invoices (30d)" value={data.range.invoice_count} />
        <StatCard label="Revenue (30d)" value={money(data.range.total_revenue)} tone="success" />
        <StatCard label="Pending Payments" value={money(data.pending_payments.customer_balance_total)} tone="warning" />
        <StatCard label="Low Stock" value={String(data.low_stock_count)} tone={data.low_stock_count > 0 ? 'danger' : 'neutral'} />
      </div>

      {breachingTerminals.length > 0 && (
        <div className="mb-5 rounded-card border border-transparent bg-danger-bg p-3 text-sm text-danger shadow-card">
          {breachingTerminals.length} terminal{breachingTerminals.length > 1 ? 's have' : ' has'} FBR submissions pending past the
          compliance threshold - check the compliance dashboard.
        </div>
      )}

      <div className="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-4">
          <h2 className="mb-3 text-sm font-medium text-ink">Sales Trend (30 days)</h2>
          {trendData.length === 0 ? (
            <EmptyState title="No sales in this range." />
          ) : (
            <ResponsiveContainer width="100%" height={260}>
              <AreaChart data={trendData}>
                <defs>
                  <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#714B67" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#714B67" stopOpacity={0.02} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e5e0e4" vertical={false} />
                <XAxis dataKey="period" tick={{ fontSize: 11 }} tickLine={false} axisLine={{ stroke: '#e5e0e4' }} />
                <YAxis
                  tick={{ fontSize: 11 }}
                  tickLine={false}
                  axisLine={false}
                  width={70}
                  tickFormatter={(v) => `Rs.${Number(v).toLocaleString()}`}
                />
                <Tooltip
                  formatter={(value) => [money(Number(value)), 'Revenue']}
                  labelFormatter={(label) => `Date: ${label}`}
                  contentStyle={{ fontSize: 12, borderRadius: 8 }}
                />
                <Area type="monotone" dataKey="total_revenue" stroke="#714B67" strokeWidth={2} fill="url(#revenueFill)" />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </Card>

        <Card className="p-4">
          <h2 className="mb-3 text-sm font-medium text-ink">Payment Method Breakdown (30 days)</h2>
          {paymentData.length === 0 ? (
            <EmptyState title="No payments in this range." />
          ) : (
            <ResponsiveContainer width="100%" height={260}>
              <BarChart data={paymentData} layout="vertical" margin={{ left: 24 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e5e0e4" horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => `Rs.${Number(v).toLocaleString()}`} />
                <YAxis type="category" dataKey="label" tick={{ fontSize: 12 }} width={100} />
                <Tooltip formatter={(value) => [money(Number(value)), 'Collected']} contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                <Bar dataKey="total" fill="#714B67" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card className="p-4">
          <h2 className="mb-3 text-sm font-medium text-ink">Top-Selling Products (30 days)</h2>
          {data.top_products.length === 0 ? (
            <EmptyState title="No sales in this range." />
          ) : (
            <ul className="divide-y divide-border">
              {data.top_products.map((p) => (
                <li key={p.item_code} className="flex items-center justify-between py-2 text-sm">
                  <div>
                    <p className="font-medium text-ink">{p.item_name}</p>
                    <p className="text-xs text-ink-faint">
                      {p.item_code} - {p.qty_sold} units
                    </p>
                  </div>
                  <span className="tabular-nums text-ink-muted">{money(p.total_revenue)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className="p-4">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-medium text-ink">Recent Sales</h2>
            <Link to="/sales-history" className="text-xs font-medium text-primary-600 hover:text-primary-700">
              View all
            </Link>
          </div>
          {data.recent_sales.length === 0 ? (
            <EmptyState title="No sales yet." />
          ) : (
            <ul className="divide-y divide-border">
              {data.recent_sales.map((inv) => (
                <li key={inv.id} className="flex items-center justify-between py-2 text-sm">
                  <div>
                    <p className="font-medium text-ink">USIN {inv.usin}</p>
                    <p className="text-xs text-ink-faint">{new Date(inv.sold_at).toLocaleString()}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="tabular-nums text-ink-muted">{money(inv.total_bill_amount)}</span>
                    <FiscalStatusBadge status={inv.fiscal_status} />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}
