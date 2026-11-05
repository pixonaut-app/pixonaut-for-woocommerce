// Webhook deliveries to the stores' endpoints, signed as Pixonaut signs them.
// A test emits events through the control API; each becomes one delivery to
// its store's endpoint, attempted at once. A delivery the endpoint does not
// acknowledge stays pending until a retry, which sends it again with the same
// id, and fails for good after `MAX_ATTEMPTS`. Nothing is sent on a timer, so
// a test decides when every attempt happens. A delivery is sent by one attempt
// at a time, and a reset stops every emit and retry still under way. Nothing
// is sent to an endpoint a disconnect revoked: an emit to it is refused, and a
// pending delivery whose endpoint was revoked or replaced is retired. An
// endpoint narrowed to some event types is sent only those; a ping is sent
// whatever it is narrowed to, once, and is no delivery.
//
// Every event emitted to a store is also recorded in its event stream, in the
// order it was emitted, whether or not its delivery gets through: what
// `GET /v1/events` pulls. While a store's deliveries are dropped, an event
// is recorded and its delivery failed for good at once, with nothing sent.
import { randomUUID } from 'node:crypto';
import type { Clock } from '../clock';
import type { State, Store } from '../state';
import { type Envelope, signedRequest, TIMESTAMP_HEADER } from './sign';

/** Attempts before a delivery is failed for good. Pixonaut retries for days;
 *  the fake gives up after a few, so a test reaches `failed` in few retries. */
export const MAX_ATTEMPTS = 3;

/** How long one attempt waits for the endpoint to answer. */
export const DELIVERY_TIMEOUT_MS = 10_000;

/** What a tampered delivery gets wrong, so a test sees the receiver refuse
 *  it: the body changed after signing, a timestamp header other than the
 *  signed one, or a signature made with another secret. */
export const TAMPERS = ['body', 'timestamp', 'secret'] as const;
export type Tamper = (typeof TAMPERS)[number];

export const ORDERS = ['in-order', 'shuffled'] as const;
export type Order = (typeof ORDERS)[number];

/** Appended to the endpoint's secret for a `secret`-tampered delivery, so
 *  the secret it is signed with is never the endpoint's own. */
const WRONG_SECRET_SUFFIX = '_not_this_endpoint';

/** One event for `emit`: a new one, or one already emitted to the same store,
 *  sent again unchanged (a duplicate the receiver has to drop). */
export type EmitEvent =
	| {
			type: string;
			payload: unknown;
			id?: string;
			/** Epoch ms; the fake's now when absent. */
			createdAt?: number;
			tamper?: Tamper;
	  }
	| { replay: string; tamper?: Tamper };

/** `retired`: its endpoint was revoked or replaced before it got through. */
export type DeliveryStatus = 'pending' | 'delivered' | 'failed' | 'retired';

/** One event on its way to one endpoint. */
export type Delivery = {
	storeId: string;
	endpointId: string;
	envelope: Envelope;
	status: DeliveryStatus;
	attempts: number;
	tamper?: Tamper;
};

/** One request the fake sent, and what the endpoint answered. */
export type Attempt = {
	eventId: string;
	type: string;
	storeId: string;
	endpointId: string;
	url: string;
	/** When it was sent, epoch ms on the fake's clock. */
	at: number;
	headers: Record<string, string>;
	body: string;
	/** A send of an already emitted event, outside its delivery's attempts. */
	replay: boolean;
	tamper?: Tamper;
	/** The endpoint's status; null when no answer came. */
	status: number | null;
	/** Why no answer came. */
	error?: string;
};

/** The attempts an emit made, or why it sent nothing. */
export type Emitted =
	| { ok: true; attempts: Attempt[] }
	| { ok: false; problems: string[] };

/** The type of the test request `ping` sends. */
export const PING_TYPE = 'webhook.ping';

/** What `POST /v1/webhooks/{id}/ping` answers. */
export type Pinged = { delivered: true } | { delivered: false; error: string };

/** What is wrong with an envelope against the reference; empty when it
 *  holds. */
