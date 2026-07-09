import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { AuthSession } from '../types';

interface AuthState {
  session: AuthSession | null;
  setSession: (session: AuthSession) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      session: null,
      setSession: (session) => set({ session }),
      logout: () => set({ session: null }),
    }),
    { name: 'pos-auth' },
  ),
);
