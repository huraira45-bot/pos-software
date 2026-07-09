import { useEffect, useState } from 'react';
import { apiClient } from '../api/client';
import { useTerminalStore } from '../stores/terminalStore';

interface TerminalOption {
  id: number;
  branch_id: number;
  code: string;
  name: string;
}

/** One-time-per-device setup: which registered terminal this till is. */
export default function TerminalPicker() {
  const setTerminal = useTerminalStore((s) => s.setTerminal);
  const [terminals, setTerminals] = useState<TerminalOption[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get<TerminalOption[]>('/terminals')
      .then((res) => setTerminals(res.data))
      .catch(() => setError('Could not load terminals - check your connection and try again.'));
  }, []);

  return (
    <div className="flex min-h-full items-center justify-center px-4">
      <div className="w-full max-w-sm rounded-xl bg-slate-800 p-8 shadow-xl">
        <h1 className="mb-1 text-xl font-semibold text-white">Select This Till's Terminal</h1>
        <p className="mb-6 text-sm text-slate-400">
          One-time setup for this device. Every sale rung up here will be numbered under this terminal.
        </p>

        {error && <p className="mb-4 text-sm text-red-400">{error}</p>}

        <div className="space-y-2">
          {terminals.map((t) => (
            <button
              key={t.id}
              onClick={() => setTerminal(t.id, t.branch_id)}
              className="w-full rounded-md border border-slate-600 bg-slate-900 px-4 py-3 text-left text-white hover:border-sky-500"
            >
              <div className="font-medium">{t.name}</div>
              <div className="text-xs text-slate-400">Code {t.code}</div>
            </button>
          ))}
          {terminals.length === 0 && !error && <p className="text-sm text-slate-500">Loading…</p>}
        </div>
      </div>
    </div>
  );
}
