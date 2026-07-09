import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import type { Product } from '../types';

interface ProductForm {
  id: number | null;
  item_code: string;
  barcode: string;
  name: string;
  unit: string;
  pct_code: string;
  tax_rate: string;
  price_excl_tax: string;
  track_stock: boolean;
}

const emptyForm: ProductForm = {
  id: null,
  item_code: '',
  barcode: '',
  name: '',
  unit: 'pcs',
  pct_code: '',
  tax_rate: '18.00',
  price_excl_tax: '',
  track_stock: true,
};

export default function ProductsPage() {
  const session = useAuthStore((s) => s.session);
  const canManage = session?.user.permissions?.includes('pos.product-manage') ?? false;

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<ProductForm>(emptyForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function loadProducts() {
    setLoading(true);
    const { data } = await apiClient.get<{ data: Product[] }>('/products', {
      params: { per_page: 100, active_only: false },
    });
    setProducts(data.data);
    setLoading(false);
  }

  useEffect(() => {
    loadProducts();
  }, []);

  function startEdit(product: Product) {
    setForm({
      id: product.id,
      item_code: product.item_code,
      barcode: product.barcode ?? '',
      name: product.name,
      unit: product.unit,
      pct_code: product.pct_code,
      tax_rate: product.tax_rate,
      price_excl_tax: product.price_excl_tax,
      track_stock: product.track_stock,
    });
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    const payload = {
      item_code: form.item_code,
      barcode: form.barcode || null,
      name: form.name,
      unit: form.unit,
      pct_code: form.pct_code,
      tax_rate: form.tax_rate,
      price_excl_tax: form.price_excl_tax,
      track_stock: form.track_stock,
    };

    try {
      if (form.id) {
        await apiClient.put(`/products/${form.id}`, payload);
      } else {
        await apiClient.post('/products', payload);
      }
      setForm(emptyForm);
      await loadProducts();
    } catch (err: unknown) {
      const message =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Could not save product.';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="min-h-full p-6">
      <header className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-white">Products</h1>
          <p className="text-xs text-slate-400">Catalog management</p>
        </div>
        <Link to="/checkout" className="text-sm text-sky-400 hover:text-sky-300">
          &larr; Back to checkout
        </Link>
      </header>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section className="rounded-lg bg-slate-800 p-4 lg:col-span-2">
          {loading ? (
            <p className="text-slate-500">Loading…</p>
          ) : (
            <table className="w-full text-sm text-slate-200">
              <thead>
                <tr className="border-b border-slate-700 text-left text-slate-400">
                  <th className="py-2">Code</th>
                  <th className="py-2">Name</th>
                  <th className="py-2">PCT Code</th>
                  <th className="py-2 text-right">Tax%</th>
                  <th className="py-2 text-right">Price</th>
                  <th className="py-2"></th>
                </tr>
              </thead>
              <tbody>
                {products.map((p) => (
                  <tr key={p.id} className="border-b border-slate-800">
                    <td className="py-2">{p.item_code}</td>
                    <td className="py-2">{p.name}</td>
                    <td className="py-2">{p.pct_code}</td>
                    <td className="py-2 text-right">{p.tax_rate}%</td>
                    <td className="py-2 text-right">Rs.{p.price_excl_tax}</td>
                    <td className="py-2 text-right">
                      {canManage && (
                        <button
                          onClick={() => startEdit(p)}
                          className="text-xs text-sky-400 hover:text-sky-300"
                        >
                          Edit
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {products.length === 0 && (
                  <tr>
                    <td colSpan={6} className="py-4 text-center text-slate-500">
                      No products yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </section>

        <aside className="rounded-lg bg-slate-800 p-4">
          {!canManage ? (
            <p className="text-sm text-slate-500">
              Your role doesn't include product management. Ask an admin or manager to add or edit items.
            </p>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-3">
              <h2 className="text-sm font-medium text-slate-300">
                {form.id ? `Edit: ${form.item_code}` : 'Add product'}
              </h2>

              <Field label="Item code" value={form.item_code} onChange={(v) => setForm({ ...form, item_code: v })} required />
              <Field label="Name" value={form.name} onChange={(v) => setForm({ ...form, name: v })} required />
              <Field label="Barcode (optional)" value={form.barcode} onChange={(v) => setForm({ ...form, barcode: v })} />
              <Field label="Unit" value={form.unit} onChange={(v) => setForm({ ...form, unit: v })} required />
              <Field
                label="PCT Code (Pakistan Customs Tariff)"
                value={form.pct_code}
                onChange={(v) => setForm({ ...form, pct_code: v })}
                required
              />
              <Field
                label="Tax rate (%)"
                type="number"
                value={form.tax_rate}
                onChange={(v) => setForm({ ...form, tax_rate: v })}
                required
              />
              <Field
                label="Price (excl. tax)"
                type="number"
                value={form.price_excl_tax}
                onChange={(v) => setForm({ ...form, price_excl_tax: v })}
                required
              />

              <label className="flex items-center gap-2 text-sm text-slate-300">
                <input
                  type="checkbox"
                  checked={form.track_stock}
                  onChange={(e) => setForm({ ...form, track_stock: e.target.checked })}
                />
                Track stock
              </label>

              {error && <p className="text-sm text-red-400">{error}</p>}

              <div className="flex gap-2">
                <button
                  type="submit"
                  disabled={saving}
                  className="flex-1 rounded-md bg-emerald-600 py-2 font-medium text-white hover:bg-emerald-500 disabled:opacity-50"
                >
                  {saving ? 'Saving…' : form.id ? 'Save changes' : 'Add product'}
                </button>
                {form.id && (
                  <button
                    type="button"
                    onClick={() => setForm(emptyForm)}
                    className="rounded-md border border-slate-600 px-3 text-sm text-slate-300 hover:bg-slate-700"
                  >
                    Cancel
                  </button>
                )}
              </div>
            </form>
          )}
        </aside>
      </div>
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  type = 'text',
  required = false,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  required?: boolean;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs text-slate-400">{label}</label>
      <input
        type={type}
        step={type === 'number' ? '0.01' : undefined}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        required={required}
        className="w-full rounded border border-slate-700 bg-slate-900 px-2 py-1.5 text-sm text-white outline-none focus:border-sky-500"
      />
    </div>
  );
}
