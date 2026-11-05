import type { UnavailableReason } from './errors';

/**
 * Tells the store that a side cannot be personalised, so the store manager
 * hears of it. Best effort: a report that fails is dropped.
 */
export function reportUnavailable(
	reportUrl: string,
	productId: number,
	side: string,
	reason: UnavailableReason,
): void {
	void fetch(reportUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ product: productId, side, reason }),
		credentials: 'omit',
		keepalive: true,
	}).catch(() => undefined);
}
