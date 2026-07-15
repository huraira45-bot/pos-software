import { useEffect, useState } from 'react';
import { apiClient } from '../../api/client';
import { Button, Input, Select } from '../ui';
import type { ReportFilterConfig } from '../../reports/registry';

export interface FilterValues {
  branch_id?: string;
  from?: string;
  to?: string;
  customer_id?: string;
  product_id?: string;
  category_id?: string;
}

interface Option {
  id: number;
  label: string;
}

interface RawBranch { id: number; name: string }
interface RawCategory { id: number; name: string }
interface RawProduct { id: number; item_code: string; name: string }
interface RawCustomer { id: number; name: string; company_name: string | null; customer_type: 'walk_in' | 'b2b' }

/** Shared filter row for both the report suite (ReportView) and Sales History -
 *  which controls render is driven entirely by which flags are set on `config`. */
export default function FilterBar({
  config,
  values,
  onChange,
  onApply,
  loading = false,
}: {
  config: ReportFilterConfig;
  values: FilterValues;
  onChange: (values: FilterValues) => void;
  onApply: () => void;
  loading?: boolean;
}) {
  const [branches, setBranches] = useState<Option[]>([]);
  const [categories, setCategories] = useState<Option[]>([]);
  const [products, setProducts] = useState<Option[]>([]);
  const [customers, setCustomers] = useState<Option[]>([]);

  useEffect(() => {
    if (config.branch) {
      apiClient
        .get<RawBranch[]>('/branches')
        .then(({ data }) => setBranches(data.map((b) => ({ id: b.id, label: b.name }))))
        .catch(() => setBranches([]));
    }
  }, [config.branch]);

  useEffect(() => {
    if (config.category) {
      apiClient
        .get<RawCategory[]>('/categories')
        .then(({ data }) => setCategories(data.map((c) => ({ id: c.id, label: c.name }))))
        .catch(() => setCategories([]));
    }
  }, [config.category]);

  useEffect(() => {
    if (config.product) {
      apiClient
        .get<{ data: RawProduct[] }>('/products', { params: { per_page: 200, active_only: false } })
        .then(({ data }) => setProducts(data.data.map((p) => ({ id: p.id, label: `${p.item_code} - ${p.name}` }))))
        .catch(() => setProducts([]));
    }
  }, [config.product]);

  useEffect(() => {
    if (config.customer) {
      apiClient
        .get<RawCustomer[]>('/customers', { params: { limit: 500 } })
        .then(({ data }) => {
          const filtered = config.customer === 'b2b' ? data.filter((c) => c.customer_type === 'b2b') : data;
          setCustomers(filtered.map((c) => ({ id: c.id, label: c.company_name || c.name })));
        })
        .catch(() => setCustomers([]));
    }
  }, [config.customer]);

  function set(patch: Partial<FilterValues>) {
    onChange({ ...values, ...patch });
  }

  return (
    <div className="mb-5 flex flex-wrap items-end gap-3">
      {config.branch && (
        <div className="w-44">
          <Select label="Branch" value={values.branch_id ?? ''} onChange={(e) => set({ branch_id: e.target.value || undefined })}>
            <option value="">All branches</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.label}
              </option>
            ))}
          </Select>
        </div>
      )}

      {config.dateRange && (
        <>
          <div className="w-40">
            <Input label="From" type="date" value={values.from ?? ''} onChange={(e) => set({ from: e.target.value || undefined })} />
          </div>
          <div className="w-40">
            <Input label="To" type="date" value={values.to ?? ''} onChange={(e) => set({ to: e.target.value || undefined })} />
          </div>
        </>
      )}

      {config.category && (
        <div className="w-48">
          <Select label="Category" value={values.category_id ?? ''} onChange={(e) => set({ category_id: e.target.value || undefined })}>
            <option value="">All categories</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.label}
              </option>
            ))}
          </Select>
        </div>
      )}

      {config.product && (
        <div className="w-56">
          <Select label="Product" value={values.product_id ?? ''} onChange={(e) => set({ product_id: e.target.value || undefined })}>
            <option value="">All products</option>
            {products.map((p) => (
              <option key={p.id} value={p.id}>
                {p.label}
              </option>
            ))}
          </Select>
        </div>
      )}

      {config.customer && (
        <div className="w-56">
          <Select
            label="Customer"
            required={config.customerRequired}
            value={values.customer_id ?? ''}
            onChange={(e) => set({ customer_id: e.target.value || undefined })}
          >
            <option value="">{config.customerRequired ? 'Select a customer…' : 'All customers'}</option>
            {customers.map((c) => (
              <option key={c.id} value={c.id}>
                {c.label}
              </option>
            ))}
          </Select>
        </div>
      )}

      <Button variant="primary" onClick={onApply} loading={loading}>
        Apply
      </Button>
    </div>
  );
}
