export type ReportGroup = 'Sales' | 'Financial' | 'Inventory' | 'B2B';

export interface ReportFilterConfig {
  branch?: boolean;
  dateRange?: boolean;
  /** true = every customer; 'b2b' = only customer_type===b2b (matches what the backend query actually returns rows for). */
  customer?: boolean | 'b2b';
  customerRequired?: boolean;
  product?: boolean;
  category?: boolean;
  /** Fixed value sent as the `granularity` param - not user-selectable, distinguishes the Daily vs Monthly registry entries that share one endpoint. */
  granularity?: 'day' | 'month';
}

export interface ReportConfig {
  key: string;
  title: string;
  description: string;
  group: ReportGroup;
  endpoint: string;
}

export const REPORT_FILTERS: Record<string, ReportFilterConfig> = {
  sales_summary: { branch: true, dateRange: true },
  daily_sales: { branch: true, dateRange: true, granularity: 'day' },
  monthly_sales: { branch: true, dateRange: true, granularity: 'month' },
  sales_by_product: { branch: true, dateRange: true, product: true, category: true },
  sales_by_category: { branch: true, dateRange: true, category: true },
  sales_by_cashier: { branch: true, dateRange: true },
  sales_by_customer: { branch: true, dateRange: true, customer: true },
  b2b_statement: { dateRange: true, customer: 'b2b', customerRequired: true },
  outstanding_payments: { customer: 'b2b' },
  tax_collected: { branch: true, dateRange: true },
  profit: { branch: true, dateRange: true, product: true },
  inventory_valuation: { branch: true },
  low_stock: { branch: true },
  payment_methods: { branch: true, dateRange: true },
};

export const REPORTS: ReportConfig[] = [
  { key: 'sales_summary', title: 'Sales Summary', description: 'New sales vs. returns vs. net revenue for a date range.', group: 'Sales', endpoint: '/reports/sales-summary' },
  { key: 'daily_sales', title: 'Daily Sales', description: 'Revenue and invoice count trended by day.', group: 'Sales', endpoint: '/reports/sales-trend' },
  { key: 'monthly_sales', title: 'Monthly Sales', description: 'Revenue and invoice count trended by month.', group: 'Sales', endpoint: '/reports/sales-trend' },
  { key: 'sales_by_product', title: 'Sales by Product', description: 'Units sold, tax, and revenue per item.', group: 'Sales', endpoint: '/reports/sales-by-item' },
  { key: 'sales_by_category', title: 'Sales by Category', description: 'Units sold and revenue per product category.', group: 'Sales', endpoint: '/reports/sales-by-category' },
  { key: 'sales_by_cashier', title: 'Sales by Cashier', description: 'Invoice count and revenue per cashier.', group: 'Sales', endpoint: '/reports/sales-by-cashier' },
  { key: 'sales_by_customer', title: 'Sales by Customer', description: 'Revenue per customer, including a Walk-in aggregate row.', group: 'Sales', endpoint: '/reports/sales-by-customer' },
  { key: 'b2b_statement', title: 'B2B Customer Statement', description: 'Opening balance, period activity, and closing balance for one B2B customer.', group: 'B2B', endpoint: '/reports/b2b-statement' },
  { key: 'outstanding_payments', title: 'Outstanding Payments', description: "B2B customer account balances and available credit. This system has no unpaid-invoice tracking - every sale is paid in full at checkout.", group: 'B2B', endpoint: '/reports/customer-balances' },
  { key: 'tax_collected', title: 'Tax Collected', description: 'Tax charged by rate band, split by FBR sync status.', group: 'Financial', endpoint: '/reports/tax-collected' },
  { key: 'profit', title: 'Profit', description: "Revenue minus each product's current cost price (not a historical cost snapshot).", group: 'Financial', endpoint: '/reports/profit' },
  { key: 'inventory_valuation', title: 'Inventory Valuation', description: 'Current stock-on-hand valued at cost price.', group: 'Inventory', endpoint: '/reports/inventory-valuation' },
  { key: 'low_stock', title: 'Low Stock', description: 'Products at or below their reorder level.', group: 'Inventory', endpoint: '/reports/low-stock' },
  { key: 'payment_methods', title: 'Payment Method', description: 'Amount collected per payment method, with mixed tenders split back to their real methods.', group: 'Financial', endpoint: '/reports/payment-methods' },
];

export const REPORT_GROUPS: ReportGroup[] = ['Sales', 'Financial', 'Inventory', 'B2B'];

export function getReport(key: string): ReportConfig | undefined {
  return REPORTS.find((r) => r.key === key);
}

export function getReportFilters(key: string): ReportFilterConfig {
  return REPORT_FILTERS[key] ?? {};
}