export type EnvelopeCheck = (envelope: Envelope) => string[];

export type Webhooks = {
	/** Delivers `events` to `storeId`'s endpoint, one after another, in the
	 *  order given or shuffled; resolves with the attempts made. */
	emit(
		storeId: string,
		events: EmitEvent[],
		options?: { order?: Order; seed?: number },
	): Promise<Emitted>;
	/** Attempts every pending delivery again, in the order it was emitted. */
	retry(): Promise<Attempt[]>;
	/** Sends `storeId`'s endpoint one signed `webhook.ping`, never retried. */
	ping(storeId: string): Promise<Pinged>;
	readonly deliveries: Delivery[];
	readonly attempts: Attempt[];
	/** Deliveries to `endpointId` that failed for good, as Pixonaut counts
	 *  an endpoint's `failedCount`. */
	failedCount(endpointId: string): number;
	/** Puts every delivery to `endpointId` that failed for good back to
	 *  pending, its attempts reset, for the next retry to send; answers how
	 *  many it requeued. */
	requeueFailed(endpointId: string): number;
	/** The events emitted to `storeId`, in the order they were recorded. */
	events(storeId: string): readonly Envelope[];
	/** While dropping, an event emitted to `storeId` is recorded and its
	 *  delivery failed at once, without a request to the endpoint. */
	drop(storeId: string, drop: boolean): void;
	/** The stores whose deliveries are being dropped. */
	readonly dropping: ReadonlySet<string>;
	reset(): void;
};

function acknowledged(status: number | null): boolean {
	return status !== null && status >= 200 && status < 300;
}

/** Whether an endpoint narrowed to `eventTypes` receives `type`. */
function subscribed(eventTypes: readonly string[], type: string): boolean {
	return eventTypes.length === 0 || eventTypes.includes(type);
}

// mulberry32's constants, as published by Tommy Ettinger
// (https://gist.github.com/tommyettinger/46a874533244883189143505d203312c).
const MULBERRY_INCREMENT = 0x6d2b79f5;
const MULBERRY_SHIFT_1 = 15;
const MULBERRY_SHIFT_2 = 7;
const MULBERRY_SHIFT_3 = 14;
const MULBERRY_ODD_MIX = 61;
/** 2^32: scales an unsigned 32-bit integer into [0, 1). */
const UINT32_RANGE = 2 ** 32;

/** A seeded generator (mulberry32), so a shuffle a test names by its seed
 *  repeats. Returns values in [0, 1). */
function seeded(seed: number): () => number {
	let a = seed >>> 0;
	return () => {
		a = (a + MULBERRY_INCREMENT) >>> 0;
		let t = a;
		t = Math.imul(t ^ (t >>> MULBERRY_SHIFT_1), t | 1);
		t ^= t + Math.imul(t ^ (t >>> MULBERRY_SHIFT_2), t | MULBERRY_ODD_MIX);
		return ((t ^ (t >>> MULBERRY_SHIFT_3)) >>> 0) / UINT32_RANGE;
	};
}

/** `items` in a random order (Fisher-Yates), from `seed` when given. */
export function shuffled<T>(items: readonly T[], seed?: number): T[] {
	const random = seed === undefined ? Math.random : seeded(seed);
	const out = [...items];
	for (let i = out.length - 1; i > 0; i--) {
		const j = Math.floor(random() * (i + 1));
		[out[i], out[j]] = [out[j] as T, out[i] as T];
	}
	return out;
}

/** The request a delivery of `envelope` sends, tampered as asked. */
function request(
	secret: string,
	envelope: Envelope,
	now: number,
	tamper: Tamper | undefined,
): { body: string; headers: Record<string, string> } {
	const signed = signedRequest(
		tamper === 'secret' ? `${secret}${WRONG_SECRET_SUFFIX}` : secret,
		envelope,
		now,
	);
	if (tamper === 'body') {
		return {
			body: JSON.stringify({ ...envelope, createdAt: envelope.createdAt + 1 }),
			headers: signed.headers,
		};
	}
	if (tamper === 'timestamp') {
		const timestamp = Number(signed.headers[TIMESTAMP_HEADER]) + 1;
		return {
			body: signed.body,
			headers: { ...signed.headers, [TIMESTAMP_HEADER]: String(timestamp) },
		};
	}
	return signed;
}

