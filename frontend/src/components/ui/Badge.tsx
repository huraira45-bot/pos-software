type Variant = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary';

const VARIANT_CLASSES: Record<Variant, string> = {
  neutral: 'bg-canvas text-ink-muted border-border',
  success: 'bg-success-bg text-success border-transparent',
  warning: 'bg-warning-bg text-warning border-transparent',
  danger: 'bg-danger-bg text-danger border-transparent',
  info: 'bg-info-bg text-info border-transparent',
  primary: 'bg-primary-100 text-primary-700 border-transparent',
};

/** fiscal_status values ('pending'|'synced'|'failed_permanent') map straight to a variant. */
const FISCAL_STATUS_VARIANT: Record<string, Variant> = {
  pending: 'warning',
  synced: 'success',
  failed_permanent: 'danger',
};

export default function Badge({
  children,
  variant = 'neutral',
  className = '',
}: {
  children: React.ReactNode;
  variant?: Variant;
  className?: string;
}) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${VARIANT_CLASSES[variant]} ${className}`}
    >
      {children}
    </span>
  );
}

export function FiscalStatusBadge({ status }: { status: string }) {
  const variant = FISCAL_STATUS_VARIANT[status] ?? 'neutral';
  const label = status === 'failed_permanent' ? 'Failed' : status.charAt(0).toUpperCase() + status.slice(1);
  return <Badge variant={variant}>{label}</Badge>;
}
