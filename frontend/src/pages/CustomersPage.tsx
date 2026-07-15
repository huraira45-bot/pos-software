import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiClient } from '../api/client';
import { useAuthStore } from '../stores/authStore';
import { useCartStore } from '../stores/cartStore';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  Input,
  LoadingState,
  PageHeader,
  Select,
  TextArea,
  Table,
  THead,
  TH,
  TBody,
  TR,
  TD,
} from '../components/ui';
import type { Customer, Invoice } from '../types';

interface PaginatedResponse<T> {
  data: T[];
}

interface CustomerForm {
  id: number | null;
  name: string;
  company_name: string;
  contact_person: string;
  phone: string;
  email: string;
  ntn: string;
  cnic: string;
  strn: string;
  address: string;
  billing_address: string;
  shipping_address: string;
  customer_type: 'walk_in' | 'b2b';
  payment_terms_days: string;
  credit_limit: string;
  opening_balance: string;
  price_level: 'retail' | 'wholesale' | 'custom';
}

const emptyForm: CustomerForm = {
  id: null,
  name: '',
  company_name: '',
  contact_person: '',
  phone: '',
  email: '',
  ntn: '',
  cnic: '',
  strn: '',
  address: '',
  billing_address: '',
  shipping_address: '',
  customer_type: 'b2b',
  payment_terms_days: '0',
  credit_limit: '0.00',
  opening_balance: '0.00',
  price_level: 'retail',
};

