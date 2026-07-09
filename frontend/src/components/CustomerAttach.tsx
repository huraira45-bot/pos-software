import { useState, type FormEvent } from 'react';
import { apiClient } from '../api/client';
import { useCartStore } from '../stores/cartStore';
import type { Customer } from '../types';

const ATL_BADGE: Record<Customer['atl_status'], { label: string; className: string }> = {
  active: { label: 'ATL Active', className: 'bg-emerald-900 text-emerald-300' },
  inactive: { label: 'Not ATL Active', className: 'bg-red-900 text-red-300' },
  unknown: { label: 'ATL Unknown', className: 'bg-slate-700 text-slate-300' },
};

/**
 * Walk-in stays the zero-click default: this whole component collapses to a
 * single "+ Attach customer" link until someone clicks it. Search-or-quick-create
 * in one small panel, no separate page/navigation needed mid-sale.
 */
export default function CustomerAttach() {
  const customer = useCartStore((s) => s.customer);
  const setCustomer = useCartStore((s) => s.setCustomer);

  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<'search' | 'create'>('search');
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<Customer[]>([]);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [form, setForm] = useState({ name: '', phone: '', ntn: '', customer_type: 'walk_in' as 'walk_in' | 'b2b' });

  async function handleSearch(value: string) {
    setQuery(value);
    if (!value) {
      setResults([]);
      return;
    }
    // CustomerController::index is unpaginated, so (unlike /products) the
    // response is a flat array, not wrapped in { data: [...] }.
    const { data } = await apiClient.get<Customer[]>('/customers', { params: { search: value } });
    setResults(data);
  }

  function selectCustomer(c: Customer) {
    setCustomer(c);
    setOpen(false);
    setQuery('');
    setResults([]);
  }

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setCreating(true);
    setError(null);
    try {
      const { data } = await apiClient.post<Customer>('/customers', form);
      selectCustomer(data);
      setForm({ name: '', phone: '', ntn: '', customer_type: 'walk_in' });
    } catch (err: unknown) {
      const message =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Could not create customer.';
      setError(message);
    } finally {
      setCreating(false);
    }
  }

  if (customer) {
    const badge = ATL_BADGE[customer.atl_status];
    return (
      <div className="flex items-center justify-between rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm">
        <div>
          <span className="text-white">{customer.name}</span>
          {customer.customer_type === 'b2b' && (
            <span className={`ml-2 rounded px-1.5 py-0.5 text-xs ${badge.className}`}>{badge.label}</span>
          )}
        </div>
        <button onClick={() => setCustomer(null)} className="text-xs text-slate-400 hover:text-white">
          Remove
        </button>
      </div>
    );
  }

  if (!open) {
    return (
      <button onClick={() => setOpen(true)} className="text-sm text-sky-400 hover:text-sky-300">
        + Attach customer
      </button>
    );
  }

  return (
    <div className="rounded-md border border-slate-700 bg-slate-900 p-3">
      <div className="mb-2 flex gap-3 text-xs">
        <button
          onClick={() => setMode('search')}
          className={mode === 'search' ? 'text-sky-400' : 'text-slate-500'}
        >
          Search
        </button>
        <button
          onClick={() => setMode('create')}
          className={mode === 'create' ? 'text-sky-400' : 'text-slate-500'}
        >
          Quick create
        </button>
        <button onClick={() => setOpen(false)} className="ml-auto text-slate-500 hover:text-white">
          Cancel
        </button>
      </div>

      {mode === 'search' ? (
        <div>
          <input
            value={query}
            onChange={(e) => handleSearch(e.target.value)}
            placeholder="Search by phone, NTN, or name…"
            className="w-full rounded border border-slate-700 bg-slate-800 px-2 py-1.5 text-sm text-white"
            autoFocus
          />
          <ul className="mt-2 max-h-40 overflow-y-auto">
            {results.map((c) => (
              <li key={c.id}>
                <button
                  onClick={() => selectCustomer(c)}
                  className="w-full rounded px-2 py-1.5 text-left text-sm text-slate-200 hover:bg-slate-800"
                >
                  {c.name} {c.phone && <span className="text-slate-500">· {c.phone}</span>}
                </button>
              </li>
            ))}
            {query && results.length === 0 && (
              <li className="px-2 py-1.5 text-sm text-slate-500">No matches - try "Quick create".</li>
            )}
          </ul>
        </div>
      ) : (
        <form onSubmit={handleCreate} className="space-y-2">
          <input
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder="Name"
            required
            className="w-full rounded border border-slate-700 bg-slate-800 px-2 py-1.5 text-sm text-white"
          />
          <input
            value={form.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })}
            placeholder="Phone"
            className="w-full rounded border border-slate-700 bg-slate-800 px-2 py-1.5 text-sm text-white"
          />
          <div className="flex gap-2">
            <select
              value={form.customer_type}
              onChange={(e) => setForm({ ...form, customer_type: e.target.value as 'walk_in' | 'b2b' })}
              className="rounded border border-slate-700 bg-slate-800 px-2 py-1.5 text-sm text-white"
            >
              <option value="walk_in">Walk-in</option>
              <option value="b2b">B2B</option>
            </select>
            {form.customer_type === 'b2b' && (
              <input
                value={form.ntn}
                onChange={(e) => setForm({ ...form, ntn: e.target.value })}
                placeholder="NTN (7 digits)"
                className="flex-1 rounded border border-slate-700 bg-slate-800 px-2 py-1.5 text-sm text-white"
              />
            )}
          </div>
          {error && <p className="text-xs text-red-400">{error}</p>}
          <button
            type="submit"
            disabled={creating}
            className="w-full rounded bg-sky-600 py-1.5 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
          >
            {creating ? 'Creating…' : 'Create & attach'}
          </button>
        </form>
      )}
    </div>
  );
}
