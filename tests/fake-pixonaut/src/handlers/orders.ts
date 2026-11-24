// Orders: placing one order of items, each of a store's prints or of its
// designs (which prints each first), listing a store's orders and reading one
// with its history. A store's own moves are in `order-moves.ts`; erasing an
// order's personal data is in `erasure.ts`. The fake sends nothing to a
// printshop; an item stays `submitted` until a test moves it.
import { randomUUID } from 'node:crypto';
import { ownedBy, putOwned } from '../state';
import type { Design } from './designs-store';
import { orderBody, orderRow } from './order-body';
import {
	type Address,
	AWAITING_PRINTS,
	FAILED,
	HELD,
	type Item,
	ORDER_STATUSES,
	ORDERS,
	type Order,
	orderStatus,
	pathOrder,
	type Recipient,
	record,
	recordForEach,
	SUBMITTED,
	submitItems,
	VIA_INTEGRATION,
} from './order-model';
import {
	approvedDesigns,
	blankOf,
	type ItemPlacing,
	isPrintshop,
	namedPrints,
	newItem,
	printshopFor,
	refuseOrdered,
	refuseOtherRow,
	refusePrints,
	takeArmedHold,
	takeArmedOutcome,
	variantFor,
} from './order-placing';
import { pageOf } from './paging';
import { type Print, printDesigns, recordPrint } from './prints';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

const CREATED_STATUS = 201;
const BAD_REQUEST_STATUS = 400;
const NOT_FOUND_STATUS = 404;
const UNPROCESSABLE_STATUS = 422;

/** The quantity an item without one is for. */
const DEFAULT_QUANTITY = 1;
/** What a list is ordered by when the request names nothing. */
const DEFAULT_SORT = 'submitted';
/** The list direction that puts the earliest first; any other, the latest. */
const ASCENDING = 'asc';

type ItemRequest = {
	externalRef?: string;
	/** Name `prints` or `designs`, not both. */
	designs?: { designId: string; page?: number }[];
	prints?: { printId: string; page?: number }[];
	variant?: string;
	quantity?: number;
	printshopId?: string | null;
	/** Orders a design again although it has a live item. */
	reorder?: boolean;
};

type CreateOrderRequest = {
	externalRef?: string;
	recipient?: Recipient;
	shippingAddress?: Address;
	shipToAddressId?: string | null;
	printshopId?: string | null;
	items: ItemRequest[];
};

/** A refusal of the item at `index`, which names it; a malformed request is
 *  refused whole. */
function ofItem(result: HandlerResult, index: number): HandlerResult {
	if (result.status === BAD_REQUEST_STATUS) {
		return result;
	}
	return {
		status: result.status,
		body: { ...(result.body as Record<string, unknown>), itemIndex: index },
	};
}

/** Each item's designs, or its prints, as the request names them; or why one
 *  cannot be ordered. */
function namedSources(
	context: HandlerContext,
	storeId: string,
	items: ItemRequest[],
): ({ designs: Design[] } | { prints: Print[] })[] | HandlerResult {
	const sources: ({ designs: Design[] } | { prints: Print[] })[] = [];
	for (const [index, item] of items.entries()) {
		const found =
			item.designs !== undefined
				? approvedDesigns(context, storeId, item.designs)
				: namedPrints(context, storeId, item.prints ?? []);
		if (!Array.isArray(found)) {
			return ofItem(found, index);
		}
		sources.push(
			item.designs !== undefined
				? { designs: found as Design[] }
				: { prints: found as Print[] },
		);
	}
	return sources;
}

/** What each item is placed with, or why one is refused. */
function itemPlacings(
	context: HandlerContext,
	request: CreateOrderRequest,
	printsOf: Print[][],
): ItemPlacing[] | HandlerResult {
	const { storeId, tenantId } = callerOf(context);
	const placings: ItemPlacing[] = [];
	for (const [index, item] of request.items.entries()) {
		const prints = printsOf[index] ?? [];
		const refused = refusePrints(context, storeId, prints);
		if (refused !== null) {
			return ofItem(refused, index);
		}
		const [lead] = prints;
		const blank =
			lead === undefined ? undefined : blankOf(context, storeId, lead);
		const chosen = variantFor(blank, item.variant);
		if (!('variant' in chosen)) {
			return ofItem(chosen, index);
		}
		const otherRow = refuseOtherRow(
			context.state,
			storeId,
			prints,
			chosen.variant,
		);
		if (otherRow !== null) {
			return ofItem(otherRow, index);
		}
		const printshop = printshopFor(context, storeId, tenantId, prints, {
			named: item.printshopId ?? request.printshopId,
		});
		if (!isPrintshop(printshop)) {
			return ofItem(printshop, index);
		}
		const ordered =
			item.reorder === true
				? null
				: refuseOrdered(context.state, storeId, prints);
		if (ordered !== null) {
			return ofItem(ordered, index);
		}
		placings.push({
			externalRef: item.externalRef ?? null,
			prints,
			blank,
			variant: chosen.variant,
			quantity: item.quantity ?? DEFAULT_QUANTITY,
			printshop,
		});
	}
	return placings;
}

