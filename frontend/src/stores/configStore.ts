import { create } from 'zustand';
import { apiClient } from '../api/client';

interface PublicConfig {
  buyer_capture_threshold: number;
  discount_permission_threshold_percent: number;
}

interface ConfigState {
  config: PublicConfig | null;
  load: () => Promise<void>;
}

const defaults: PublicConfig = {
  buyer_capture_threshold: 100000,
  discount_permission_threshold_percent: 10,
};

/** Fetched once per session so the checkout screen's thresholds match what
 *  the server will actually enforce. */
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
