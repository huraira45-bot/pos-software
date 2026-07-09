import { useCartStore } from '../stores/cartStore';

const PAYMENT_MODES = [
  { value: 1, label: 'Cash' },
  { value: 2, label: 'Card' },
  { value: 3, label: 'Gift Voucher' },
  { value: 4, label: 'Loyalty Card' },
  { value: 6, label: 'Cheque' },
];

interface Props {
  billTotal: number;
}

export default function TenderPanel({ billTotal }: Props) {
  const tenders = useCartStore((s) => s.tenders);
  const setTenders = useCartStore((s) => s.setTenders);

  const tenderedSum = tenders.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
  const remaining = Math.round((billTotal - tenderedSum) * 100) / 100;

  function addTender() {
    setTenders([...tenders, { mode: 1, amount: remaining > 0 ? remaining.toFixed(2) : '0.00' }]);
  }

  function updateTender(index: number, field: 'mode' | 'amount', value: string) {
    const next = [...tenders];
    next[index] = field === 'mode' ? { ...next[index], mode: Number(value) } : { ...next[index], amount: value };
    setTenders(next);
  }

  function removeTender(index: number) {
    setTenders(tenders.filter((_, i) => i !== index));
  }

  return (
    <div>
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-sm font-medium text-slate-300">Payment</h3>
        <button type="button" onClick={addTender} className="text-xs text-sky-400 hover:text-sky-300">
          + Add tender
        </button>
      </div>

      {tenders.length === 0 && (
        <p className="text-xs text-slate-500">No tender added yet.</p>
      )}

      {tenders.map((tender, index) => (
        <div key={index} className="mb-2 flex gap-2">
          <select
            value={tender.mode}
            onChange={(e) => updateTender(index, 'mode', e.target.value)}
            className="rounded border border-slate-700 bg-slate-900 px-2 py-1 text-sm text-white"
          >
            {PAYMENT_MODES.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
          <input
            type="number"
            step="0.01"
            value={tender.amount}
            onChange={(e) => updateTender(index, 'amount', e.target.value)}
            className="flex-1 rounded border border-slate-700 bg-slate-900 px-2 py-1 text-right text-sm text-white"
          />
          <button type="button" onClick={() => removeTender(index)} className="text-red-400">
            ✕
          </button>
        </div>
      ))}

      {billTotal > 0 && (
        <p className={`mt-2 text-sm ${remaining === 0 ? 'text-emerald-400' : 'text-amber-400'}`}>
          {remaining === 0 ? 'Fully tendered' : `Remaining: Rs.${remaining.toFixed(2)}`}
        </p>
      )}
    </div>
  );
}