/** Places the order as its printshops answer it now, and records it: held
 *  whole when a hold is armed; else each item waits for its prints, fails
 *  unsent at a printshop that cannot take it, or is submitted, as an armed
 *  outcome says. Items its printshop answered alike go in one printshop
 *  order. */
function placeOrder(
	context: HandlerContext,
	request: CreateOrderRequest,
	placings: ItemPlacing[],
): Order {
	const { storeId, tenantId } = callerOf(context);
	const now = context.clock.now();
	const hold = takeArmedHold(context.state);
	const order: Order = {
		id: `ord_${randomUUID()}`,
		storeId,
		tenantId,
		externalRef: request.externalRef ?? null,
		recipient: request.recipient ?? null,
		shippingAddress: request.shippingAddress ?? null,
		hold,
		items: [],
		printshopOrders: [],
		parcels: [],
		personalDataErasedAt: null,
		delivery: null,
		deliveryChanges: [],
		createdAt: now,
		updatedAt: now,
		history: [],
	};
	const sent: Item[] = [];
	for (const placing of placings) {
		let outcome = { status: SUBMITTED, lastError: null as string | null };
		let handed = false;
		if (hold !== null) {
			// Held before it is sent: its prints wait with it.
			outcome = { status: HELD, lastError: null };
		} else if (placing.prints.some((p) => p.status !== 'completed')) {
			outcome = { status: AWAITING_PRINTS, lastError: null };
		} else if (placing.printshop.refused !== null) {
			outcome = { status: FAILED, lastError: placing.printshop.refused };
		} else {
			outcome = takeArmedOutcome(context.state, placing.externalRef) ?? outcome;
			handed = true;
		}
		const item = newItem(context, storeId, placing, outcome.status, now);
		item.lastError = outcome.lastError;
		order.items.push(item);
		if (handed) {
			sent.push(item);
		}
	}
	for (const status of new Set(sent.map((item) => item.status))) {
		submitItems(
			order,
			sent.filter((item) => item.status === status),
			now,
		);
	}
	recordForEach(order, now, { type: 'order.created', via: VIA_INTEGRATION });
	for (const item of order.items) {
		const type =
			item.status === HELD
				? 'order.held'
				: item.status === FAILED
					? 'item.failed'
					: sent.includes(item)
						? 'item.submitted'
						: null;
		if (type !== null) {
			record(order, now, {
				type,
				item,
				reason: item.lastError,
				via: VIA_INTEGRATION,
			});
		}
	}
	putOwned(context.state, ORDERS, order.id, storeId, order);
	return order;
}

export const createOrder: Handler = (context) => {
	const caller = callerOf(context);
	const request = context.body as CreateOrderRequest;
	if (
		request.items.length === 0 ||
		request.items.some(
			(item) => (item.prints === undefined) === (item.designs === undefined),
		)
	) {
		return refusal(BAD_REQUEST_STATUS, 'bad_request');
	}
	// The fake keeps no address book, so it knows no saved address.
	if (typeof request.shipToAddressId === 'string') {
		return refusal(UNPROCESSABLE_STATUS, 'saved_address_not_found');
	}
	const sources = namedSources(context, caller.storeId, request.items);
	if (!Array.isArray(sources)) {
		return sources;
	}
	// The designs named are printed together, so their print numbers never
	// repeat, and the prints are recorded only once nothing refuses the order.
	const made = printDesigns(
		context.state,
		sources.flatMap((source) => ('designs' in source ? source.designs : [])),
		caller.storeId,
		context.clock.now(),
	);
	let next = 0;
	const printsOf = sources.map((source) => {
		if ('prints' in source) {
			return source.prints;
		}
		const prints = made.slice(next, next + source.designs.length);
		next += source.designs.length;
		return prints;
	});
	const placings = itemPlacings(context, request, printsOf);
	if (!Array.isArray(placings)) {
		return placings;
	}
	for (const print of made) {
		recordPrint(context.state, caller.storeId, print);
	}
	const order = placeOrder(context, request, placings);
	return {
		status: CREATED_STATUS,
		body: orderBody(context.state, order),
		// A replay shows the order as it is now.
		replay: () => ({
			status: CREATED_STATUS,
			body: orderBody(context.state, order),
		}),
	};
};

