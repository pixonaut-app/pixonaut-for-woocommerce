// The control API's holds: a free workspace past its monthly order limit,
// whose next orders Pixonaut takes as `held`, and the release of one held
// order, by an upgrade or at the month's reset, with the `order.released`
// Pixonaut sends for it. The release moves the order to `awaiting_prints`, as
// Pixonaut's always does, unless the test names another status, and sends no
// `order.status_changed`.
import type { Hono } from 'hono';
import type { Fake } from '../app';
import {
	type Body,
	jsonBody,
	optionalString,
	PREFIX,
	type Read,
	readTimes,
	refused,
} from '../control';
import { HELD, ORDERS, type Order } from '../handlers/orders';
import { allOwned, type Hold, type OrderHeld } from '../state';
import { ORDERS as DELIVERY_ORDERS, type EmitEvent } from './deliveries';

/** The free plan's monthly order limit when the test names none. */
export const DEFAULT_HOLD_LIMIT = 25;

/** When the month resets when the test names no date. */
export const DEFAULT_PERIOD_END = '2026-11-01T00:00:00.000Z';

export const MONTHLY_ORDER_LIMIT = 'monthly_order_limit';

/** What releases a held order. */
export const RELEASED_BY = ['upgrade', 'month_reset'] as const;

/** Where a released order goes when the test names no status. */
const DEFAULT_RELEASE_STATUS = 'awaiting_prints';

type Release = {
	store: string;
	order: Order;
	releasedBy: string;
	status: string;
	delivery?: (typeof DELIVERY_ORDERS)[number];
	seed?: number;
};

function readHeld(body: Body): Read<OrderHeld> {
	const reason = optionalString(body, 'reason');
	if (!reason.ok) {
		return reason;
	}
	const periodEnd = optionalString(body, 'periodEnd');
	if (!periodEnd.ok) {
		return periodEnd;
	}
	if (
		body.limit !== undefined &&
		!(Number.isInteger(body.limit) && (body.limit as number) > 0)
	) {
		return refused('`limit` is not a positive integer');
	}
	const times = readTimes(body);
	if (!times.ok) {
		return times;
	}
	const hold: Hold = {
		reason: reason.value ?? MONTHLY_ORDER_LIMIT,
		limit: (body.limit as number | undefined) ?? DEFAULT_HOLD_LIMIT,
		periodEnd: periodEnd.value ?? DEFAULT_PERIOD_END,
	};
	return { ok: true, value: { outcome: 'held', hold, remaining: times.value } };
}

/** The held order a request names: of `store` when it names one, of
 *  whichever store placed it otherwise. */
function readHeldOrder(
	body: Body,
	fake: Fake,
): Read<{ store: string; order: Order }> {
	const store = optionalString(body, 'store');
	if (!store.ok) {
		return store;
	}
	const orderId = optionalString(body, 'orderId');
	if (!orderId.ok) {
		return orderId;
	}
	if (orderId.value === undefined) {
		return refused('`orderId` is required');
	}
	const owned = allOwned<Order>(fake.state, ORDERS).get(orderId.value);
	if (
		owned === undefined ||
		(store.value !== undefined && owned.storeId !== store.value)
	) {
		return refused(`no store placed an order ${orderId.value} it may release`);
	}
	const order = owned.value;
	if (order.status !== HELD) {
		return refused(`order ${order.id} is ${order.status}, not held`);
	}
	return { ok: true, value: { store: owned.storeId, order } };
}

function readRelease(body: Body, fake: Fake): Read<Release> {
	const held = readHeldOrder(body, fake);
	if (!held.ok) {
		return held;
	}
	const releasedBy = optionalString(body, 'releasedBy');
	if (!releasedBy.ok) {
		return releasedBy;
	}
	const status = optionalString(body, 'status');
	if (!status.ok) {
		return status;
	}
	const by = releasedBy.value ?? RELEASED_BY[0];
	if (!(RELEASED_BY as readonly string[]).includes(by)) {
		return refused(`\`releasedBy\` is not one of ${RELEASED_BY.join(', ')}`);
	}
	if (
		body.order !== undefined &&
		!DELIVERY_ORDERS.includes(body.order as (typeof DELIVERY_ORDERS)[number])
	) {
		return refused(`\`order\` is not one of ${DELIVERY_ORDERS.join(', ')}`);
	}
	if (body.seed !== undefined && !Number.isInteger(body.seed)) {
		return refused('`seed` is not an integer');
	}
	return {
		ok: true,
		value: {
			...held.value,
			releasedBy: by,
			status: status.value ?? DEFAULT_RELEASE_STATUS,
			delivery: body.order as Release['delivery'],
			seed: body.seed as number | undefined,
		},
	};
}

/** The `order.held` Pixonaut sends once for an order placed held. */
export function heldEvent(order: Order): EmitEvent {
	if (order.hold === null) {
		throw new Error(`Order ${order.id} is not held.`);
	}
	return {
		type: 'order.held',
		payload: {
			orderId: order.id,
			printId: order.printIds[0],
			printIds: order.printIds,
			externalRef: order.externalRef,
			...order.hold,
		},
	};
}

/** Moves a held order on and records it, as Pixonaut releases one. */
export function release(
	order: Order,
	now: number,
	releasedBy: string,
	status: string,
): EmitEvent {
	order.status = status;
	order.hold = null;
	order.updatedAt = now;
	order.history.push({
		seq: order.history.length + 1,
		at: now,
		type: 'order.released',
		status,
		reason: null,
		via: null,
		automation: null,
		releasedBy,
	});
	return {
		type: 'order.released',
		payload: {
			orderId: order.id,
			printId: order.printIds[0],
			printIds: order.printIds,
			externalRef: order.externalRef,
			releasedBy,
			status,
		},
	};
}

export function registerHoldOrder(app: Hono, fake: Fake): void {
	// The next orders placed answer `201` with status `held`.
	app.post(`${PREFIX}/orders/hold`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const held = body.ok ? readHeld(body.value) : body;
		if (!held.ok) {
			return held.response;
		}
		fake.state.armedOrderOutcome = held.value;
		return c.json(held.value);
	});

	// Sends `order.held` for an order placed held, as Pixonaut does once.
	app.post(`${PREFIX}/orders/held-event`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readHeldOrder(body.value, fake) : body;
		if (!read.ok) {
			return read.response;
		}
		const emitted = await fake.webhooks.emit(read.value.store, [
			heldEvent(read.value.order),
		]);
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});

	app.post(`${PREFIX}/orders/release`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readRelease(body.value, fake) : body;
		if (!read.ok) {
			return read.response;
		}
		const { store, order, releasedBy, status, delivery, seed } = read.value;
		const event = release(order, fake.clock.now(), releasedBy, status);
		const emitted = await fake.webhooks.emit(store, [event], {
			order: delivery,
			seed,
		});
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});
}