export function createWebhooks(
	state: () => State,
	clock: Clock,
	check: EnvelopeCheck,
): Webhooks {
	const deliveries: Delivery[] = [];
	const attempts: Attempt[] = [];
	/** Each store's event stream, by store id. */
	const streams = new Map<string, Envelope[]>();
	const dropping = new Set<string>();
	/** Deliveries an attempt is sending now; no other attempt sends them. */
	const inFlight = new Set<Delivery>();
	/** Bumped on reset: an emit or retry started before it sends nothing
	 *  more and records nothing more. */
	let generation = 0;
	/** Aborts the requests in flight when a reset comes. */
	let aborts = new AbortController();

	function endpointOf(storeId: string): Store {
		const store = state().stores.get(storeId);
		if (store === undefined) {
			throw new Error(`No store ${storeId}.`);
		}
		return store;
	}

	/** Sends `envelope` to `store`'s endpoint once and logs the attempt. */
	async function send(
		store: Store,
		envelope: Envelope,
		replay: boolean,
		tamper: Tamper | undefined,
	): Promise<Attempt> {
		const at = clock.now();
		const { body, headers } = request(
			store.webhook.secret,
			envelope,
			at,
			tamper,
		);
		const attempt: Attempt = {
			eventId: envelope.id,
			type: envelope.type,
			storeId: store.id,
			endpointId: store.webhook.id,
			url: store.webhook.url,
			at,
			headers,
			body,
			replay,
			tamper,
			status: null,
		};
		attempts.push(attempt);
		try {
			const response = await fetch(store.webhook.url, {
				method: 'POST',
				headers,
				body,
				redirect: 'manual',
				signal: AbortSignal.any([
					aborts.signal,
					AbortSignal.timeout(DELIVERY_TIMEOUT_MS),
				]),
			});
			// The status is the answer; a body that breaks off after it does
			// not undo it. The body is dropped, so the connection is freed.
			attempt.status = response.status;
			response.body?.cancel().catch(() => undefined);
		} catch (error) {
			attempt.error = String(error);
		}
		return attempt;
	}

	/** Sends `delivery` once, unless another attempt is sending it or it is
	 *  settled; null when nothing was sent. The attempt is counted when it is
	 *  claimed, before anything is awaited, so overlapping retries cannot send
	 *  it twice or past `MAX_ATTEMPTS`. */
	async function attempt(delivery: Delivery): Promise<Attempt | null> {
		if (
			delivery.status !== 'pending' ||
			inFlight.has(delivery) ||
			delivery.attempts >= MAX_ATTEMPTS
		) {
			return null;
		}
		const store = endpointOf(delivery.storeId);
		if (!store.webhook.active || store.webhook.id !== delivery.endpointId) {
			delivery.status = 'retired';
			return null;
		}
		inFlight.add(delivery);
		delivery.attempts += 1;
		const started = generation;
		try {
			const sent = await send(store, delivery.envelope, false, delivery.tamper);
			if (started !== generation || delivery.status !== 'pending') {
				return sent;
			}
			if (acknowledged(sent.status)) {
				delivery.status = 'delivered';
			} else if (delivery.attempts >= MAX_ATTEMPTS) {
				delivery.status = 'failed';
			}
			return sent;
		} finally {
			inFlight.delete(delivery);
		}
	}

	return {
		deliveries,
		attempts,
		async emit(storeId, events, { order = 'in-order', seed } = {}) {
			const store = state().stores.get(storeId);
			if (store === undefined) {
				return { ok: false, problems: [`there is no store ${storeId}`] };
			}
			if (!store.webhook.active) {
				return {
					ok: false,
					problems: [`store ${storeId} disconnected; its endpoint is revoked`],
				};
			}
			// Every event is read and checked before anything is sent, so a
			// refused emit sends nothing.
			const problems: string[] = [];
			const recorded: Envelope[] = [];
			const dropped = dropping.has(storeId);
			const sends = events.map((event, index) => {
				if ('replay' in event) {
					const emitted = [...deliveries]
						.reverse()
						.find(
							(d) => d.storeId === storeId && d.envelope.id === event.replay,
						);
					if (emitted === undefined) {
						problems.push(
							`events[${index}]: no event ${event.replay} was emitted to ${storeId}`,
						);
						return null;
					}
					return () => send(store, emitted.envelope, true, event.tamper);
				}
				if (!subscribed(store.webhook.eventTypes, event.type)) {
					return null;
				}
				const createdAt = event.createdAt ?? clock.now();
				const envelope: Envelope = {
					id: event.id ?? `${createdAt}-${randomUUID()}`,
					type: event.type,
					payload: event.payload,
					createdAt,
				};
				for (const violation of check(envelope)) {
					problems.push(`events[${index}]: ${violation}`);
				}
				const delivery: Delivery = {
					storeId,
					endpointId: store.webhook.id,
					envelope,
					status: 'pending',
					attempts: 0,
					tamper: event.tamper,
				};
				recorded.push(envelope);
				if (!store.webhook.reachable) {
					return null;
				}
				return () => {
					deliveries.push(delivery);
					if (dropped) {
						delivery.status = 'failed';
						return Promise.resolve(null);
					}
					return attempt(delivery);
				};
			});
			const ready = sends.filter((next) => next !== null);
			if (problems.length > 0) {
				return { ok: false, problems };
			}
			// Recorded as emitted, not as sent: a shuffle reorders the
			// deliveries, not what happened.
			let stream = streams.get(storeId);
			if (stream === undefined) {
				stream = [];
				streams.set(storeId, stream);
			}
			stream.push(...recorded);
			const started = generation;
			const made: Attempt[] = [];
			for (const next of order === 'shuffled' ? shuffled(ready, seed) : ready) {
				if (started !== generation) {
					break;
				}
				const sent = await next();
				if (sent !== null) {
					made.push(sent);
				}
			}
			return { ok: true, attempts: made };
		},
		async retry() {
			const started = generation;
			const made: Attempt[] = [];
			for (const delivery of deliveries.filter((d) => d.status === 'pending')) {
				if (started !== generation) {
					break;
				}
				const sent = await attempt(delivery);
				if (sent !== null) {
					made.push(sent);
				}
			}
			return made;
		},
		async ping(storeId) {
			const store = endpointOf(storeId);
			const envelope: Envelope = {
				id: `ping_${randomUUID()}`,
				type: PING_TYPE,
				payload: { endpointId: store.webhook.id },
				createdAt: clock.now(),
			};
			const problems = check(envelope);
			if (problems.length > 0) {
				throw new Error(
					`The ping breaks the reference: ${problems.join('; ')}`,
				);
			}
			const sent = await send(store, envelope, false, undefined);
			if (acknowledged(sent.status)) {
				return { delivered: true };
			}
			return {
				delivered: false,
				error: sent.error ?? `The endpoint answered ${sent.status}.`,
			};
		},
		failedCount(endpointId) {
			return deliveries.filter(
				(d) => d.endpointId === endpointId && d.status === 'failed',
			).length;
		},
		requeueFailed(endpointId) {
			const failed = deliveries.filter(
				(d) => d.endpointId === endpointId && d.status === 'failed',
			);
			for (const delivery of failed) {
				delivery.status = 'pending';
				delivery.attempts = 0;
			}
			return failed.length;
		},
		events(storeId) {
			return streams.get(storeId) ?? [];
		},
		drop(storeId, drop) {
			if (drop) {
				dropping.add(storeId);
			} else {
				dropping.delete(storeId);
			}
		},
		dropping,
		reset() {
			generation += 1;
			streams.clear();
			dropping.clear();
			aborts.abort();
			aborts = new AbortController();
			inFlight.clear();
			deliveries.length = 0;
			attempts.length = 0;
		},
	};
}
