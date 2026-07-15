/** The one envelope shape every ReportingService method (except day-close,
 *  reconciliation, and b2b-invoices, which predate the report suite) returns -
 *  see backend/app/Services/Reporting/ReportingService.php. */

export interface ReportSummaryMetric {
  key: string;
  label: string;
  value: string;
  format: 'currency' | 'number' | 'datetime' | string;
}

export interface ReportColumn {
  key: string;
  label: string;
  align?: 'left' | 'right' | 'center';
  format?: 'currency' | 'number' | 'datetime' | string;
}

export interface ReportEnvelope {
  summary: ReportSummaryMetric[];
  columns: ReportColumn[];
  rows: Array<Record<string, string>>;
  totals: Record<string, string>;
  meta: {
    report: string;
    filters: Record<string, unknown>;
    generated_at: string;
    [key: string]: unknown;
  };
}
