import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient } from '../../api/client';
import { getReport, getReportFilters } from '../../reports/registry';
import { downloadReportCsv } from '../../lib/csv';
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  StatCard,
  Table,
  TBody,
  TD,
  TH,
  THead,
  TotalsRow,
  TR,
} from '../ui';
import FilterBar, { type FilterValues } from './FilterBar';
import type { ReportEnvelope } from '../../reports/types';

function defaultRange(): FilterValues {
  const to = new Date();
  const from = new Date();
  from.setDate(from.getDate() - 30);
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) };
}

function formatValue(value: string | undefined, format?: string): string {
  if (value === undefined || value === null || value === '') return '-';
  if (format === 'currency') {
    const n = Number(value);
    return Number.isNaN(n) ? value : `Rs. ${n.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
  if (format === 'number') {
    const n = Number(value);
    return Number.isNaN(n) ? value : n.toLocaleString('en-PK');
  }
  if (format === 'datetime') {
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
  }
  return value;
}

/** Generic renderer for every report in the registry: FilterBar + summary
 *  StatCards + Table + TotalsRow + CSV export, driven entirely by the
 *  {summary, columns, rows, totals, meta} envelope the backend returns. */
export default function ReportView() {
  const { reportKey } = useParams<{ reportKey: string }>();
  const config = reportKey ? getReport(reportKey) : undefined;
  const filterConfig = reportKey ? getReportFilters(reportKey) : {};

  const [filters, setFilters] = useState<FilterValues>(() => (filterConfig.dateRange ? defaultRange() : {}));
  const [data, setData] = useState<ReportEnvelope | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canLoad = !filterConfig.customerRequired || !!filters.customer_id;

  async function load(activeFilters: FilterValues) {
    if (!config) return;
    if (filterConfig.customerRequired && !activeFilters.customer_id) return;

    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = {};
      if (activeFilters.branch_id) params.branch_id = activeFilters.branch_id;
      if (activeFilters.from) params.from = activeFilters.from;
      if (activeFilters.to) params.to = activeFilters.to;
      if (activeFilters.customer_id) params.customer_id = activeFilters.customer_id;
      if (activeFilters.product_id) params.product_id = activeFilters.product_id;
      if (activeFilters.category_id) params.category_id = activeFilters.category_id;
      if (filterConfig.granularity) params.granularity = filterConfig.granularity;

      const { data: envelope } = await apiClient.get<ReportEnvelope>(config.endpoint, { params });
      setData(envelope);
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not load this report.';
      setError(message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const initial = filterConfig.dateRange ? defaultRange() : {};
    setFilters(initial);
    setData(null);
    setError(null);
    if (!filterConfig.customerRequired) {
      load(initial);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reportKey]);

  if (!config) {
    return <EmptyState title="Unknown report." />;
  }

  function handleExport() {
    if (!data) return;
    downloadReportCsv(data, `${config!.key}-${new Date().toISOString().slice(0, 10)}.csv`);
  }

  return (
    <div>
      <PageHeader
        title={config.title}
        subtitle={config.description}
        actions={
          <>
            <Button variant="secondary" onClick={handleExport} disabled={!data || data.rows.length === 0}>
              Export CSV
            </Button>
            <Button variant="secondary" onClick={() => window.print()} disabled={!data}>
              Print
            </Button>
          </>
        }
      />

      <FilterBar config={filterConfig} values={filters} onChange={setFilters} onApply={() => load(filters)} loading={loading} />

      {data && data.summary.length > 0 && (
        <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {data.summary.map((m) => (
            <StatCard key={m.key} label={m.label} value={formatValue(m.value, m.format)} />
          ))}
        </div>
      )}

      {typeof data?.meta.note === 'string' && <p className="mb-3 text-xs text-ink-faint">{data.meta.note}</p>}
      {typeof data?.meta.caveat === 'string' && <p className="mb-3 text-xs text-warning">{data.meta.caveat}</p>}

      <Card className="p-4">
        {!canLoad ? (
          <EmptyState title="Select a customer" description="Choose a B2B customer above to view their statement." />
        ) : loading ? (
          <LoadingState />
        ) : error ? (
          <ErrorState message={error} onRetry={() => load(filters)} />
        ) : !data || data.rows.length === 0 ? (
          <EmptyState title="No data for this range." description="Try widening the date range or clearing filters." />
        ) : (
          <Table>
            <THead>
              {data.columns.map((c) => (
                <TH key={c.key} align={c.align}>
                  {c.label}
                </TH>
              ))}
            </THead>
            <TBody>
              {data.rows.map((row, i) => (
                <TR key={i}>
                  {data.columns.map((c) => (
                    <TD key={c.key} align={c.align}>
                      {formatValue(row[c.key], c.format)}
                    </TD>
                  ))}
                </TR>
              ))}
            </TBody>
            {Object.keys(data.totals).length > 0 && (
              <TotalsRow>
                <tr>
                  {data.columns.map((c, i) => (
                    <TD key={c.key} align={c.align}>
                      {i === 0 ? 'Total' : formatValue(data.totals[c.key], c.format)}
                    </TD>
                  ))}
                </tr>
              </TotalsRow>
            )}
          </Table>
        )}
      </Card>
    </div>
  );
}
