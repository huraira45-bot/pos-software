import { useEffect, useState, type FormEvent } from 'react';
import { apiClient } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import {
  Button,
  Card,
  EmptyState,
  Input,
  LoadingState,
  PageHeader,
  Table,
  THead,
  TH,
  TBody,
  TR,
  TD,
} from '../components/ui';
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
    <div>
      <PageHeader title="Products" subtitle="Catalog management" />

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Card className="p-4 lg:col-span-2">
          {loading ? (
            <LoadingState />
          ) : (
            <Table>
              <THead>
                <TH>Code</TH>
                <TH>Name</TH>
                <TH>PCT Code</TH>
                <TH align="right">Tax%</TH>
                <TH align="right">Price</TH>
                <TH></TH>
              </THead>
              <TBody>
                {products.map((p) => (
                  <TR key={p.id}>
                    <TD>{p.item_code}</TD>
                    <TD>{p.name}</TD>
                    <TD>{p.pct_code}</TD>
                    <TD align="right">{p.tax_rate}%</TD>
                    <TD align="right">Rs.{p.price_excl_tax}</TD>
                    <TD align="right">
                      {canManage && (
                        <button onClick={() => startEdit(p)} className="text-xs font-medium text-primary-600 hover:text-primary-700">
                          Edit
                        </button>
                      )}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          )}
          {!loading && products.length === 0 && <EmptyState title="No products yet." />}
        </Card>

        <Card className="p-4">
          {!canManage ? (
            <p className="text-sm text-ink-muted">
              Your role doesn't include product management. Ask an admin or manager to add or edit items.
            </p>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-3">
              <h2 className="text-sm font-medium text-ink">{form.id ? `Edit: ${form.item_code}` : 'Add product'}</h2>

              <Input label="Item code" value={form.item_code} onChange={(e) => setForm({ ...form, item_code: e.target.value })} required />
              <Input label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
              <Input label="Barcode (optional)" value={form.barcode} onChange={(e) => setForm({ ...form, barcode: e.target.value })} />
              <Input label="Unit" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} required />
              <Input
                label="PCT Code (Pakistan Customs Tariff)"
                value={form.pct_code}
                onChange={(e) => setForm({ ...form, pct_code: e.target.value })}
                required
              />
              <Input
                label="Tax rate (%)"
                type="number"
                value={form.tax_rate}
                onChange={(e) => setForm({ ...form, tax_rate: e.target.value })}
                required
              />
              <Input
                label="Price (excl. tax)"
                type="number"
                value={form.price_excl_tax}
                onChange={(e) => setForm({ ...form, price_excl_tax: e.target.value })}
                required
              />

              <label className="flex items-center gap-2 text-sm text-ink-muted">
                <input
                  type="checkbox"
                  checked={form.track_stock}
                  onChange={(e) => setForm({ ...form, track_stock: e.target.checked })}
                />
                Track stock
              </label>

              {error && <p className="text-sm text-danger">{error}</p>}

              <div className="flex gap-2">
                <Button type="submit" variant="primary" loading={saving} className="flex-1 py-2">
                  {saving ? 'Saving…' : form.id ? 'Save changes' : 'Add product'}
                </Button>
                {form.id && (
                  <Button type="button" variant="secondary" onClick={() => setForm(emptyForm)}>
                    Cancel
                  </Button>
                )}
              </div>
            </form>
          )}
        </Card>
      </div>
    </div>
  );
}
