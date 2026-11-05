// The control API's "move order": walks a store's order along Pixonaut's
// statuses and emits one `order.status_changed` per step, as Pixonaut does
// when the printshop reports progress. The last step to `shipped` carries
// tracking. `printId` defaults to the lead print of the store's order.
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
	ORDERS as ORDER_RECORDS,
	type Order as PlacedOrder,
} from '../handlers/orders';
import { findOwned } from '../state';
import { type EmitEvent, ORDERS, type Order } from './deliveries';

/** An order's statuses on the way to the customer, in the order Pixonaut
 *  moves through them. */
export const ORDER_CHAIN = ['submitted', 'queued', 'printed', 'shipped'];

export type Tracking = { carrier?: string; number: string; url?: string };

/** Tracking a move to `shipped` carries when the test names none. */
export const DEFAULT_TRACKING: Tracking = {
	carrier: 'PostNord',
	number: 'PN000000001SE',
	url: 'https://tracking.example/PN000000001SE',
};

type Move = {
	store: string;
	orderId: string;
	printId: string;
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

function readMove(body: Body, fake: Fake): Read<Move> {
	const strings: Record<string, string | undefined> = {};
	for (const name of [
		'store',
		'orderId',
		'printId',
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
	if (strings.orderId === undefined) {
		return refused('`orderId` is required');
	}
	const printId =
		strings.printId ??
		findOwned<PlacedOrder>(fake.state, ORDER_RECORDS, strings.orderId, store)
			?.printIds[0];
	if (printId === undefined) {
		return refused('`printId` is required for an order the fake did not place');
	}
	const from = strings.from ?? 'submitted';
	const to = strings.to ?? 'shipped';
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
			orderId: strings.orderId,
			printId,
			externalRef: strings.externalRef ?? null,
			from,
			to,
			tracking: tracking.value,
			order: body.order as Order | undefined,
			seed: body.seed as number | undefined,
		},
	};
}

/** One `order.status_changed` per step from `from` to `to`. */
export function moveEvents(move: Move): EmitEvent[] {
	const steps = ORDER_CHAIN.slice(
		ORDER_CHAIN.indexOf(move.from),
		ORDER_CHAIN.indexOf(move.to) + 1,
	);
	return steps.slice(1).map((status, index) => ({
		type: 'order.status_changed',
		payload: {
			orderId: move.orderId,
			printId: move.printId,
			externalRef: move.externalRef,
			previousStatus: steps[index],
			status,
			via: 'integration',
			...(status === 'shipped' ? { tracking: move.tracking } : {}),
		},
	}));
}

export function registerMoveOrder(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/orders/move`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const move = body.ok ? readMove(body.value, fake) : body;
		if (!move.ok) {
			return move.response;
		}
		const { store, order, seed } = move.value;
		const emitted = await fake.webhooks.emit(store, moveEvents(move.value), {
			order,
			seed,
		});
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});
}
