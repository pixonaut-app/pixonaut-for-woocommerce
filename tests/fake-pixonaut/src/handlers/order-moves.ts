// A store's own moves on an order: settling or moving one of its printshop
// orders by hand, submitting a failed item again, and cancelling one item or
// the whole order. Each answers the order as it is now.
import { allOwned, type State } from '../state';
import { orderBody } from './order-body';
import {
	AWAITING_PRINTS,
	CANCELLED,
	FAILED,
	type Item,
	latestSubmission,
	newSubmission,
	ORDERS,
	type Order,
	OUTCOME_UNKNOWN,
	pathItem,
	pathOrder,
	printshopOrderOf,
	record,
	retryPrints,
	SUBMITTED,
	setItemStatus,
	statusMoves,
	submitItems,
} from './order-model';
import { isPrintshop, printshopFor, takeArmedOutcome } from './order-placing';
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
const CONFLICT_STATUS = 409;
const UNPROCESSABLE_STATUS = 422;

/** Statuses the printshop has made the item in: too late to cancel. */
const MADE_STATUSES = ['printed', 'shipped'];
/** Statuses a cancel may stop an item in. */
const CANCELLABLE_STATUSES = [
	'held',
	AWAITING_PRINTS,
	SUBMITTED,
	'queued',
	OUTCOME_UNKNOWN,
];

type UpdatePrintshopOrderRequest = {
	status: string;
	reason?: string;
	externalOrderRef?: string;
};

/** Whether a printshop order of another order of the tenant, or this one
 *  under another reference, holds the printshop's reference. */
function referenceTaken(
	state: State,
	order: Order,
	printshopOrderId: string,
	ref: string,
): boolean {
	return [...allOwned<Order>(state, ORDERS).values()].some(({ value }) =>
		value.printshopOrders.some(
			(p) =>
				p.externalOrderRef !== null &&
				(p.id === printshopOrderId
					? p.externalOrderRef !== ref
					: value.tenantId === order.tenantId && p.externalOrderRef === ref),
		),
	);
}

/** `PATCH /v1/orders/{id}/printshop-orders/{printshopOrderId}`: moves every
 *  item still in that printshop order. */
export const updatePrintshopOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const printshopOrderId = context.params.printshopOrderId ?? '';
	const printshopOrder = order.printshopOrders.find(
		(p) => p.id === printshopOrderId,
	);
	if (printshopOrder === undefined) {
		return refusal(NOT_FOUND_STATUS, 'not_found');
	}
	const request = context.body as UpdatePrintshopOrderRequest;
	const to = request.status;
	if (request.externalOrderRef !== undefined && to !== SUBMITTED) {
		return refusal(BAD_REQUEST_STATUS, 'bad_request');
	}
	const items = order.items.filter(
		(item) =>
			item.status !== CANCELLED &&
			latestSubmission(item).printshopOrderId === printshopOrderId,
	);
	const [lead] = items;
	const stuck =
		lead === undefined
			? { status: CANCELLED }
			: items.find(
					(item) => !statusMoves(context.state, order, item).includes(to),
				);
	if (stuck !== undefined) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_status_not_allowed', from: stuck.status, to },
		};
	}
	const from = lead?.status;
	if (from === OUTCOME_UNKNOWN && request.reason === undefined) {
		return {
			status: UNPROCESSABLE_STATUS,
			body: { error: 'order_status_reason_required', from },
		};
	}
	const ref = request.externalOrderRef;
	if (
		ref !== undefined &&
		referenceTaken(context.state, order, printshopOrderId, ref)
	) {
		return refusal(CONFLICT_STATUS, 'order_reference_taken');
	}

	const reason = request.reason ?? null;
	const now = context.clock.now();
	if (ref !== undefined) {
		printshopOrder.externalOrderRef = ref;
	}
	for (const item of items) {
		setItemStatus(item, to);
		if (to === FAILED) {
			item.lastError = reason;
		}
		const type = to === FAILED ? 'item.failed' : 'item.status_changed';
		record(order, now, { type, item, reason });
	}
	return { status: 200, body: orderBody(context.state, order) };
};

/** `POST /v1/orders/{id}/items/{itemId}/retry`: submits a failed item again,
 *  as a new submission to the printshop it went to. */
