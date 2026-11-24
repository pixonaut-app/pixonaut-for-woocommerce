// How the fake keeps an order: one order holds the items a store placed
// together, the printshop orders those items were submitted in, and the
// parcels they left in. Each item moves through the item statuses on its own;
// the order's status is read from its items'. Answering an order is in
// `order-body.ts`; placing and moving one is elsewhere.
import { randomUUID } from 'node:crypto';
import { findOwned, type Hold, type State } from '../state';
import { findDesign } from './designs-store';
import { findPrint, type Print } from './prints';
import {
	callerOf,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

/** The `records` collection orders are kept in. */
export const ORDERS = 'orders';

const NOT_FOUND_STATUS = 404;
const CONFLICT_STATUS = 409;

/** A free workspace's order past its monthly limit, not sent yet: the
 *  order's status and each of its items'. */
export const HELD = 'held';
/** An item whose prints are still being drawn: no printshop has it yet. */
export const AWAITING_PRINTS = 'awaiting_prints';
export const SUBMITTED = 'submitted';
export const SHIPPED = 'shipped';
export const FAILED = 'failed';
export const OUTCOME_UNKNOWN = 'outcome_unknown';
export const CANCELLED = 'cancelled';
/** The statuses an item moves forward through at its printshop. */
export const FORWARD_STATUSES = [SUBMITTED, 'queued', 'printed', SHIPPED];
/** Every status an item can be in. */
export const ITEM_STATUSES = [
	HELD,
	AWAITING_PRINTS,
	...FORWARD_STATUSES,
	FAILED,
	OUTCOME_UNKNOWN,
	CANCELLED,
];
/** Statuses no move leaves. */
export const FINAL_STATUSES = [SHIPPED, FAILED, CANCELLED];
/** Statuses of an item that is no longer its design's live item: a design has
 *  one live item, in any other status. */
export const DEAD_STATUSES = [FAILED, CANCELLED];
/** The statuses an item whose outcome is unknown is settled to. */
const SETTLED_STATUSES = [SUBMITTED, FAILED];
/** Item statuses that call for the store: the order needs attention. */
const ATTENTION_STATUSES = [FAILED, OUTCOME_UNKNOWN];

/** The statuses an order is read in, from its items'. The fake never closes
 *  an order, so none is `done`. */
export const ORDER_STATUSES = [
	HELD,
	'open',
	'partly_shipped',
	SHIPPED,
	'needs_attention',
	CANCELLED,
	'done',
];

/** How a store's own move shows in an order's history. */
export const VIA_STORE_KEY = 'store_key';
/** How an integration key shows in an order's history. */
export const VIA_INTEGRATION = 'integration';

export type Recipient = { name: string; email?: string; phone?: string };
export type Address = {
	line1: string;
	line2?: string;
	city: string;
	region?: string;
	postalCode?: string;
	country: string;
	company?: string;
};

export type Tracking = { carrier?: string; number: string; url?: string };

/** One step of an order, always of one of its items. */
export type HistoryEntry = {
	seq: number;
	at: number;
	type: string;
	/** The item's status after the step. */
	status: string;
	itemId: string;
	reason: string | null;
	via: string | null;
	/** The automation that made the step; the fake runs none. */
	automation: { id: string; name: string } | null;
	/** What released a held order, on `order.released`; null otherwise. */
	releasedBy: string | null;
};

/** A printshop field as an order sent it. */
type SentField = { name: string; value: string; source: string };

/** The variant as the item placed it: later edits do not change it. */
export type PlacedVariant = {
	ref: string;
	options: Record<string, string>;
	/** The fake's printshops declare no variant fields. */
	fields: SentField[];
};

export type OrderPrint = {
	printId: string;
	designId: string;
	/** The design's revision the print was made from. */
	designRevision: number;
	placementId: string | null;
	printedAs: string | null;
	spotOutput: string;
	placementFields: SentField[];
	/** The print's status as it was ordered. */
	status: string;
};

/** One sending of an item to its printshop; a retry adds another. */
export type Submission = {
	id: string;
	/** Null until the item is handed to a printshop. */
	printshopOrderId: string | null;
	status: string;
	submittedAt: number;
};

/** The order one printshop was handed, holding some of the order's items. */
export type PrintshopOrder = {
	id: string;
	printshopId: string;
	printshopName: string;
	adapter: string;
	/** The printshop's own reference for it. */
	externalOrderRef: string | null;
	itemIds: string[];
	submittedAt: number;
};

/** A parcel that left a printshop with some of the order's items. */
export type Parcel = {
	id: string;
	printshopOrderId: string;
	status: string;
	tracking: Tracking | null;
	shippedAt: string | null;
	items: { itemId: string; quantity: number | null }[];
};

export type Item = {
	id: string;
	externalRef: string | null;
	status: string;
	quantity: number;
	printIds: string[];
	prints: OrderPrint[];
	/** The blank the prints are on; null for prints on no blank. */
	blankId: string | null;
	variant: PlacedVariant | null;
	printshopId: string;
	printshopName: string;
	printshopAdapter: string;
	lastError: string | null;
	/** Why Pixonaut failed it rather than its printshop. The fake's prints
	 *  that render never complete, so a waiting item never fails. */
	failureKind: string | null;
	/** Oldest first; never empty. */
	submissions: Submission[];
};

/** What the printshops made of an address change. */
export type Delivery = { state: string; words: string | null; at: number };

/** An address change as `order.delivery_changed` records it. */
export type DeliveryChange = {
	at: number;
	added: boolean;
	by: string;
	outcome: string;
	words: string | null;
};

export type Order = {
	id: string;
	storeId: string;
	tenantId: string;
	externalRef: string | null;
	recipient: Recipient | null;
	shippingAddress: Address | null;
	/** Why a free workspace holds it, while it does; null otherwise. */
	hold: Hold | null;
	/** In the order the request named them. */
	items: Item[];
	printshopOrders: PrintshopOrder[];
	parcels: Parcel[];
	/** When its personal data was erased, epoch ms; null until it is. */
	personalDataErasedAt: number | null;
	/** Its latest address change after it was sent; null for none. */
	delivery: Delivery | null;
	/** Every address change after it was sent, oldest first. */
	deliveryChanges: DeliveryChange[];
	createdAt: number;
	updatedAt: number;
	history: HistoryEntry[];
};

/** The order's status, read from its items': cancelled once every item is,
 *  held while the order is, needing attention while an item failed or its
 *  outcome is unknown, shipped once every item not cancelled has, partly
 *  shipped once one has, and open otherwise. */
export function orderStatus(order: Order): string {
	const live = order.items.filter((item) => item.status !== CANCELLED);
	if (live.length === 0) {
		return CANCELLED;
	}
	if (order.hold !== null || live.every((item) => item.status === HELD)) {
		return HELD;
	}
	if (live.some((item) => ATTENTION_STATUSES.includes(item.status))) {
		return 'needs_attention';
	}
	const shipped = live.filter((item) => item.status === SHIPPED).length;
	if (shipped === live.length) {
		return SHIPPED;
	}
	return shipped > 0 ? 'partly_shipped' : 'open';
}

/** The item's latest submission. */
export function latestSubmission(item: Item): Submission {
	const latest = item.submissions.at(-1);
	if (latest === undefined) {
		throw new Error(`Item ${item.id} has no submission.`);
	}
	return latest;
}

/** A first submission, not yet handed to a printshop. */
export function newSubmission(status: string, now: number): Submission {
	return {
		id: `sub_${randomUUID()}`,
		printshopOrderId: null,
		status,
		submittedAt: now,
	};
}

/** Moves the item, and its latest submission with it. */
export function setItemStatus(item: Item, status: string): void {
	item.status = status;
	latestSubmission(item).status = status;
}

/** The printshop order the item's latest submission went in, if any. */
export function printshopOrderOf(
	order: Order,
	item: Item,
): PrintshopOrder | undefined {
	const { printshopOrderId } = latestSubmission(item);
	return order.printshopOrders.find((p) => p.id === printshopOrderId);
}

/** Hands `items` to their printshops, one printshop order per printshop,
 *  unless their latest submission went in one already. */
export function submitItems(order: Order, items: Item[], now: number): void {
	const unsent = items.filter(
		(item) => latestSubmission(item).printshopOrderId === null,
	);
	for (const printshopId of new Set(unsent.map((i) => i.printshopId))) {
		const sent = unsent.filter((item) => item.printshopId === printshopId);
		const [lead] = sent;
		if (lead === undefined) {
			continue;
		}
		const printshopOrder: PrintshopOrder = {
			id: `pso_${randomUUID()}`,
			printshopId,
			printshopName: lead.printshopName,
			adapter: lead.printshopAdapter,
			externalOrderRef: null,
			itemIds: sent.map((item) => item.id),
			submittedAt: now,
		};
		order.printshopOrders.push(printshopOrder);
		for (const item of sent) {
			latestSubmission(item).printshopOrderId = printshopOrder.id;
		}
	}
}

/** A step to record: of one item, which is in the status it ends in. */
export type Step = {
	type: string;
	item: Item;
	reason?: string | null;
	via?: string | null;
	releasedBy?: string | null;
};

/** Records `step` in the order's history. */
export function record(order: Order, now: number, step: Step): void {
	order.updatedAt = now;
	order.history.push({
		seq: order.history.length + 1,
		at: now,
		type: step.type,
		status: step.item.status,
		itemId: step.item.id,
		reason: step.reason ?? null,
		via: step.via === undefined ? VIA_STORE_KEY : step.via,
		automation: null,
		releasedBy: step.releasedBy ?? null,
	});
}

/** Records a step of the whole order, once for each of its items. */
export function recordForEach(
	order: Order,
	now: number,
	step: Omit<Step, 'item'>,
): void {
	for (const item of order.items) {
		record(order, now, { ...step, item });
	}
}

/** The design the item's lead print is of. */
export function itemDesignId(item: Item): string {
	const [lead] = item.prints;
	if (lead === undefined) {
		throw new Error(`Item ${item.id} has no print.`);
	}
	return lead.designId;
}

/** The moves a secret key may make: forward along the chain or to `failed`,
 *  nothing out of a final status, and an item whose outcome is unknown
 *  settled. */
function secretKeyMoves(status: string): string[] {
	if (FINAL_STATUSES.includes(status)) {
		return [];
	}
	if (status === OUTCOME_UNKNOWN) {
		return SETTLED_STATUSES;
	}
	const at = FORWARD_STATUSES.indexOf(status);
	return [...FORWARD_STATUSES.slice(at + 1), FAILED];
}

/** The statuses a store may move the item to now: in a sandbox every move a
 *  secret key may make; on live only settling an unknown outcome. */
export function statusMoves(state: State, order: Order, item: Item): string[] {
	if (state.tenants.get(order.tenantId)?.environment === 'sandbox') {
		return secretKeyMoves(item.status);
	}
	return item.status === OUTCOME_UNKNOWN ? SETTLED_STATUSES : [];
}

/** The item's prints, or why a retry cannot order them again: a print is
 *  gone, its design's personal data was erased, or it is of an earlier design
 *  revision. A failed print or one still being drawn does not refuse it. */
export function retryPrints(
	state: State,
	storeId: string,
	item: Item,
): Print[] | HandlerResult {
	const prints: Print[] = [];
	for (const printId of item.printIds) {
		const print = findPrint(state, printId, storeId);
		if (print === undefined) {
			return refusal(NOT_FOUND_STATUS, 'print_not_found');
		}
		const design = findDesign(state, print.designId, storeId);
		if (design?.personalDataErasedAt != null) {
			return refusal(CONFLICT_STATUS, 'design_erased');
		}
		if (design !== undefined && print.designRevision !== design.revision) {
			return refusal(CONFLICT_STATUS, 'print_superseded');
		}
		prints.push(print);
	}
	return prints;
}

/** Whether a retry would submit the item again: it failed with no printshop
 *  reference, and its prints can be ordered again. */
export function canRetry(state: State, order: Order, item: Item): boolean {
	return (
		item.status === FAILED &&
		(printshopOrderOf(order, item)?.externalOrderRef ?? null) === null &&
		Array.isArray(retryPrints(state, order.storeId, item))
	);
}

/** Whether every item is final, so the order's personal data may go. */
export function orderFinal(order: Order): boolean {
	return order.items.every((item) => FINAL_STATUSES.includes(item.status));
}

/** The caller's order the path names. */
export function pathOrder(context: HandlerContext): Order | undefined {
	return findOwned<Order>(
		context.state,
		ORDERS,
		context.params.id ?? '',
		callerOf(context).storeId,
	);
}

/** The item of `order` the path names. */
export function pathItem(
	context: HandlerContext,
	order: Order,
): Item | undefined {
	return order.items.find((item) => item.id === context.params.itemId);
}
