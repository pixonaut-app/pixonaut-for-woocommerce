// The control API's "move order": walks items of a store's order along
// Pixonaut's statuses and emits one `item.status_changed` per step of each
// item, as Pixonaut does when the printshop reports progress, then
// `order.status_changed` whenever the order's own status changes with a step.
// For an order the fake placed, the items move in its state too, and the step
// to `shipped` puts them in a parcel with the tracking (no parcel event is
// sent). `itemId`, `itemExternalRef`, `printId`, or an `externalRef` that is
// an item's, picks the items; without one every item not cancelled moves. An
// order the fake did not place is moved as one item, named by `itemId` or
// `printId`.
import { randomUUID } from 'node:crypto';
import type { Hono } from 'hono';
import type { Fake } from '../app';
import {
	type Body,
	jsonBody,
	optionalString,
	PREFIX,
	type Read,
	refused,
} from '../control';
import { FIXTURE } from '../fixture-ids';
import {
	CANCELLED,
	FORWARD_STATUSES,
	type Item,
	latestSubmission,
	ORDERS as ORDER_RECORDS,
	orderStatus,
	type Order as PlacedOrder,
	printshopOrderOf,
	record,
	SHIPPED,
	setItemStatus,
	submitItems,
	type Tracking,
	VIA_INTEGRATION,
} from '../handlers/order-model';
import { findOwned } from '../state';
import { type EmitEvent, ORDERS, type Order } from './deliveries';
import {
	type EventItem,
	type EventOrder,
	itemStatusChanged,
	orderStatusChanged,
} from './order-events';

/** An item's statuses on the way to the customer, in the order Pixonaut
 *  moves through them. */
export const ORDER_CHAIN = FORWARD_STATUSES;

/** Tracking a move to `shipped` carries when the test names none. */
export const DEFAULT_TRACKING: Tracking = {
	carrier: 'PostNord',
	number: 'PN000000001SE',
	url: 'https://tracking.example/PN000000001SE',
};

/** The status of an order whose items are on their way, none shipped. */
const OPEN = 'open';

type Move = {
	store: string;
	orderId: string;
	/** The order the fake placed and the items of it to move; undefined
	 *  for an order it did not place. */
	placed?: { order: PlacedOrder; items: Item[] };
	/** The one item of an order the fake did not place. */
	named?: EventItem;
	externalRef: string | null;
	from: string;
	to: string;
	tracking: Tracking;
	order?: Order;
	seed?: number;
};

function readTracking(body: Body): Read<Tracking> {
	if (body.tracking === undefined) {
		return { ok: true, value: DEFAULT_TRACKING };
	}
	const tracking = body.tracking as Body | null;
	if (
		tracking === null ||
		typeof tracking !== 'object' ||
		typeof tracking.number !== 'string'
	) {
		return refused('`tracking` is not an object with a `number`');
	}
	return { ok: true, value: tracking as Tracking };
}

type Picks = Record<string, string | undefined>;

/** The placed order's items a move picks; every item not cancelled when it
 *  names none. */
function pickedItems(order: PlacedOrder, picks: Picks): Item[] {
	const { itemId, itemExternalRef, printId, externalRef } = picks;
	if (itemId !== undefined) {
		return order.items.filter((item) => item.id === itemId);
	}
	if (itemExternalRef !== undefined) {
		return order.items.filter((item) => item.externalRef === itemExternalRef);
	}
	if (printId !== undefined) {
		return order.items.filter((item) => item.printIds.includes(printId));
	}
	const byRef = order.items.filter(
		(item) => externalRef !== undefined && item.externalRef === externalRef,
	);
	return byRef.length > 0
		? byRef
		: order.items.filter((item) => item.status !== CANCELLED);
}

/** The one item of an order the fake did not place, as the move names it. */
function namedItem(orderId: string, picks: Picks): EventItem | undefined {
	const { itemId, printId } = picks;
	if (itemId === undefined && printId === undefined) {
		return undefined;
	}
	return {
		id: itemId ?? `itm_${orderId}`,
		externalRef: picks.itemExternalRef ?? null,
		submissionId: `sub_${orderId}`,
		printIds: printId === undefined ? [] : [printId],
	};
}

