// Pixonaut's webhook signature: `v1=` and the hex HMAC-SHA256, keyed with the
// endpoint's signing secret, of the timestamp, a `.` and the raw body bytes.
// `contracts/pixonaut/webhook-signature-vectors.json` holds it to Pixonaut's
// own signer.
import { createHmac } from 'node:crypto';

export const TIMESTAMP_HEADER = 'x-pixonaut-timestamp';
export const SIGNATURE_HEADER = 'x-pixonaut-signature';

/** The scheme's name, carried as the signature's prefix. */
export const SIGNATURE_SCHEME = 'v1';

const MS_PER_SECOND = 1000;

/** The body a delivery carries, for every event type. */
export type Envelope = {
	id: string;
	type: string;
	payload: unknown;
	createdAt: number;
};

/** Epoch milliseconds as the Unix seconds `x-pixonaut-timestamp` carries. */
export function toUnixSeconds(ms: number): number {
	return Math.floor(ms / MS_PER_SECOND);
}

export function sign(
	secret: string,
	timestampSeconds: number,
	body: string,
): string {
	const mac = createHmac('sha256', secret)
		.update(`${timestampSeconds}.${body}`, 'utf8')
		.digest('hex');
	return `${SIGNATURE_SCHEME}=${mac}`;
}

/** The exact body bytes and headers a delivery of `envelope` sends at
 *  `sentAtMs`, as Pixonaut's `signedRequest` builds them. */
export function signedRequest(
	secret: string,
	envelope: Envelope,
	sentAtMs: number,
): { body: string; headers: Record<string, string> } {
	const body = JSON.stringify(envelope);
	const timestamp = toUnixSeconds(sentAtMs);
	return {
		body,
		headers: {
			'content-type': 'application/json',
			[TIMESTAMP_HEADER]: String(timestamp),
			[SIGNATURE_HEADER]: sign(secret, timestamp, body),
		},
	};
}
