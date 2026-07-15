import { useEffect, useRef, useState } from 'react';
import { useOnlineStatus } from '../hooks/useOnlineStatus';
import { flushPendingSales } from '../lib/saleSubmission';
import { getPendingSales } from '../db/offlineDb';

const RETRY_INTERVAL_MS = 20000;

/**
 * Reconnect sync is driven by a periodic retry loop, NOT purely by watching
 * the `online` flag flip false->true. Browser online/offline events (and,
 * notably, headless-browser network emulation used in tests) don't reliably
 * transition that flag - if it never goes false in the first place, a
 * transition-only trigger would silently never fire again. A steady retry
 * for as long as pending sales exist is the same defense-in-depth pattern as
 * the backend's outbox sweep: an event-driven fast path (the 'online' event)
 * plus an unconditional periodic fallback that doesn't depend on any one
 * signal being trustworthy.
 */
export default function OfflineBanner() {
  const online = useOnlineStatus();
  const [pendingCount, setPendingCount] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const syncingRef = useRef(false);

  async function refreshCount() {
    const pending = await getPendingSales();
    setPendingCount(pending.length);
  }

  async function attemptFlush() {
    if (syncingRef.current) return; // never run two flushes concurrently
    syncingRef.current = true;
    setSyncing(true);
    await flushPendingSales();
    await refreshCount();
    setSyncing(false);
    syncingRef.current = false;
  }

  useEffect(() => {
    refreshCount();
    const countPoll = setInterval(refreshCount, 5000);
    const retryLoop = setInterval(attemptFlush, RETRY_INTERVAL_MS);
    const onOnline = () => attemptFlush();
    window.addEventListener('online', onOnline);

    attemptFlush(); // also try once immediately on mount

    return () => {
      clearInterval(countPoll);
      clearInterval(retryLoop);
      window.removeEventListener('online', onOnline);
    };
  }, []);

  if (online && pendingCount === 0) return null;

  return (
    <div
      className={`mb-4 rounded-card px-4 py-2 text-center text-sm font-medium text-white ${
        online ? 'bg-warning' : 'bg-danger'
      }`}
    >
      {!online && 'Offline - sales are being saved on this device and will sync automatically once connectivity returns. '}
      {syncing && 'Syncing queued sales… '}
      {pendingCount > 0 && `${pendingCount} sale(s) pending FBR sync.`}
    </div>
  );
}
