import { useCartStore } from '../stores/cartStore';
import { calculateLine } from '../lib/taxCalc';

export default function CartTable() {
  const lines = useCartStore((s) => s.lines);
  const updateLineQuantity = useCartStore((s) => s.updateLineQuantity);
  const removeLine = useCartStore((s) => s.removeLine);

  if (lines.length === 0) {
    return <p className="py-8 text-center text-slate-500">Cart is empty - scan or search an item to begin.</p>;
  }

  return (
    <table className="w-full text-sm text-slate-200">
      <thead>
        <tr className="border-b border-slate-700 text-left text-slate-400">
          <th className="py-2">Item</th>
          <th className="py-2 text-right">Qty</th>
          <th className="py-2 text-right">Price</th>
          <th className="py-2 text-right">Tax%</th>
          <th className="py-2 text-right">Total</th>
          <th className="py-2"></th>
        </tr>
      </thead>
      <tbody>
        {lines.map((line, index) => {
          const totals = calculateLine(line);
          return (
            <tr key={`${line.product_id}-${line.variant_id ?? 0}`} className="border-b border-slate-800">
              <td className="py-2">{line.name}</td>
              <td className="py-2 text-right">
                <input
                  type="number"
                  min={0}
                  step="0.001"
                  value={line.quantity}
                  onChange={(e) => updateLineQuantity(index, parseFloat(e.target.value) || 0)}
                  className="w-16 rounded border border-slate-700 bg-slate-900 px-1 py-0.5 text-right"
                />
              </td>
              <td className="py-2 text-right">Rs.{line.unit_price_excl_tax}</td>
              <td className="py-2 text-right">{line.tax_rate}%</td>
              <td className="py-2 text-right font-medium">Rs.{totals.totalAmount.toFixed(2)}</td>
              <td className="py-2 text-right">
                <button
                  type="button"
                  onClick={() => removeLine(index)}
                  className="text-red-400 hover:text-red-300"
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
  );
}
