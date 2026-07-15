import { Link, useParams } from 'react-router-dom';
import { PageHeader } from '../components/ui';
import ReportView from '../components/reports/ReportView';
import { REPORTS, REPORT_GROUPS } from '../reports/registry';

/** /reports shows the catalog; /reports/:reportKey renders the generic
 *  ReportView for that entry. One page, not 14, per the registry design. */
export default function ReportsPage() {
  const { reportKey } = useParams<{ reportKey: string }>();

  if (reportKey) {
    return (
      <div>
        <Link to="/reports" className="mb-4 inline-block text-sm font-medium text-primary-600 hover:text-primary-700">
          ← All reports
        </Link>
        <ReportView />
      </div>
    );
  }

  return (
    <div>
      <PageHeader title="Reports" subtitle="Sales, financial, inventory, and B2B reporting" />

      <div className="space-y-6">
        {REPORT_GROUPS.map((group) => {
          const items = REPORTS.filter((r) => r.group === group);
          if (items.length === 0) return null;

          return (
            <div key={group}>
              <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-faint">{group}</h2>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {items.map((r) => (
                  <Link
                    key={r.key}
                    to={`/reports/${r.key}`}
                    className="rounded-card border border-border bg-surface p-4 shadow-card transition-colors hover:border-primary-300 hover:bg-primary-50"
                  >
                    <p className="text-sm font-medium text-ink">{r.title}</p>
                    <p className="mt-1 text-xs text-ink-faint">{r.description}</p>
                  </Link>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
