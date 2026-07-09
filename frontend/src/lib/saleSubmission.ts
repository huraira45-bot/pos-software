import { apiClient } from '../api/client';
import { getPendingSales, queuePendingSale, removePendingSale, updatePendingSale } from '../db/offlineDb';
import type { Invoice, PendingSale, SaleRequest } from '../types';

function isNetworkError(error: unknown): boolean {
  // Axios sets error.response only when the server actually answered; a
  // missing response means the request never completed (offline, DNS
  // failure, timeout) - exactly the case that should queue locally instead
  // of surfacing as a hard failure to the cashier.
  return typeof error === 'object' && error !== null && 'response' in error
    ? (error as { response?: unknown }).response === undefined
    : true;
}

/**
 * The single entry point the checkout screen calls to finalize a sale.
 * Never throws for connectivity problems - a queued sale is still a
 * successful checkout from the cashier's point of view (the whole point of
 * the offline outbox is that FBR reachability is never on the critical path).
 */
export async function submitSale(
  request: SaleRequest,
  estimatedTotal: string,
): Promise<{ mode: 'synced'; invoice: Invoice } | { mode: 'queued'; localId: string }> {
  try {
    const response = await apiClient.post<Invoice>('/sales', request);
    return { mode: 'synced', invoice: response.data };
  } catch (error) {
    if (!isNetworkError(error)) {
      throw error; // validation errors etc. are real, must surface to the cashier
    }

    const localId = crypto.randomUUID();
    const pending: PendingSale = {
      localId,
      createdAt: new Date().toISOString(),
      request,
      estimatedTotal,
      syncStatus: 'pending',
    };
    await queuePendingSale(pending);
    return { mode: 'queued', localId };
  }
}

/** Called on reconnect and on a timer - pushes every queued sale in order. */
export async function flushPendingSales(): Promise<{ synced: number; stillPending: number }> {
  const pending = await getPendingSales();
  let synced = 0;

  for (const sale of pending.filter((s) => s.syncStatus !== 'syncing')) {
    await updatePendingSale({ ...sale, syncStatus: 'syncing' });
    try {
      await apiClient.post<Invoice>('/sales', sale.request);
      await removePendingSale(sale.localId);
      synced++;
    } catch (error) {
      if (isNetworkError(error)) {
        await updatePendingSale({ ...sale, syncStatus: 'pending' });
        break; // still offline - stop, don't burn through the rest failing identically
      }
      // A real validation/authorization error on a queued sale needs a human:
      // surfaced via syncStatus='failed' rather than retried forever.
      await updatePendingSale({
        ...sale,
        syncStatus: 'failed',
        lastError: error instanceof Error ? error.message : 'Unknown error',
      });
    }
  }

  const remaining = await getPendingSales();
  return { synced, stillPending: remaining.length };
}