export const retryItem: Handler = (context) => {
	const caller = callerOf(context);
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const item = pathItem(context, order);
	if (item === undefined) {
		return refusal(NOT_FOUND_STATUS, 'item_not_found');
	}
	const atPrintshop =
		(printshopOrderOf(order, item)?.externalOrderRef ?? null) !== null;
	if (item.status !== FAILED || atPrintshop) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'retry_refused', status: item.status, atPrintshop },
		};
	}
	const prints = retryPrints(context.state, caller.storeId, item);
	if (!Array.isArray(prints)) {
		return prints;
	}
	const printshop = printshopFor(
		context,
		caller.storeId,
		order.tenantId,
		prints,
		{ sentTo: item.printshopId },
	);
	if (!isPrintshop(printshop)) {
		return printshop;
	}

	// A failed print is drawn again in place, under its own id, and the item
	// waits for it, as for a print still being drawn. The fake never finishes
	// the drawing on its own.
	for (const print of prints) {
		if (print.status === 'failed') {
			print.status = 'created';
			print.completedAt = null;
		}
	}
	const now = context.clock.now();
	const waiting = prints.some((p) => p.status !== 'completed');
	const outcome = waiting
		? { status: AWAITING_PRINTS, lastError: null }
		: (takeArmedOutcome(context.state, item.externalRef) ?? {
				status: SUBMITTED,
				lastError: null,
			});
	item.submissions.push(newSubmission(outcome.status, now));
	item.status = outcome.status;
	item.lastError = outcome.lastError;
	if (!waiting) {
		submitItems(order, [item], now);
	}
	const type =
		outcome.status === FAILED
			? 'item.failed'
			: waiting
				? 'item.status_changed'
				: 'item.submitted';
	record(order, now, { type, item, reason: outcome.lastError });
	return { status: CREATED_STATUS, body: orderBody(context.state, order) };
};

/** Why `item` cannot be cancelled now; null when it can. */
function cancelRefusal(item: Item): HandlerResult | null {
	if (MADE_STATUSES.includes(item.status)) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_at_printshop', cause: 'made' },
		};
	}
	if (!CANCELLABLE_STATUSES.includes(item.status)) {
		return {
			status: CONFLICT_STATUS,
			body: {
				error: 'order_status_not_allowed',
				from: item.status,
				to: CANCELLED,
			},
		};
	}
	return null;
}

/** Cancels `items` once none refuses it, and answers the order naming each
 *  item cancelled. */
function cancelItems(
	context: HandlerContext,
	order: Order,
	items: Item[],
	type: string,
): HandlerResult {
	for (const item of items) {
		const refused = cancelRefusal(item);
		if (refused !== null) {
			return refused;
		}
	}
	const now = context.clock.now();
	for (const item of items) {
		setItemStatus(item, CANCELLED);
		record(order, now, { type, item });
	}
	return {
		status: 200,
		body: {
			...orderBody(context.state, order),
			cancelledItemIds: items.map((item) => item.id),
		},
	};
}

/** Cancelling asks the printshop, which the fake always answers yes to while
 *  it has not made the order: every item not yet cancelled goes, but a failed
 *  one beside others still on their way, which stays failed. An order of
 *  failed items alone is refused as a failed item is. */
export const cancelOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const live = order.items.filter((item) => item.status !== CANCELLED);
	const going = live.filter((item) => item.status !== FAILED);
	return cancelItems(
		context,
		order,
		going.length > 0 ? going : live,
		'order.cancelled',
	);
};

/** Cancels one item: alone while no printshop has it, else with every item
 *  sent in its printshop order, as a printshop that stops orders whole does;
 *  the fake's printshops cancel no single line. */
export const cancelItem: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const item = pathItem(context, order);
	if (item === undefined) {
		return refusal(NOT_FOUND_STATUS, 'item_not_found');
	}
	if (item.status === CANCELLED) {
		return cancelItems(context, order, [], 'item.cancelled');
	}
	const { printshopOrderId } = latestSubmission(item);
	const taken =
		printshopOrderId === null
			? [item]
			: order.items.filter(
					(other) =>
						other.status !== CANCELLED &&
						latestSubmission(other).printshopOrderId === printshopOrderId,
				);
	return cancelItems(context, order, taken, 'item.cancelled');
};
