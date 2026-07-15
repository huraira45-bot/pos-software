import { useEffect, useRef, useState } from 'react';
import { apiClient } from '../api/client';
import { cacheProducts, findCachedProductByBarcode, getCachedProducts } from '../db/offlineDb';
import type { Product } from '../types';

interface Props {
  onSelect: (product: Product) => void;
}

/**
 * Barcode-first: a full barcode match (typical scanner input, ending in
 * Enter) adds the item immediately. Free text falls back to a name/code
 * search over the locally cached catalog, so this keeps working mid-sale
 * even if connectivity drops between refreshes.
 */
export default function ProductSearch({ onSelect }: Props) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<Product[]>([]);
  const [refreshedAt, setRefreshedAt] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    refreshCatalog();
    inputRef.current?.focus();
  }, []);

  async function refreshCatalog() {
    try {
      const { data } = await apiClient.get<{ data: Product[] }>('/products', {
        params: { per_page: 500, active_only: true },
      });
      await cacheProducts(data.data);
      setRefreshedAt(new Date().toLocaleTimeString());
    } catch {
      // Offline - the cached catalog from the last successful refresh is used instead.
    }
  }

  async function handleChange(value: string) {
    setQuery(value);
    if (!value) {
      setResults([]);
      return;
    }

    const exact = await findCachedProductByBarcode(value);
    if (exact) {
      onSelect(exact);
      setQuery('');
      setResults([]);
      return;
    }

    const all = await getCachedProducts();
    const needle = value.toLowerCase();
    setResults(
      all
        .filter(
          (p) =>
            p.name.toLowerCase().includes(needle) ||
            p.item_code.toLowerCase().includes(needle),
        )
        .slice(0, 8),
    );
  }

  function handleSelect(product: Product) {
    onSelect(product);
    setQuery('');
    setResults([]);
    inputRef.current?.focus();
  }

  return (
    <div className="relative">
      <input
        ref={inputRef}
        value={query}
        onChange={(e) => handleChange(e.target.value)}
        placeholder="Scan barcode or search item…"
        className="w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-ink outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
      />
      <p className="mt-1 text-xs text-ink-faint">
        {refreshedAt ? `Catalog synced ${refreshedAt}` : 'Using cached catalog (offline)'}
      </p>

      {results.length > 0 && (
        <ul className="absolute z-10 mt-1 w-full rounded-md border border-border bg-surface shadow-card">
          {results.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                onClick={() => handleSelect(p)}
                className="flex w-full justify-between px-3 py-2 text-left text-sm text-ink hover:bg-surface-hover"
              >
                <span>{p.name}</span>
                <span className="text-ink-faint">Rs.{p.price_excl_tax}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