function readMove(body: Body, fake: Fake): Read<Move> {
	const strings: Picks = {};
	for (const name of [
		'store',
		'orderId',
		'printId',
		'itemId',
		'itemExternalRef',
		'externalRef',
		'from',
		'to',
	]) {
		const value = optionalString(body, name);
		if (!value.ok) {
			return value;
		}
		strings[name] = value.value;
	}
	const store = strings.store ?? FIXTURE.store.main.id;
	if (!fake.state.stores.has(store)) {
		return refused(`there is no store ${store}`);
	}
	const { orderId } = strings;
	if (orderId === undefined) {
		return refused('`orderId` is required');
	}
	const placed = findOwned<PlacedOrder>(
		fake.state,
		ORDER_RECORDS,
		orderId,
		store,
	);
	const named = placed === undefined ? namedItem(orderId, strings) : undefined;
	if (placed === undefined && named === undefined) {
		return refused(
			'`printId` or `itemId` is required for an order the fake did not place',
		);
	}
	const items = placed === undefined ? [] : pickedItems(placed, strings);
	if (placed !== undefined && items.length === 0) {
		return refused(`order ${orderId} holds no item the move picks`);
	}
	const from = strings.from ?? 'submitted';
	const to = strings.to ?? SHIPPED;
	const start = ORDER_CHAIN.indexOf(from);
	const end = ORDER_CHAIN.indexOf(to);
	if (start === -1 || end === -1 || end <= start) {
		return refused(
			`\`from\` and \`to\` must be statuses of ${ORDER_CHAIN.join(', ')}, \`to\` after \`from\``,
		);
	}
	const tracking = readTracking(body);
	if (!tracking.ok) {
		return tracking;
	}
	if (body.order !== undefined && !ORDERS.includes(body.order as Order)) {
		return refused(`\`order\` is not one of ${ORDERS.join(', ')}`);
	}
	if (body.seed !== undefined && !Number.isInteger(body.seed)) {
		return refused('`seed` is not an integer');
	}
	return {
		ok: true,
		value: {
			store,
			orderId,
			placed: placed === undefined ? undefined : { order: placed, items },
			named,
			externalRef: placed?.externalRef ?? strings.externalRef ?? null,
			from,
			to,
			tracking: tracking.value,
			order: body.order as Order | undefined,
			seed: body.seed as number | undefined,
		},
	};
}

/** The statuses a move steps through after `from`, up to `to`. */
function stepsOf(move: Move): string[] {
	return ORDER_CHAIN.slice(
		ORDER_CHAIN.indexOf(move.from) + 1,
		ORDER_CHAIN.indexOf(move.to) + 1,
	);
}

/** The steps of an order the fake did not place: its one item's, and the
 *  order shipped with it. */
function namedEvents(move: Move, item: EventItem): EmitEvent[] {
	const order: EventOrder = { id: move.orderId, externalRef: move.externalRef };
	let previous = move.from;
	return stepsOf(move).flatMap((status) => {
		const events = [
			itemStatusChanged(order, item, previous, status, VIA_INTEGRATION),
		];
		if (status === SHIPPED) {
			events.push(orderStatusChanged(order, OPEN, SHIPPED, VIA_INTEGRATION));
		}
		previous = status;
		return events;
	});
}

/** Puts the items that just shipped in one parcel per printshop order. */
function shipParcels(
	order: PlacedOrder,
	items: Item[],
	tracking: Tracking,
	now: number,
): void {
	for (const item of items) {
		const printshopOrderId = printshopOrderOf(order, item)?.id;
		if (printshopOrderId === undefined) {
			continue;
		}
		const parcel = order.parcels.find(
			(p) => p.printshopOrderId === printshopOrderId && p.shippedAt === null,
		);
		const entry = { itemId: item.id, quantity: item.quantity };
		if (parcel !== undefined) {
			parcel.items.push(entry);
			continue;
		}
		order.parcels.push({
			id: `pcl_${randomUUID()}`,
			printshopOrderId,
			status: SHIPPED,
			tracking: { ...tracking },
			shippedAt: null,
			items: [entry],
		});
	}
	for (const parcel of order.parcels) {
		parcel.shippedAt ??= new Date(now).toISOString();
	}
}

/** Moves the placed order's items in its state, step by step, and answers
 *  the events each step sends. */
function placedEvents(
	move: Move,
	{ order: placed, items }: { order: PlacedOrder; items: Item[] },
	now: number,
): EmitEvent[] {
	const order: EventOrder = { id: placed.id, externalRef: move.externalRef };
	let orderWas = orderStatus(placed);
	// An item still waiting is handed to its printshop to be moved.
	submitItems(placed, items, now);
	for (const item of items) {
		setItemStatus(item, move.from);
	}
	let previous = move.from;
	const events: EmitEvent[] = [];
	for (const status of stepsOf(move)) {
		for (const item of items) {
			setItemStatus(item, status);
			record(placed, now, {
				type: 'item.status_changed',
				item,
				via: VIA_INTEGRATION,
			});
			const named: EventItem = {
				id: item.id,
				externalRef: item.externalRef,
				submissionId: latestSubmission(item).id,
				printIds: item.printIds,
			};
			events.push(
				itemStatusChanged(order, named, previous, status, VIA_INTEGRATION),
			);
		}
		if (status === SHIPPED) {
			shipParcels(placed, items, move.tracking, now);
		}
		const orderIs = orderStatus(placed);
		if (orderIs !== orderWas) {
			events.push(
				orderStatusChanged(order, orderWas, orderIs, VIA_INTEGRATION),
			);
			orderWas = orderIs;
		}
		previous = status;
	}
	return events;
}

/** The events a move sends, the fake's own order moved as they say. */
export function moveEvents(move: Move, now: number): EmitEvent[] {
	if (move.placed !== undefined) {
		return placedEvents(move, move.placed, now);
	}
	return move.named === undefined ? [] : namedEvents(move, move.named);
}

export function registerMoveOrder(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/orders/move`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const move = body.ok ? readMove(body.value, fake) : body;
		if (!move.ok) {
			return move.response;
		}
		const { store, order, seed } = move.value;
		const events = moveEvents(move.value, fake.clock.now());
		const emitted = await fake.webhooks.emit(store, events, { order, seed });
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});
}
