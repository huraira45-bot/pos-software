const PRINT_AGENT_URL = 'http://localhost:9100';

/** Used to offer/hide the "Print" action based on whether the till's local print agent is reachable. */
export async function isPrintAgentReachable(): Promise<boolean> {
  try {
    const res = await fetch(`${PRINT_AGENT_URL}/health`, { signal: AbortSignal.timeout(2000) });
    return res.ok;
  } catch {
    return false;
  }
}

/** Body must be the exact JSON from GET /api/sales/{invoice}/receipt. */
export async function printReceipt(receiptData: unknown): Promise<{ ok: true } | { ok: false; error: string }> {
  try {
    const res = await fetch(`${PRINT_AGENT_URL}/print`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(receiptData),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      return { ok: false, error: data.error ?? `Print agent returned ${res.status}.` };
    }
    return { ok: true };
  } catch {
    return { ok: false, error: 'Print agent not reachable at localhost:9100.' };
  }
}
