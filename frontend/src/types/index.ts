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
  track_stock: boolean;
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
  phone: string | null;
  ntn: string | null;
  ntn_formatted: string | null;
  cnic: string | null;
  cnic_formatted: string | null;
  address: string | null;
  customer_type: 'walk_in' | 'b2b';
  atl_status: 'active' | 'inactive' | 'unknown';
  atl_checked_at: string | null;
  is_active: boolean;
}

export interface SaleRequest {
  branch_id: number;
  terminal_id: number;
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
  confirm_non_atl_b2b?: boolean;
  waive_further_tax?: boolean;
}

export interface InvoiceItem {
  id: number;
  item_code: string;
  item_name: string;
  quantity: string;
  unit_price_excl_tax: string;
  tax_rate: string;
  sale_value: string;
  tax_charged: string;
  discount: string;
  total_amount: string;
}

export interface Invoice {
  id: number;
  usin: string;
  invoice_type: number;
  fbr_invoice_number: string | null;
  fiscal_status: 'pending' | 'synced' | 'failed_permanent';
  printed_offline_pending: boolean;
  total_sale_value: string;
  total_tax_charged: string;
  discount: string;
  total_bill_amount: string;
  payment_mode: number;
  sold_at: string;
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
