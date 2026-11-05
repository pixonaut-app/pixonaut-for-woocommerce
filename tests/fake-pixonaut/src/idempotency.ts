// `Idempotency-Key` as Pixonaut answers it: one key space per store, a key
// remembered for 24 hours from when it is first sent, a repeat answered with
// what the first made (marked `Idempotent-Replayed: true`), a repeat while the
// first is still being answered refused `409` with `Retry-After`, and a key
// freed again when its request was refused.

export const IDEMPOTENCY_KEY_HEADER = 'idempotency-key';
export const IDEMPOTENT_REPLAYED_HEADER = 'Idempotent-Replayed';
export const RETRY_AFTER_HEADER = 'Retry-After';

const MS_PER_HOUR = 60 * 60 * 1000;
/** How long a key is remembered after it is first sent. */
export const IDEMPOTENCY_REPLAY_WINDOW_MS = 24 * MS_PER_HOUR;
/** Pixonaut's `Retry-After` on `idempotency_key_in_use`, in seconds. */
export const IDEMPOTENCY_IN_USE_RETRY_AFTER_S = 2;
/** A key is 1 to this many printable ASCII characters. */
export const IDEMPOTENCY_KEY_MAX_CHARS = 255;

/** Visible ASCII, no space: the reference's pattern for the header. */
const PRINTABLE_ASCII = /^[\x21-\x7e]+$/;

/** What an answered request made, to answer its repeats with. */
export type Answer = {
	status: number;
	body: unknown;
	/** Re-reads what the first request made, so a replay shows its current
	 *  state; without it the first answer is repeated as it was. */
	replay?: () => { status: number; body: unknown };
};

type Claim = {
	fingerprint: string;
	firstSentAt: number;
	answer: Answer | null;
};

export type ClaimResult =
	| { kind: 'new'; settle(answer: Answer): void; release(): void }
	| { kind: 'replay'; status: number; body: unknown }
	| { kind: 'refused'; status: 409 | 422; error: string };

export type IdempotencyStore = {
	claim(
		storeId: string,
		key: string,
		fingerprint: string,
		now: number,
	): ClaimResult;
	reset(): void;
};

export function validIdempotencyKey(key: string): boolean {
	return key.length <= IDEMPOTENCY_KEY_MAX_CHARS && PRINTABLE_ASCII.test(key);
}

export function createIdempotencyStore(): IdempotencyStore {
	const claims = new Map<string, Claim>();
	return {
		claim(storeId, key, fingerprint, now) {
			if (!validIdempotencyKey(key)) {
				return {
					kind: 'refused',
					status: 422,
					error: 'invalid_idempotency_key',
				};
			}
			const slot = `${storeId}\n${key}`;
			const held = claims.get(slot);
			if (held && now - held.firstSentAt < IDEMPOTENCY_REPLAY_WINDOW_MS) {
				if (held.fingerprint !== fingerprint) {
					return {
						kind: 'refused',
						status: 422,
						error: 'idempotency_key_reused',
					};
				}
				if (held.answer === null) {
					return {
						kind: 'refused',
						status: 409,
						error: 'idempotency_key_in_use',
					};
				}
				const replayed = held.answer.replay?.() ?? held.answer;
				return { kind: 'replay', status: replayed.status, body: replayed.body };
			}
			const claim: Claim = { fingerprint, firstSentAt: now, answer: null };
			claims.set(slot, claim);
			return {
				kind: 'new',
				settle(answer) {
					claim.answer = answer;
				},
				release() {
					if (claims.get(slot) === claim) {
						claims.delete(slot);
					}
				},
			};
		},
		reset() {
			claims.clear();
		},
	};
}
