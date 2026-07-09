import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface TerminalState {
  terminalId: number | null;
  branchId: number | null;
  setTerminal: (terminalId: number, branchId: number) => void;
  clear: () => void;
}

/**
 * Which terminal this physical till is registered as - a property of the
 * device, not the logged-in cashier, so it's stored separately from
 * authStore and persists across shift changes/logins on the same machine.
 */
export const useTerminalStore = create<TerminalState>()(
  persist(
    (set) => ({
      terminalId: null,
      branchId: null,
      setTerminal: (terminalId, branchId) => set({ terminalId, branchId }),
      clear: () => set({ terminalId: null, branchId: null }),
    }),
    { name: 'pos-terminal' },
  ),
);
