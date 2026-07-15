import { create } from 'zustand';
import type { Buyer, CartLine, Customer, Tender, UsinType } from '../types';

interface CartState {
  lines: CartLine[];
  billDiscount: string;
  tenders: Tender[];
  buyer: Buyer;
  customer: Customer | null;
  usinType: UsinType;
  addLine: (line: CartLine) => void;
  updateLineQuantity: (index: number, quantity: number) => void;
  updateLinePrice: (index: number, unitPriceExclTax: string) => void;
  removeLine: (index: number) => void;
  setBillDiscount: (value: string) => void;
  setTenders: (tenders: Tender[]) => void;
  setBuyer: (buyer: Buyer) => void;
  setCustomer: (customer: Customer | null) => void;
  setUsinType: (usinType: UsinType) => void;
  reset: () => void;
}

const initialState = {
  lines: [] as CartLine[],
  billDiscount: '0',
  tenders: [] as Tender[],
  buyer: {} as Buyer,
  customer: null as Customer | null,
  usinType: 'SIR' as UsinType,
};

export const useCartStore = create<CartState>((set) => ({
  ...initialState,

  addLine: (line) =>
    set((state) => {
      const existingIndex = state.lines.findIndex(
        (l) => l.product_id === line.product_id && l.variant_id === line.variant_id,
      );
      if (existingIndex >= 0) {
        const lines = [...state.lines];
        lines[existingIndex] = {
          ...lines[existingIndex],
          quantity: lines[existingIndex].quantity + line.quantity,
        };
        return { lines };
      }
      return { lines: [...state.lines, line] };
    }),

  updateLineQuantity: (index, quantity) =>
    set((state) => {
      const lines = [...state.lines];
      if (quantity <= 0) {
        lines.splice(index, 1);
      } else {
        lines[index] = { ...lines[index], quantity };
      }
      return { lines };
    }),

  updateLinePrice: (index, unitPriceExclTax) =>
    set((state) => {
      const lines = [...state.lines];
      lines[index] = { ...lines[index], unit_price_excl_tax: unitPriceExclTax };
      return { lines };
    }),

  removeLine: (index) =>
    set((state) => ({ lines: state.lines.filter((_, i) => i !== index) })),

  setBillDiscount: (value) => set({ billDiscount: value }),
  setTenders: (tenders) => set({ tenders }),
  setBuyer: (buyer) => set({ buyer }),
  setCustomer: (customer) => set({ customer }),
  setUsinType: (usinType) => set({ usinType }),

  reset: () => set(initialState),
}));
