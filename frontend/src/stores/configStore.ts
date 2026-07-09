import { create } from 'zustand';
import { apiClient } from '../api/client';

interface PublicConfig {
  further_tax_rate_percent: number;
  buyer_capture_threshold: number;
  discount_permission_threshold_percent: number;
}

interface ConfigState {
  config: PublicConfig | null;
  load: () => Promise<void>;
}

const defaults: PublicConfig = {
  further_tax_rate_percent: 0,
  buyer_capture_threshold: 100000,
  discount_permission_threshold_percent: 10,
};

/** Fetched once per session so the checkout total preview (incl. Further Tax
 *  for non-ATL B2B customers) matches what the server will actually charge,
 *  before the cashier even collects payment - avoids a payment-mismatch
 *  surprise after the non-ATL confirmation step. */
export const useConfigStore = create<ConfigState>((set, get) => ({
  config: null,
  load: async () => {
    if (get().config) return;
    try {
      const { data } = await apiClient.get<PublicConfig>('/config/public');
      set({ config: data });
    } catch {
      set({ config: defaults });
    }
  },
}));
