import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '../api/client';

interface AtlStatus {
  last_refreshed_at: string | null;
  counts: { active: number; inactive: number; unknown: number };
}

export default function AtlImportPage() {
  const [status, setStatus] = useState<AtlStatus | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function loadStatus() {
    try {
      const { data } = await apiClient.get<AtlStatus>('/customers-atl/status');
      setStatus(data);
    } catch {
      setError('Could not load ATL status - your role may not have access to this screen.');
    }
  }

  useEffect(() => {
    loadStatus();
  }, []);

  async function handleImport() {
    if (!file) return;
    setImporting(true);
    setError(null);
    setResult(null);

    const formData = new FormData();
    formData.append('file', file);

    try {
      const { data } = await apiClient.post('/customers-atl/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setResult(`Matched ${data.matched} customer(s), updated ${data.updated}, skipped ${data.skipped}.`);
      setFile(null);
      await loadStatus();
    } catch (err: unknown) {
      const data = (err as { response?: { data?: { errors?: string[]; message?: string } } }).response?.data;
      setError(data?.errors?.[0] ?? data?.message ?? 'Import failed.');
    } finally {
      setImporting(false);
    }
  }

  return (
    <div className="min-h-full p-6">
      <header className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-white">FBR Active Taxpayer List</h1>
          <p className="text-xs text-slate-400">Bulk-refresh ATL status for saved B2B customers</p>
        </div>
        <Link to="/checkout" className="text-sm text-sky-400 hover:text-sky-300">
          &larr; Back to checkout
        </Link>
      </header>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section className="rounded-lg bg-slate-800 p-4 lg:col-span-2">
          <h2 className="mb-3 text-sm font-medium text-slate-300">Import ATL export (CSV)</h2>
          <p className="mb-3 text-xs text-slate-500">
            Download FBR's Active Taxpayer List and save/export it as CSV. This only refreshes atl_status
            for customers already saved here (matched by NTN) - it never creates new customer records.
          </p>
          <input
            type="file"
            accept=".csv,.txt"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="mb-3 block w-full text-sm text-slate-300"
          />
          <button
            onClick={handleImport}
            disabled={!file || importing}
            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:opacity-50"
          >
            {importing ? 'Importing…' : 'Import'}
          </button>

          {result && <p className="mt-3 text-sm text-emerald-400">{result}</p>}
          {error && <p className="mt-3 text-sm text-red-400">{error}</p>}
        </section>

        <aside className="rounded-lg bg-slate-800 p-4">
          <h2 className="mb-3 text-sm font-medium text-slate-300">Current status</h2>
          {status ? (
            <div className="space-y-2 text-sm text-slate-300">
              <div className="flex justify-between">
                <span>Last refreshed</span>
                <span>{status.last_refreshed_at ? new Date(status.last_refreshed_at).toLocaleString() : 'Never'}</span>
              </div>
              <div className="flex justify-between text-emerald-400">
                <span>Active</span>
                <span>{status.counts.active}</span>
              </div>
              <div className="flex justify-between text-red-400">
                <span>Inactive</span>
                <span>{status.counts.inactive}</span>
              </div>
              <div className="flex justify-between text-slate-500">
                <span>Unknown</span>
                <span>{status.counts.unknown}</span>
              </div>
            </div>
          ) : (
            <p className="text-sm text-slate-500">Loading…</p>
          )}
        </aside>
      </div>
    </div>
  );
}
