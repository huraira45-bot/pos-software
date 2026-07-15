import type { ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';

export function Table({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-sm">{children}</table>
    </div>
  );
}

export function THead({ children }: { children: ReactNode }) {
  return (
    <thead>
      <tr className="border-b border-border text-left text-xs font-medium uppercase tracking-wide text-ink-faint">
        {children}
      </tr>
    </thead>
  );
}

export function TH({ children, align = 'left', ...rest }: ThHTMLAttributes<HTMLTableCellElement> & { align?: 'left' | 'right' | 'center' }) {
  return (
    <th
      className={`px-3 py-2 font-medium ${align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'}`}
      {...rest}
    >
      {children}
    </th>
  );
}

export function TBody({ children }: { children: ReactNode }) {
  return <tbody className="divide-y divide-border">{children}</tbody>;
}

export function TR({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <tr className={`hover:bg-surface-hover ${className}`}>{children}</tr>;
}

export function TD({ children, align = 'left', className = '', ...rest }: TdHTMLAttributes<HTMLTableCellElement> & { align?: 'left' | 'right' | 'center' }) {
  return (
    <td
      className={`px-3 py-2 text-ink ${align === 'right' ? 'text-right tabular-nums' : align === 'center' ? 'text-center' : 'text-left'} ${className}`}
      {...rest}
    >
      {children}
    </td>
  );
}

/** Bold summary row pinned to the bottom of a report table. */
export function TotalsRow({ children }: { children: ReactNode }) {
  return <tfoot className="border-t-2 border-border-strong font-semibold text-ink">{children}</tfoot>;
}