export default function CustomersPage() {
  const session = useAuthStore((s) => s.session);
  const canManage = session?.user.permissions?.includes('pos.customer-manage') ?? false;
  const setCartCustomer = useCartStore((s) => s.setCustomer);
  const addLine = useCartStore((s) => s.addLine);
  const navigate = useNavigate();

  const [query, setQuery] = useState('');
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [selected, setSelected] = useState<Customer | null>(null);
  const [sales, setSales] = useState<Invoice[]>([]);
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [form, setForm] = useState<CustomerForm>(emptyForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const summary = selected?.sales_summary;
  const selectedDisplayName = selected ? customerDisplayName(selected) : '';

  useEffect(() => {
    const handle = window.setTimeout(() => {
      loadCustomers(query);
    }, 200);

    return () => window.clearTimeout(handle);
  }, [query]);

  async function loadCustomers(search: string) {
    setLoadingList(true);
    try {
      const { data } = await apiClient.get<Customer[]>('/customers', {
        params: { search, limit: 100 },
      });
      setCustomers(data);

      if (!selected && data.length > 0) {
        await selectCustomer(data[0].id);
      }
    } finally {
      setLoadingList(false);
    }
  }

  async function selectCustomer(id: number) {
    setLoadingDetail(true);
    setError(null);

    try {
      const [{ data: customer }, { data: salePage }] = await Promise.all([
        apiClient.get<Customer>(`/customers/${id}`),
        apiClient.get<PaginatedResponse<Invoice>>(`/customers/${id}/sales`, {
          params: { per_page: 50 },
        }),
      ]);
      setSelected(customer);
      setSales(salePage.data);
      setForm(customerToForm(customer));
    } catch (err: unknown) {
      const message =
        (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Could not load customer details.';
      setError(message);
    } finally {
      setLoadingDetail(false);
    }
  }

  function startNew(type: 'walk_in' | 'b2b' = 'b2b') {
    setForm({ ...emptyForm, customer_type: type });
    setSelected(null);
    setSales([]);
    setError(null);
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (form.id && !canManage) return;

    setSaving(true);
    setError(null);

    const payload = {
      name: form.name,
      company_name: nullable(form.company_name),
      contact_person: nullable(form.contact_person),
      phone: nullable(form.phone),
      email: nullable(form.email),
      ntn: nullable(form.ntn),
      cnic: nullable(form.cnic),
      strn: nullable(form.strn),
      address: nullable(form.address),
      billing_address: nullable(form.billing_address),
      shipping_address: nullable(form.shipping_address),
      customer_type: form.customer_type,
      payment_terms_days: Number(form.payment_terms_days || 0),
      credit_limit: form.credit_limit || '0.00',
      opening_balance: form.opening_balance || '0.00',
      price_level: form.price_level,
    };

    try {
      const { data } = form.id
        ? await apiClient.put<Customer>(`/customers/${form.id}`, payload)
        : await apiClient.post<Customer>('/customers', payload);

      await loadCustomers(query);
      await selectCustomer(data.id);
    } catch (err: unknown) {
      const response = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response;
      const firstError = response?.data?.errors ? Object.values(response.data.errors)[0]?.[0] : null;
      setError(firstError ?? response?.data?.message ?? 'Could not save customer.');
    } finally {
      setSaving(false);
    }
  }

  function loadSaleIntoCart(sale: Invoice) {
    if (!selected) return;

    setCartCustomer(selected);
    sale.items
      .filter((item) => item.product_id !== null)
      .forEach((item) => {
        addLine({
          product_id: item.product_id!,
          variant_id: item.product_variant_id ?? undefined,
          item_code: item.item_code,
          name: item.item_name,
          quantity: parseFloat(item.quantity),
          unit_price_excl_tax: item.unit_price_excl_tax,
          tax_rate: item.tax_rate,
          line_discount: item.discount,
          further_tax: item.further_tax,
        });
      });
    navigate('/checkout');
  }

  const canSave = useMemo(() => {
    if (!form.name.trim()) return false;
    if (form.id && !canManage) return false;
    return true;
  }, [canManage, form.id, form.name]);

  return (
    <div>
      <PageHeader
        title="Customers"
        subtitle="B2B profiles, credit terms, and previous sales"
        actions={
          <>
            <Button variant="secondary" size="sm" onClick={() => startNew('b2b')}>
              New B2B customer
            </Button>
            <Button variant="secondary" size="sm" onClick={() => startNew('walk_in')}>
              New individual
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-[320px_minmax(0,1fr)_380px]">
        <Card className="p-4">
          <label className="mb-2 block text-xs font-medium text-ink-muted">Search customers</label>
          <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Name, company, phone, NTN, STRN" />

          <div className="mt-4 max-h-[70vh] space-y-2 overflow-y-auto pr-1">
            {loadingList ? (
              <LoadingState />
            ) : (
              customers.map((customer) => {
                const active = selected?.id === customer.id;
                return (
                  <button
                    key={customer.id}
                    onClick={() => selectCustomer(customer.id)}
                    className={`w-full rounded-md border px-3 py-2 text-left text-sm ${
                      active
                        ? 'border-primary-500 bg-primary-50 text-ink'
                        : 'border-border bg-surface text-ink-muted hover:bg-surface-hover'
                    }`}
                  >
                    <span className="block font-medium text-ink">{customerDisplayName(customer)}</span>
                    <span className="mt-1 block text-xs text-ink-faint">
                      {customer.customer_type === 'b2b' ? 'B2B' : 'Individual'}
                      {customer.phone ? ` - ${customer.phone}` : ''}
                    </span>
                  </button>
                );
              })
            )}
            {!loadingList && customers.length === 0 && <EmptyState title="No customers found" />}
          </div>
        </Card>

        <section className="space-y-4">
          <Card className="p-4">
            {loadingDetail ? (
              <LoadingState />
            ) : selected ? (
              <div className="space-y-4">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="text-lg font-semibold text-ink">{selectedDisplayName}</h2>
                      <Badge variant={selected.customer_type === 'b2b' ? 'primary' : 'neutral'}>
                        {selected.customer_type === 'b2b' ? 'B2B' : 'Individual'}
                      </Badge>
                    </div>
                    <p className="mt-1 text-sm text-ink-muted">
                      {selected.contact_person || selected.name}
                      {selected.phone ? ` - ${selected.phone}` : ''}
                      {selected.email ? ` - ${selected.email}` : ''}
                    </p>
                  </div>
                  <Button variant="secondary" size="sm" onClick={() => setCartCustomer(selected)}>
                    Attach to checkout
                  </Button>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                  <SummaryTile label="Lifetime sales" value={money(summary?.total_sales_amount)} />
                  <SummaryTile label="Invoices" value={String(summary?.sales_count ?? 0)} />
                  <SummaryTile label="Available credit" value={money(summary?.available_credit)} />
                  <SummaryTile label="Last sale" value={summary?.last_sale_at ? dateOnly(summary.last_sale_at) : 'Never'} />
                </div>

                <div className="grid grid-cols-1 gap-4 text-sm lg:grid-cols-2">
                  <InfoGroup
                    title="Profile"
                    rows={[
                      ['Registered name', selected.name],
                      ['Company', selected.company_name],
                      ['Contact person', selected.contact_person],
                      ['Phone', selected.phone],
                      ['Email', selected.email],
                    ]}
                  />
                  <InfoGroup
                    title="Tax and account"
                    rows={[
                      ['NTN', selected.ntn_formatted ?? selected.ntn],
                      ['CNIC', selected.cnic_formatted ?? selected.cnic],
                      ['STRN', selected.strn],
                      ['Price level', selected.price_level],
                      ['Payment terms', `${selected.payment_terms_days ?? 0} day(s)`],
                      ['Credit limit', money(selected.credit_limit)],
                      ['Opening balance', money(selected.opening_balance)],
                    ]}
                  />
                  <InfoGroup
                    title="Billing"
                    rows={[
                      ['Address', selected.billing_address ?? selected.address],
                      ['Legacy address', selected.address],
                    ]}
                  />
                  <InfoGroup
                    title="Shipping"
                    rows={[['Address', selected.shipping_address ?? selected.billing_address ?? selected.address]]}
                  />
                </div>
              </div>
            ) : (
              <EmptyState title="No customer selected" description="Select a customer or create a new one." />
            )}
          </Card>

          <Card className="p-4">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-sm font-medium text-ink">Previous sales</h2>
              <span className="text-xs text-ink-faint">{sales.length} shown</span>
            </div>

            <Table>
              <THead>
                <TH>Date</TH>
                <TH>USIN</TH>
                <TH>Items</TH>
                <TH align="right">Tax</TH>
                <TH align="right">Total</TH>
                <TH></TH>
              </THead>
              <TBody>
                {sales.map((sale) => (
                  <TR key={sale.id}>
                    <TD className="align-top text-ink-muted">{dateTime(sale.sold_at)}</TD>
                    <TD className="align-top">{sale.usin}</TD>
                    <TD className="align-top">
                      <div className="max-w-md space-y-1">
                        {sale.items.slice(0, 3).map((item) => (
                          <p key={item.id} className="text-xs text-ink-faint">
                            {item.item_name} x {parseFloat(item.quantity).toLocaleString()}
                          </p>
                        ))}
                        {sale.items.length > 3 && (
                          <p className="text-xs text-ink-faint">+ {sale.items.length - 3} more item(s)</p>
                        )}
                      </div>
                    </TD>
                    <TD align="right" className="align-top">{money(sale.total_tax_charged)}</TD>
                    <TD align="right" className="align-top font-medium">{money(sale.total_bill_amount)}</TD>
                    <TD align="right" className="align-top">
                      <button
                        onClick={() => loadSaleIntoCart(sale)}
                        disabled={sale.items.every((item) => item.product_id === null)}
                        className="text-xs font-medium text-primary-600 hover:text-primary-700 disabled:text-ink-faint"
                      >
                        Add items
                      </button>
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
            {sales.length === 0 && <EmptyState title="No previous sales for this customer." />}
          </Card>
        </section>

        <Card className="p-4">
          <div className="mb-3">
            <h2 className="text-sm font-medium text-ink">
              {form.id ? `Edit ${customerDisplayNameFromForm(form)}` : 'Add customer'}
            </h2>
            <p className="mt-1 text-xs text-ink-faint">
              Cashiers can create. Managers and admins can edit saved profiles.
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-3">
            <Select
              label="Customer type"
              value={form.customer_type}
              onChange={(e) => setForm({ ...form, customer_type: e.target.value as 'walk_in' | 'b2b' })}
            >
              <option value="b2b">B2B company</option>
              <option value="walk_in">Individual</option>
            </Select>

            <Input label="Registered name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            {form.customer_type === 'b2b' && (
              <>
                <Input label="Company name" value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} />
                <Input label="Contact person" value={form.contact_person} onChange={(e) => setForm({ ...form, contact_person: e.target.value })} />
              </>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
              <Input label="Phone" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
              <Input label="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
              <Input label="NTN" value={form.ntn} onChange={(e) => setForm({ ...form, ntn: e.target.value })} />
              <Input label="CNIC" value={form.cnic} onChange={(e) => setForm({ ...form, cnic: e.target.value })} />
              <Input label="STRN" value={form.strn} onChange={(e) => setForm({ ...form, strn: e.target.value })} />
            </div>

            <TextArea label="Address" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} rows={2} />
            {form.customer_type === 'b2b' && (
              <>
                <TextArea label="Billing address" value={form.billing_address} onChange={(e) => setForm({ ...form, billing_address: e.target.value })} rows={2} />
                <TextArea label="Shipping address" value={form.shipping_address} onChange={(e) => setForm({ ...form, shipping_address: e.target.value })} rows={2} />

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
                  <Input
                    label="Payment terms days"
                    type="number"
                    value={form.payment_terms_days}
                    onChange={(e) => setForm({ ...form, payment_terms_days: e.target.value })}
                  />
                  <Input
                    label="Credit limit"
                    type="number"
                    value={form.credit_limit}
                    onChange={(e) => setForm({ ...form, credit_limit: e.target.value })}
                  />
                  <Input
                    label="Opening balance"
                    type="number"
                    value={form.opening_balance}
                    onChange={(e) => setForm({ ...form, opening_balance: e.target.value })}
                  />
                </div>

                <Select
                  label="Price level"
                  value={form.price_level}
                  onChange={(e) => setForm({ ...form, price_level: e.target.value as CustomerForm['price_level'] })}
                >
                  <option value="retail">Retail</option>
                  <option value="wholesale">Wholesale</option>
                  <option value="custom">Custom</option>
                </Select>
              </>
            )}

            {error && <p className="rounded-md border border-transparent bg-danger-bg px-3 py-2 text-sm text-danger">{error}</p>}

            <Button type="submit" variant="primary" disabled={!canSave} loading={saving} className="w-full py-2">
              {saving ? 'Saving...' : form.id ? 'Save changes' : 'Create customer'}
            </Button>
          </form>
        </Card>
      </div>
    </div>
  );
}

function customerToForm(customer: Customer): CustomerForm {
  return {
    id: customer.id,
    name: customer.name,
    company_name: customer.company_name ?? '',
    contact_person: customer.contact_person ?? '',
    phone: customer.phone ?? '',
    email: customer.email ?? '',
    ntn: customer.ntn ?? '',
    cnic: customer.cnic ?? '',
    strn: customer.strn ?? '',
    address: customer.address ?? '',
    billing_address: customer.billing_address ?? '',
    shipping_address: customer.shipping_address ?? '',
    customer_type: customer.customer_type,
    payment_terms_days: String(customer.payment_terms_days ?? 0),
    credit_limit: customer.credit_limit ?? '0.00',
    opening_balance: customer.opening_balance ?? '0.00',
    price_level: customer.price_level ?? 'retail',
  };
}

function nullable(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function customerDisplayName(customer: Customer): string {
  return customer.customer_type === 'b2b' && customer.company_name ? customer.company_name : customer.name;
}

function customerDisplayNameFromForm(form: CustomerForm): string {
  return form.customer_type === 'b2b' && form.company_name ? form.company_name : form.name || 'customer';
}

function money(value?: string | null): string {
  const amount = Number(value ?? 0);
  return `Rs.${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function dateOnly(value: string): string {
  return new Date(value).toLocaleDateString();
}

function dateTime(value: string): string {
  return new Date(value).toLocaleString();
}

function SummaryTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border border-border bg-canvas px-3 py-2">
      <p className="text-xs text-ink-faint">{label}</p>
      <p className="mt-1 text-sm font-semibold text-ink">{value}</p>
    </div>
  );
}

function InfoGroup({ title, rows }: { title: string; rows: Array<[string, string | number | null | undefined]> }) {
  return (
    <div className="rounded-md border border-border bg-canvas p-3">
      <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-ink-faint">{title}</h3>
      <dl className="space-y-2">
        {rows.map(([label, value]) => (
          <div key={label} className="grid grid-cols-[120px_minmax(0,1fr)] gap-3">
            <dt className="text-xs text-ink-faint">{label}</dt>
            <dd className="break-words text-sm text-ink">{value || '-'}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
