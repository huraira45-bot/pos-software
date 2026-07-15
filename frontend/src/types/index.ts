export interface User {
  id: number;
  name: string;
  email: string;
  branch_id: number | null;
  roles: string[];
  permissions: string[];
}

export interface AuthSession {
  token: string;
  user: User;
}

export interface ProductVariant {
  id: number;
  name: string;
  sku: string;
  barcode: string | null;
  price_excl_tax: string | null;
  is_active: boolean;
}

export interface Product {
  id: number;
  category_id: number | null;
  item_code: string;
  barcode: string | null;
  name: string;
  unit: string;
  pct_code: string;
  tax_rate: string;
  price_excl_tax: string;
  cost_price: string | null;
  track_stock: boolean;
  reorder_level: string;
  is_active: boolean;
  variants: ProductVariant[];
}

/** One line in the cashier's in-progress cart, before it's sent to the server. */
export interface CartLine {
  product_id: number;
  variant_id?: number;
  item_code: string;
  name: string;
  quantity: number;
  unit_price_excl_tax: string;
  tax_rate: string;
  line_discount?: string;
  further_tax?: string;
}

export interface Tender {
  mode: number; // 1=Cash 2=Card 3=Gift Voucher 4=Loyalty Card 6=Cheque (5=Mixed is derived)
  amount: string;
}

export interface Buyer {
  ntn?: string;
  cnic?: string;
  name?: string;
  phone?: string;
}

export interface Customer {
  id: number;
  name: string;
  company_name: string | null;
  contact_person: string | null;
  phone: string | null;
  email: string | null;
  ntn: string | null;
  ntn_formatted: string | null;
  cnic: string | null;
  cnic_formatted: string | null;
  strn: string | null;
  address: string | null;
  billing_address: string | null;
  shipping_address: string | null;
  customer_type: 'walk_in' | 'b2b';
  payment_terms_days: number | null;
  credit_limit: string | null;
  opening_balance: string | null;
  price_level: 'retail' | 'wholesale' | 'custom' | null;
  is_active: boolean;
  sales_summary?: CustomerSalesSummary;
  recent_sales?: Invoice[];
}

export interface CustomerSalesSummary {
  sales_count: number;
  total_sales_amount: string;
  total_tax_charged: string;
  last_sale_at: string | null;
  opening_balance: string;
  account_balance: string;
  available_credit: string;
}

/** SIR (hyphen-separated, e.g. SIR-1056) and SS (underscore-separated, e.g. SS_1034) are independent gapless USIN sequences per terminal. */
export type UsinType = 'SIR' | 'SS';

export interface SaleRequest {
  branch_id: number;
  terminal_id: number;
  usin_type: UsinType;
  items: Array<{
    product_id: number;
    variant_id?: number;
    quantity: number;
    unit_price_excl_tax?: string;
    line_discount?: string;
    further_tax?: string;
  }>;
  bill_discount?: string;
  tenders: Tender[];
  buyer?: Buyer;
  customer_id?: number;
}

export interface InvoiceItem {
  id: number;
  product_id: number | null;
  product_variant_id: number | null;
  item_code: string;
  item_name: string;
  pct_code: string;
  quantity: string;
  unit_price_excl_tax: string;
  tax_rate: string;
  sale_value: string;
  tax_charged: string;
  discount: string;
  further_tax: string;
  total_amount: string;
  invoice_type: number;
}

export interface Invoice {
  id: number;
  usin: string;
  usin_type: UsinType | null;
  invoice_type: number;
  ref_invoice_id: number | null;
  ref_usin: string | null;
  fbr_invoice_number: string | null;
  fiscal_status: 'pending' | 'synced' | 'failed_permanent';
  printed_offline_pending: boolean;
  branch_id: number;
  terminal_id: number;
  customer_id: number | null;
  buyer: Buyer;
  total_sale_value: string;
  total_tax_charged: string;
  discount: string;
  further_tax: string;
  total_bill_amount: string;
  payment_mode: number;
  payment_breakdown: Tender[] | null;
  sold_at: string;
  synced_at: string | null;
  cashier_id: number;
  items: InvoiceItem[];
}

/** A sale that couldn't reach the server yet - held entirely client-side in IndexedDB. */
export interface PendingSale {
  localId: string;
  createdAt: string;
  request: SaleRequest;
  /** Locally-estimated totals so a receipt can be printed immediately, offline. */
  estimatedTotal: string;
  syncStatus: 'pending' | 'syncing' | 'failed';
  lastError?: string;
}