/** The statuses `status` names, repeated or comma-separated; empty for
 *  every status. */
function statusesOf(query: URLSearchParams): string[] {
	return query
		.getAll('status')
		.flatMap((s) => s.split(','))
		.map((s) => s.trim())
		.filter((s) => s !== '');
}

/** Whether `order` matches every filter of `query` but its status
 *  (Pixonaut's `filterPredicates`): `counts` is counted under these. An
 *  order matches an item filter when one of its items does. */
function matchesFilters(order: Order, query: URLSearchParams): boolean {
	const printId = query.get('printId');
	const designId = query.get('designId');
	const externalRef = query.get('externalRef');
	const printshop = query.get('printshop');
	const blankId = query.get('blankId');
	const variant = query.get('variant');
	const from = query.get('submittedFrom');
	const to = query.get('submittedTo');
	const q = query.get('q')?.toLowerCase() ?? '';
	const anyItem = (test: (item: Item) => boolean) => order.items.some(test);
	return (
		(printId === null || anyItem((i) => i.printIds.includes(printId))) &&
		(designId === null ||
			anyItem((i) => i.prints.some((p) => p.designId === designId))) &&
		(externalRef === null || order.externalRef === externalRef) &&
		(printshop === null || anyItem((i) => i.printshopId === printshop)) &&
		(blankId === null || anyItem((i) => i.blankId === blankId)) &&
		(variant === null || anyItem((i) => i.variant?.ref === variant)) &&
		(from === null || order.createdAt >= Number(from)) &&
		(to === null || order.createdAt <= Number(to)) &&
		(q === '' ||
			[order.externalRef, order.id, order.recipient?.name].some(
				(text) => text?.toLowerCase().startsWith(q) === true,
			))
	);
}

/** How many of `orders` each status holds, every status named. */
function statusCounts(orders: Order[]): Record<string, number> {
	const counts = Object.fromEntries(
		ORDER_STATUSES.map((status) => [status, 0]),
	);
	for (const order of orders) {
		const status = orderStatus(order);
		counts[status] = (counts[status] ?? 0) + 1;
	}
	return counts;
}

/** `GET /v1/orders`: the store's orders the query keeps, the latest
 *  submitted first unless `sort` and `order` say otherwise, with how many
 *  each status holds under every filter but `status`. Orders made in the same
 *  millisecond keep the order they were placed in, read in the direction
 *  asked. */
export const listOrders: Handler = (context) => {
	const caller = callerOf(context);
	const { query } = context;
	const statuses = statusesOf(query);
	if (statuses.some((status) => !ORDER_STATUSES.includes(status))) {
		return refusal(UNPROCESSABLE_STATUS, 'invalid_query');
	}
	const matching = ownedBy<Order>(context.state, ORDERS, caller.storeId).filter(
		(o) => matchesFilters(o, query),
	);
	const bySubmission = (query.get('sort') ?? DEFAULT_SORT) === DEFAULT_SORT;
	const at = (o: Order) => (bySubmission ? o.createdAt : o.updatedAt);
	const ascending = [...matching].sort((a, b) => at(a) - at(b));
	const sorted =
		query.get('order') === ASCENDING ? ascending : ascending.reverse();
	const orders = sorted.filter(
		(o) => statuses.length === 0 || statuses.includes(orderStatus(o)),
	);
	const page = pageOf(orders, query);
	return {
		status: 200,
		body: {
			orders: page.rows.map((o) => orderRow(context.state, o)),
			total: page.total,
			page: page.page,
			pageSize: page.pageSize,
			counts: statusCounts(matching),
		},
	};
};

export const readOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	return { status: 200, body: orderBody(context.state, order) };
};
