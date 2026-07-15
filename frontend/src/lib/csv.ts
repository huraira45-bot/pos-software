import type { ReportEnvelope } from '../reports/types';

function csvCell(value: string): string {
  return /[",\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}

/** Client-side CSV Blob download from a report envelope - no backend export endpoint needed. */
export function downloadReportCsv(envelope: ReportEnvelope, filename: string): void {
  const header = envelope.columns.map((c) => csvCell(c.label)).join(',');
  const rows = envelope.rows.map((row) => envelope.columns.map((c) => csvCell(row[c.key] ?? '')).join(','));
  const lines = [header, ...rows];

  if (Object.keys(envelope.totals).length > 0) {
    lines.push(
      envelope.columns
        .map((c, i) => (i === 0 ? 'Total' : csvCell(envelope.totals[c.key] ?? '')))
        .join(','),
    );
  }

  const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}
