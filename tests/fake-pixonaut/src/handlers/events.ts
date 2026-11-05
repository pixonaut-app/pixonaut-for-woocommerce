// The store's event stream, pulled to catch up on what its webhook endpoint
// missed: every event the fake emitted to the store, in the order it was
// recorded, whether or not its delivery got through.
import type { Contract } from '../spec';
import { PING_TYPE } from '../webhooks/deliveries';
import { callerOf, type Handler, refusal } from './types';

/** The reference's default `limit`. */
const DEFAULT_LIMIT = 100;

/** Pixonaut looks through at most this many events in one pull, so with
 *  `types` a page can be short, or empty, with a cursor that still moves on. */
export const EVENTS_LOOKED_THROUGH = 1000;

/** Separates the types in `types`. */
const TYPES_SEPARATOR = ',';

/** The event types the reference names: its webhooks, but the ping. */
function eventTypes(contract: Contract): Set<string> {
	return new Set(
		Object.keys(contract.spec.webhooks).filter((type) => type !== PING_TYPE),
	);
}

export const listEvents: Handler = (context) => {
	const { query } = context;
	const stream = context.webhooks.events(callerOf(context).storeId);

	const after = query.get('after');
	const start =
		after === null ? 0 : stream.findIndex((event) => event.id === after) + 1;
	if (start === 0 && after !== null) {
		return refusal(400, 'unknown_event');
	}

	const known = eventTypes(context.contract);
	const types = (query.get('types') ?? '')
		.split(TYPES_SEPARATOR)
		.map((type) => type.trim())
		.filter((type) => type !== '');
	const unknown = types.find((type) => !known.has(type));
	if (unknown !== undefined) {
		return {
			status: 400,
			body: { error: 'invalid_event_type', type: unknown },
		};
	}
	const wanted = types.length === 0 ? null : new Set(types);

	const limit = Number(query.get('limit') ?? DEFAULT_LIMIT);
	const window = stream.slice(start, start + EVENTS_LOOKED_THROUGH);
	const events = [];
	/** How many events of the window this pull looked through. */
	let looked = 0;
	for (const event of window) {
		looked += 1;
		if (wanted === null || wanted.has(event.type)) {
			events.push(event);
			if (events.length === limit) {
				break;
			}
		}
	}
	const last = start + looked - 1;
	const more = looked > 0 && last < stream.length - 1;
	return {
		status: 200,
		body: { events, nextCursor: more ? (stream[last]?.id ?? null) : null },
	};
};
