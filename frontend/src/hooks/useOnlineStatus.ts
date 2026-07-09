import { useEffect, useState } from 'react';

/**
 * navigator.onLine is a necessary-but-not-sufficient signal (it can be true on
 * a LAN with no real internet path to the API), so this also does a cheap
 * periodic reachability probe against the API's health check rather than
 * trusting the browser flag alone.
 */
export function useOnlineStatus(): boolean {
  const [online, setOnline] = useState(navigator.onLine);

  useEffect(() => {
    const goOnline = () => setOnline(true);
    const goOffline = () => setOnline(false);
    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);

    const probe = setInterval(async () => {
      try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 3000);
        const res = await fetch('/up', { method: 'GET', signal: controller.signal, cache: 'no-store' });
        clearTimeout(timeout);
        setOnline(res.ok);
      } catch {
        setOnline(false);
      }
    }, 15000);

    return () => {
      window.removeEventListener('online', goOnline);
      window.removeEventListener('offline', goOffline);
      clearInterval(probe);
    };
  }, []);

  return online;
}
