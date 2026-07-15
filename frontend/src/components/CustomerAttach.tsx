import { useState, type FormEvent } from 'react';
import { apiClient } from '../api/client';
import { useCartStore } from '../stores/cartStore';
import { Badge, Input, Select } from './ui';
import type { Customer } from '../types';

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
    return (
      <div className="flex items-center justify-between rounded-md border border-border-strong bg-canvas px-3 py-2 text-sm">
        <div>
          <span className="text-ink">{customer.name}</span>
          {customer.customer_type === 'b2b' && (
            <Badge variant="primary" className="ml-2">B2B</Badge>
          )}
        </div>
        <button onClick={() => setCustomer(null)} className="text-xs text-ink-faint hover:text-ink">
          Remove
        </button>
      </div>
    );
  }

  if (!open) {
    return (
      <button onClick={() => setOpen(true)} className="text-sm font-medium text-primary-600 hover:text-primary-700">
        + Attach customer
      </button>
    );
  }

  return (
    <div className="rounded-md border border-border-strong bg-canvas p-3">
      <div className="mb-2 flex gap-3 text-xs">
        <button
          onClick={() => setMode('search')}
          className={mode === 'search' ? 'font-medium text-primary-600' : 'text-ink-faint'}
        >
          Search
        </button>
        <button
          onClick={() => setMode('create')}
          className={mode === 'create' ? 'font-medium text-primary-600' : 'text-ink-faint'}
        >
          Quick create
        </button>
        <button onClick={() => setOpen(false)} className="ml-auto text-ink-faint hover:text-ink">
          Cancel
        </button>
      </div>

      {mode === 'search' ? (
        <div>
          <Input value={query} onChange={(e) => handleSearch(e.target.value)} placeholder="Search by phone, NTN, or name…" autoFocus />
          <ul className="mt-2 max-h-40 overflow-y-auto">
            {results.map((c) => (
              <li key={c.id}>
                <button
                  onClick={() => selectCustomer(c)}
                  className="w-full rounded px-2 py-1.5 text-left text-sm text-ink hover:bg-surface-hover"
                >
                  {c.name} {c.phone && <span className="text-ink-faint">· {c.phone}</span>}
                </button>
              </li>
            ))}
            {query && results.length === 0 && (
              <li className="px-2 py-1.5 text-sm text-ink-faint">No matches - try "Quick create".</li>
            )}
          </ul>
        </div>
      ) : (
        <form onSubmit={handleCreate} className="space-y-2">
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Name" required />
          <Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="Phone" />
          <div className="flex gap-2">
            <Select
              value={form.customer_type}
              onChange={(e) => setForm({ ...form, customer_type: e.target.value as 'walk_in' | 'b2b' })}
              className="w-auto"
            >
              <option value="walk_in">Walk-in</option>
              <option value="b2b">B2B</option>
            </Select>
            {form.customer_type === 'b2b' && (
              <div className="flex-1">
                <Input value={form.ntn} onChange={(e) => setForm({ ...form, ntn: e.target.value })} placeholder="NTN (7 digits)" />
              </div>
            )}
          </div>
          {error && <p className="text-xs text-danger">{error}</p>}
          <button
            type="submit"
            disabled={creating}
            className="w-full rounded-md bg-primary-600 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
          >
            {creating ? 'Creating…' : 'Create & attach'}
          </button>
        </form>
      )}
    </div>
  );
}
