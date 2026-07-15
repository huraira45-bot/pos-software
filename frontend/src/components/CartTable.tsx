import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';
import { calculateLine } from '../lib/taxCalc';
import { EmptyState } from './ui';

export default function CartTable() {
  const lines = useCartStore((s) => s.lines);
  const updateLineQuantity = useCartStore((s) => s.updateLineQuantity);
  const updateLinePrice = useCartStore((s) => s.updateLinePrice);
  const removeLine = useCartStore((s) => s.removeLine);
  const session = useAuthStore((s) => s.session);
  const canOverridePrice = session?.user.permissions?.includes('pos.price-override') ?? false;

  if (lines.length === 0) {
    return <EmptyState title="Cart is empty" description="Scan or search an item to begin." />;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border text-left text-xs font-medium uppercase tracking-wide text-ink-faint">
            <th className="py-2">Item</th>
            <th className="py-2 text-right">Qty</th>
            <th className="py-2 text-right">Price</th>
            <th className="py-2 text-right">Tax%</th>
            <th className="py-2 text-right">Total</th>
            <th className="py-2"></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {lines.map((line, index) => {
            const totals = calculateLine(line);
            return (
              <tr key={`${line.product_id}-${line.variant_id ?? 0}`} className="hover:bg-surface-hover">
                <td className="py-2 text-ink">{line.name}</td>
                <td className="py-2 text-right">
                  <input
                    type="number"
                    min={0}
                    step="0.001"
                    value={line.quantity}
                    onChange={(e) => updateLineQuantity(index, parseFloat(e.target.value) || 0)}
                    className="w-16 rounded border border-border-strong bg-surface px-1 py-0.5 text-right text-ink outline-none focus:border-primary-500"
                  />
                </td>
                <td className="py-2 text-right">
                  {canOverridePrice ? (
                    <input
                      type="number"
                      min={0}
                      step="0.01"
                      value={line.unit_price_excl_tax}
                      onChange={(e) => updateLinePrice(index, e.target.value)}
                      className="w-24 rounded border border-border-strong bg-surface px-1 py-0.5 text-right text-ink outline-none focus:border-primary-500"
                    />
                  ) : (
                    <span className="tabular-nums text-ink">Rs.{line.unit_price_excl_tax}</span>
                  )}
                </td>
                <td className="py-2 text-right tabular-nums text-ink-muted">{line.tax_rate}%</td>
                <td className="py-2 text-right tabular-nums font-medium text-ink">Rs.{totals.totalAmount.toFixed(2)}</td>
                <td className="py-2 text-right">
                  <button
                    type="button"
                    onClick={() => removeLine(index)}
                    className="text-danger hover:opacity-75"
                    aria-label="Remove line"
                  >
                    ✕
                  </button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
