import { useEffect, useState } from 'react';
import { apiClient } from '../api/client';
import { Button, Card, EmptyState, ErrorState, Input, LoadingState, PageHeader } from '../components/ui';
import type { UsinType } from '../types';

interface Terminal {
  id: number;
  branch_id: number;
  code: string;
  name: string;
  fbr_pos_id: number;
}

interface UsinCounterRow {
  usin_type: UsinType;
  last_value: number;
  next_usin: string;
}

interface RowState {
  startFrom: string;
  saving: boolean;
  error: string | null;
  needsForce: boolean;
}

function rowKey(terminalId: number, usinType: UsinType): string {
  return `${terminalId}-${usinType}`;
}

/** Lets ops set "the next SIR/SS invoice on this till should be number X" per
 *  terminal - the counter itself still only ever increments transactionally
 *  via UsinGenerator during a real sale; this just sets where it starts from. */
export default function UsinSettingsPage() {
  const [terminals, setTerminals] = useState<Terminal[]>([]);
  const [counters, setCounters] = useState<Record<number, UsinCounterRow[]>>({});
  const [rowState, setRowState] = useState<Record<string, RowState>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const { data: terminalList } = await apiClient.get<Terminal[]>('/terminals');
      setTerminals(terminalList);

      const entries = await Promise.all(
        terminalList.map(async (t) => {
          const { data } = await apiClient.get<UsinCounterRow[]>(`/terminals/${t.id}/usin-counters`);
          return [t.id, data] as const;
        }),
      );
      setCounters(Object.fromEntries(entries));
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Could not load terminals/counters.';
      setError(message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  function getRowState(key: string): RowState {
    return rowState[key] ?? { startFrom: '', saving: false, error: null, needsForce: false };
  }

  function setStartFrom(key: string, value: string) {
    setRowState((prev) => ({ ...prev, [key]: { ...getRowState(key), startFrom: value, error: null } }));
  }

  async function save(terminalId: number, usinType: UsinType, force = false) {
    const key = rowKey(terminalId, usinType);
    const current = getRowState(key);
    const startFrom = parseInt(current.startFrom, 10);
    if (!startFrom || startFrom < 1) {
      setRowState((prev) => ({ ...prev, [key]: { ...current, error: 'Enter a valid number.' } }));
      return;
    }

    setRowState((prev) => ({ ...prev, [key]: { ...current, saving: true, error: null } }));

    try {
      await apiClient.put(`/terminals/${terminalId}/usin-counters/${usinType}`, { start_from: startFrom, force });
      setRowState((prev) => ({ ...prev, [key]: { startFrom: '', saving: false, error: null, needsForce: false } }));
      const { data } = await apiClient.get<UsinCounterRow[]>(`/terminals/${terminalId}/usin-counters`);
      setCounters((prev) => ({ ...prev, [terminalId]: data }));
    } catch (err: unknown) {
      const response = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response;
      const message = response?.data?.errors?.start_from?.[0] ?? response?.data?.message ?? 'Could not update the counter.';
      setRowState((prev) => ({ ...prev, [key]: { ...current, saving: false, error: message, needsForce: !force } }));
    }
  }

  return (
    <div>
      <PageHeader title="USIN Counters" subtitle="Set the next SIR/SS invoice number for each till." />

      <Card className="p-4">
        {loading ? (
          <LoadingState />
        ) : error ? (
          <ErrorState message={error} onRetry={load} />
        ) : terminals.length === 0 ? (
          <EmptyState title="No terminals configured." />
        ) : (
          <div className="space-y-6">
            {terminals.map((terminal) => (
              <div key={terminal.id} className="border-b border-border pb-5 last:border-0 last:pb-0">
                <p className="mb-3 text-sm font-medium text-ink">
                  {terminal.name} <span className="text-ink-faint">({terminal.code}, POS ID {terminal.fbr_pos_id})</span>
                </p>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {(counters[terminal.id] ?? []).map((counter) => {
                    const key = rowKey(terminal.id, counter.usin_type);
                    const state = getRowState(key);
                    return (
                      <div key={key} className="rounded-card border border-border p-3">
                        <div className="mb-2 flex items-center justify-between">
                          <span className="text-xs font-medium uppercase tracking-wide text-ink-faint">{counter.usin_type}</span>
                          <span className="text-xs text-ink-muted">Next: {counter.next_usin}</span>
                        </div>
                        <div className="flex items-end gap-2">
                          <div className="flex-1">
                            <Input
                              label="Set next number to"
                              type="number"
                              min={1}
                              placeholder={String(counter.last_value + 1)}
                              value={state.startFrom}
                              onChange={(e) => setStartFrom(key, e.target.value)}
                            />
                          </div>
                          <Button variant="secondary" size="sm" loading={state.saving} onClick={() => save(terminal.id, counter.usin_type)}>
                            Save
                          </Button>
                        </div>
                        {state.error && (
                          <div className="mt-2 text-xs text-danger">
                            {state.error}
                            {state.needsForce && (
                              <Button
                                variant="danger"
                                size="sm"
                                className="ml-2"
                                loading={state.saving}
                                onClick={() => save(terminal.id, counter.usin_type, true)}
                              >
                                Override anyway
                              </Button>
                            )}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
