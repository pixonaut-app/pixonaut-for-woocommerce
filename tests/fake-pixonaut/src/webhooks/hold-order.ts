// The control API's holds: a free workspace past its monthly order limit,
// whose next orders Pixonaut takes as `held`, and the release of one held
// order, by an upgrade or at the month's reset, with the `order.released`
// Pixonaut sends for it. The release moves the order's held items to
// `awaiting_prints`, as Pixonaut's always does, unless the test names another
// item status, and sends no other event.
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
import {
	AWAITING_PRINTS,
	FORWARD_STATUSES,
	HELD,
	ITEM_STATUSES,
	ORDERS,
	type Order,
	orderStatus,
	recordForEach,
	setItemStatus,
	submitItems,
} from '../handlers/order-model';
import { allOwned, type Hold, type OrderHeld } from '../state';
import { ORDERS as DELIVERY_ORDERS, type EmitEvent } from './deliveries';

/** The free plan's monthly order limit when the test names none. */
export const DEFAULT_HOLD_LIMIT = 25;

/** When the month resets when the test names no date. */
export const DEFAULT_PERIOD_END = '2026-11-01T00:00:00.000Z';

export const MONTHLY_ORDER_LIMIT = 'monthly_order_limit';

/** What releases a held order. */
export const RELEASED_BY = ['upgrade', 'month_reset'] as const;

/** Where a released order's items go when the test names no status. */
const DEFAULT_RELEASE_STATUS = AWAITING_PRINTS;

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
	const status = orderStatus(order);
	if (status !== HELD) {
		return refused(`order ${order.id} is ${status}, not held`);
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
	if (status.value !== undefined && !ITEM_STATUSES.includes(status.value)) {
		return refused(`\`status\` is not an item status: ${status.value}`);
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
			externalRef: order.externalRef,
			...order.hold,
		},
	};
}

/** Moves a held order's held items on and records it, as Pixonaut releases
 *  one; an item moved to a printshop's status is handed to its printshop. */
export function release(
	order: Order,
	now: number,
	releasedBy: string,
	status: string,
): EmitEvent {
	order.hold = null;
	const held = order.items.filter((item) => item.status === HELD);
	for (const item of held) {
		setItemStatus(item, status);
	}
	if (FORWARD_STATUSES.includes(status)) {
		submitItems(order, held, now);
	}
	recordForEach(order, now, { type: 'order.released', via: null, releasedBy });
	return {
		type: 'order.released',
		payload: {
			orderId: order.id,
			externalRef: order.externalRef,
			releasedBy,
			status: orderStatus(order),
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
