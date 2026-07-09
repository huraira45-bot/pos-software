import { create } from 'zustand';
import type { Buyer, CartLine, Customer, Tender } from '../types';

interface CartState {
  lines: CartLine[];
  billDiscount: string;
  tenders: Tender[];
  buyer: Buyer;
  customer: Customer | null;
  confirmNonAtlB2b: boolean;
  waiveFurtherTax: boolean;
  addLine: (line: CartLine) => void;
  updateLineQuantity: (index: number, quantity: number) => void;
  removeLine: (index: number) => void;
  setBillDiscount: (value: string) => void;
  setTenders: (tenders: Tender[]) => void;
  setBuyer: (buyer: Buyer) => void;
  setCustomer: (customer: Customer | null) => void;
  setConfirmNonAtlB2b: (value: boolean) => void;
  setWaiveFurtherTax: (value: boolean) => void;
  reset: () => void;
}

const initialState = {
  lines: [] as CartLine[],
  billDiscount: '0',
  tenders: [] as Tender[],
  buyer: {} as Buyer,
  customer: null as Customer | null,
  confirmNonAtlB2b: false,
  waiveFurtherTax: false,
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

  removeLine: (index) =>
    set((state) => ({ lines: state.lines.filter((_, i) => i !== index) })),

  setBillDiscount: (value) => set({ billDiscount: value }),
  setTenders: (tenders) => set({ tenders }),
  setBuyer: (buyer) => set({ buyer }),
  setCustomer: (customer) => set({ customer, confirmNonAtlB2b: false, waiveFurtherTax: false }),
  setConfirmNonAtlB2b: (value) => set({ confirmNonAtlB2b: value }),
  setWaiveFurtherTax: (value) => set({ waiveFurtherTax: value }),

  reset: () => set(initialState),
}));
