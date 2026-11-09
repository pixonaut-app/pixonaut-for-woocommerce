// Orders: placing one order for a store's prints, or for its designs (which
// prints each first), listing a store's orders,
// reading one with its history, and a store's own moves: settling or moving
// one by hand, retrying a failed one and cancelling one. Erasing an order's
// personal data is in `erasure.ts`. The fake sends
// nothing to a printshop; an order stays `submitted` until a test moves it.
import { randomUUID } from 'node:crypto';
import {
	allOwned,
	type Blank,
	findOwned,
	type Hold,
	ownedBy,
	putOwned,
	type State,
} from '../state';
import { type Design, findDesign } from './designs-store';
import { deliveryChangeRefusal } from './order-delivery';
import { pageOf } from './paging';
import { findPrint, type Print, printDesigns, recordPrint } from './prints';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

/** The `records` collection orders are kept in. */
export const ORDERS = 'orders';

const CREATED_STATUS = 201;
const BAD_REQUEST_STATUS = 400;
const NOT_FOUND_STATUS = 404;
const CONFLICT_STATUS = 409;
const UNPROCESSABLE_STATUS = 422;

/** The adapter the fake claims to have sent every order through. */
const FAKE_ADAPTER = 'fake';
/** How the fake renders spot colours: as process colour, never as plates. */
const SPOT_OUTPUT = 'process';
/** The quantity an order without one is for. */
const DEFAULT_QUANTITY = 1;
/** How an integration key shows in an order's history. */
const VIA_INTEGRATION = 'integration';
/** How a store's own move shows in an order's history. */
const VIA_STORE_KEY = 'store_key';
/** The statuses an order moves forward through at its printshop. */
const FORWARD_STATUSES = ['submitted', 'queued', 'printed', 'shipped'];
/** Statuses of an order that is no longer its design's live order: a design
 *  has one live order, in any other status. */
const DEAD_STATUSES = ['failed', 'cancelled'];
/** Statuses no move leaves. */
export const FINAL_STATUSES = ['shipped', 'failed', 'cancelled'];
/** A free workspace's order past its monthly limit, not sent yet. */
export const HELD = 'held';
/** The statuses an order whose outcome is unknown is settled to. */
const SETTLED_STATUSES = ['submitted', 'failed'];
/** Statuses the printshop has made the order in: too late to cancel. */
const MADE_STATUSES = ['printed', 'shipped'];
/** Statuses a cancel may stop the order in. */
const CANCELLABLE_STATUSES = [
	'held',
	'awaiting_prints',
	'submitted',
	'queued',
	'outcome_unknown',
];
/** Every status an order can be in, each counted in a list's `counts`. */
const ORDER_STATUSES = [
	'held',
	'awaiting_prints',
	'submitted',
	'queued',
	'printed',
	'shipped',
	'failed',
	'outcome_unknown',
	'cancelled',
];
/** What a list is ordered by when the request names nothing. */
const DEFAULT_SORT = 'submitted';
/** The list direction that puts the earliest first; any other, the latest. */
const ASCENDING = 'asc';

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

type CreateOrderRequest = {
	/** Name `prints` or `designs`, not both. */
	prints?: { printId: string }[];
	designs?: { designId: string }[];
	/** Orders a design again although it has a live order. */
	reorder?: boolean;
	quantity?: number;
	externalRef?: string;
	recipient?: Recipient;
	shippingAddress?: Address;
	printshopId?: string | null;
	variant?: string;
	shipToAddressId?: string | null;
};

type Tracking = { carrier?: string; number: string; url?: string };

type UpdateOrderRequest = {
	status: string;
	reason?: string;
	externalOrderRef?: string;
	tracking?: Tracking;
};

export type HistoryEntry = {
	seq: number;
	at: number;
	type: string;
	status: string;
	reason: string | null;
	via: string | null;
	/** The automation that made the step; the fake runs none. */
	automation: { id: string; name: string } | null;
	/** What released a held order, on `order.released`; null otherwise. */
	releasedBy: string | null;
};

/** A printshop field as an order sent it. */
type SentField = { name: string; value: string; source: string };

/** The variant as the order placed it: later edits do not change it. */
type PlacedVariant = {
	ref: string;
	options: Record<string, string>;
	/** The fake's printshops declare no variant fields. */
	fields: SentField[];
};

export type Order = {
	id: string;
	tenantId: string;
	printIds: string[];
	prints: {
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
	}[];
	/** The blank the prints are on; null for prints on no blank. */
	blankId: string | null;
	variant: PlacedVariant | null;
	quantity: number;
	externalRef: string | null;
	recipient: Recipient | null;
	shippingAddress: Address | null;
	printshopId: string;
	printshopName: string;
	printshopAdapter: string;
	status: string;
	lastError: string | null;
	/** Why Pixonaut failed it rather than its printshop. The fake's prints
	 *  that render never complete, so a waiting order never fails. */
	failureKind: string | null;
	/** Why a free workspace holds it, while it does; null otherwise. */
	hold: Hold | null;
	/** The printshop's own reference for the order. */
	externalOrderRef: string | null;
	tracking: Tracking | null;
	/** The failed order this one was placed to retry. */
	replacesOrderId: string | null;
	/** The order a retry placed for this one. */
	replacedBy: string | null;
	/** When its personal data was erased, epoch ms; null until it is. */
	personalDataErasedAt: number | null;
	/** Its latest address change after it was sent; null for none. */
	delivery: Delivery | null;
	/** Every address change after it was sent, oldest first. */
	deliveryChanges: DeliveryChange[];
	submittedAt: number;
	updatedAt: number;
	history: HistoryEntry[];
};

/** What the printshop made of an address change. */
export type Delivery = { state: string; words: string | null; at: number };

/** An address change as `order.delivery_changed` records it. */
export type DeliveryChange = {
	at: number;
	added: boolean;
	by: string;
	outcome: string;
	words: string | null;
};

/** The blank `blankId` names as it reads now; null for none, or one no
 *  longer there. */
function blankSummary(
	state: State,
	blankId: string | null,
): Record<string, unknown> | null {
	const blank = blankId === null ? undefined : state.blanks.get(blankId);
	return blank === undefined
		? null
		: {
				name: blank.name,
				optionAxes: [...blank.axes],
				variants: blank.variants.length,
			};
}

/** The order as every operation answers it. */
export function orderBody(state: State, order: Order): Record<string, unknown> {
	const [lead] = order.prints;
	if (lead === undefined) {
		throw new Error(`Order ${order.id} has no print.`);
	}
	return {
		id: order.id,
		tenantId: order.tenantId,
		printId: lead.printId,
		designId: lead.designId,
		printIds: order.printIds,
		prints: order.prints,
		adapter: FAKE_ADAPTER,
		quantity: order.quantity,
		externalRef: order.externalRef,
		recipient: order.recipient,
		// The fake sends no mail, so none bounces.
		recipientEmailBounced: false,
		shippingAddress: order.shippingAddress,
		printshopConnectionId: order.printshopId,
		printshopName: order.printshopName,
		spotOutput: SPOT_OUTPUT,
		externalOrderRef: order.externalOrderRef,
		status: order.status,
		lastError: order.lastError,
		failureKind: order.failureKind,
		hold: order.hold === null ? null : { ...order.hold },
		tracking: order.tracking,
		submittedAt: order.submittedAt,
		lastCheckedAt: null,
		updatedAt: order.updatedAt,
		replacesOrderId: order.replacesOrderId,
		replacedByOrderId: order.replacedBy,
		personalDataErasedAt: order.personalDataErasedAt,
		blankId: order.blankId,
		blank: blankSummary(state, order.blankId),
		variantRef: order.variant?.ref ?? null,
		variant: order.variant === null ? null : structuredClone(order.variant),
		printshopAsPlaced: {
			name: order.printshopName,
			adapter: order.printshopAdapter,
		},
		delivery: order.delivery === null ? null : { ...order.delivery },
		// The fake ships every order to the customer's address.
		shipTo: null,
	};
}

/** The blank `print`'s design was committed on, if any. */
function blankOf(context: HandlerContext, storeId: string, print: Print) {
	const blankId = findDesign(context.state, print.designId, storeId)?.proof
		.blankId;
	return blankId == null ? undefined : context.state.blanks.get(blankId);
}

/** Why `prints` cannot be one item, as the reference refuses it; null when
 *  they can: prints of their designs' current revisions that did not fail and, for
 *  several prints, all on one blank, each in a placement of its own. One
 *  print is the item it is. */
function refusePrints(
	context: HandlerContext,
	storeId: string,
	prints: Print[],
): HandlerResult | null {
	const placed: { blankId: string; placementId: string }[] = [];
	for (const print of prints) {
		if (print.status === 'failed') {
			return {
				status: CONFLICT_STATUS,
				body: { error: 'print_not_completed', status: print.status },
			};
		}
		const design = findDesign(context.state, print.designId, storeId);
		if (design?.personalDataErasedAt != null) {
			return refusal(CONFLICT_STATUS, 'design_erased');
		}
		if (design !== undefined && print.designRevision !== design.revision) {
			return refusal(CONFLICT_STATUS, 'print_superseded');
		}
		if (prints.length === 1) {
			return null;
		}
		const { blankId, placementId } = design?.proof ?? {};
		if (blankId == null) {
			return {
				status: UNPROCESSABLE_STATUS,
				body: { error: 'print_without_blank', printId: print.id },
			};
		}
		if (placementId == null) {
			return {
				status: UNPROCESSABLE_STATUS,
				body: { error: 'print_without_placement', printId: print.id },
			};
		}
		placed.push({ blankId, placementId });
	}
	if (new Set(placed.map((p) => p.blankId)).size > 1) {
		return refusal(UNPROCESSABLE_STATUS, 'mixed_blanks');
	}
	const seen = new Set<string>();
	for (const { placementId } of placed) {
		if (seen.has(placementId)) {
			return {
				status: UNPROCESSABLE_STATUS,
				body: { error: 'duplicate_placement', placementId },
			};
		}
		seen.add(placementId);
	}
	return null;
}

/** The variant an order of `prints` is for (Pixonaut's `order-variant`):
 *  the one named, which must be a sold variant of the prints' blank; when
 *  none is named, the blank's only variant, and none for prints on no blank.
 *  A blank of several variants needs one named. */
function variantFor(
	blank: Blank | undefined,
	named: string | undefined,
): { variant: PlacedVariant | null } | HandlerResult {
	if (named === undefined) {
		if (blank === undefined) {
			return { variant: null };
		}
		const [only, ...more] = blank.variants;
		if (only === undefined || more.length > 0) {
			return {
				status: UNPROCESSABLE_STATUS,
				body: {
					error: 'variant_required',
					variants: blank.variants.length,
					reason: `The blank has ${blank.variants.length} variants; name the one ordered.`,
				},
			};
		}
		return sold(only);
	}
	const variant = blank?.variants.find((v) => v.ref === named);
	if (variant === undefined) {
		return {
			status: UNPROCESSABLE_STATUS,
			body: {
				error: 'variant_not_of_blank',
				variant: named,
				reason: `${named} is not a variant of the prints' blank.`,
			},
		};
	}
	return sold(variant);
}

/** The variant ordered, named or implied, unless it is retired. */
function sold(
	variant: Blank['variants'][number],
): { variant: PlacedVariant } | HandlerResult {
	if (variant.retired !== null) {
		return {
			status: UNPROCESSABLE_STATUS,
			body: {
				error: 'variant_retired',
				variant: variant.ref,
				reason: `${variant.ref} is retired.`,
			},
		};
	}
	return { variant: placed(variant) };
}

/** The frozen copy of `variant` an order keeps. */
function placed(variant: Blank['variants'][number]): PlacedVariant {
	return { ref: variant.ref, options: { ...variant.options }, fields: [] };
}

/** Where an order goes. `refused` says why that printshop cannot take it
 *  now: one nobody named still gets the order, placed as failed. */
type Printshop = {
	id: string;
	name: string;
	adapter: string;
	refused: string | null;
};

type PrintshopRefusal = { status: number; error: string; reason?: string };

/** Where the order goes: the blank's printshop for a print on a blank, which
 *  refuses any printshop named; for a print on no blank, the one named, else
 *  the tenant's default. A retry sends the order back where it went. A
 *  disconnected printshop the call named, or a retry's, is refused; one
 *  nobody named still takes the order, which fails (Pixonaut's
 *  `routeForOrder`). */
function printshopFor(
	context: HandlerContext,
	storeId: string,
	tenantId: string,
	prints: Print[],
	route: { named?: string | null; sentTo?: string },
): Printshop | PrintshopRefusal {
	const [lead] = prints;
	const blank =
		lead === undefined ? undefined : blankOf(context, storeId, lead);
	const named = route.named ?? null;
	let id: string | undefined;
	let chosen = route.sentTo !== undefined;
	if (route.sentTo !== undefined) {
		id = route.sentTo;
	} else if (blank !== undefined) {
		if (named === blank.defaultPrintshopId) {
			return { status: BAD_REQUEST_STATUS, error: 'bad_request' };
		}
		if (named !== null) {
			return {
				status: UNPROCESSABLE_STATUS,
				error: 'printshop_differs_from_blank',
				reason: `The blank is made by ${blank.defaultPrintshopId}.`,
			};
		}
		id = blank.defaultPrintshopId;
	} else {
		chosen = named !== null;
		id =
			named ??
			[...context.state.printshops.values()].find(
				(p) => p.tenantId === tenantId && p.isDefault,
			)?.id;
	}
	if (id === undefined) {
		return { status: CONFLICT_STATUS, error: 'print_needs_printshop' };
	}
	const printshop = context.state.printshops.get(id);
	if (printshop === undefined || printshop.tenantId !== tenantId) {
		return {
			status: UNPROCESSABLE_STATUS,
			error: 'unknown_printshop',
			reason: `No printshop ${id}.`,
		};
	}
	const { adapter } = printshop;
	const refused = printshop.active
		? null
		: `${printshop.name} is disconnected.`;
	if (refused !== null && chosen) {
		return {
			status: UNPROCESSABLE_STATUS,
			error: 'printshop_disconnected',
			reason: refused,
		};
	}
	return { id, name: printshop.name, adapter, refused };
}

/** How an order turns out as it is placed. */
type Outcome = {
	status: string;
	lastError: string | null;
	type: string;
	hold?: Hold;
};

/** How the next order turns out, if an outcome is armed; uses it up. */
function takeArmedOutcome(state: State): Outcome {
	const armed = state.armedOrderOutcome;
	if (armed === null) {
		return { status: 'submitted', lastError: null, type: 'order.submitted' };
	}
	armed.remaining -= 1;
	if (armed.remaining <= 0) {
		state.armedOrderOutcome = null;
	}
	if (armed.outcome === 'failed') {
		return { status: 'failed', lastError: armed.reason, type: 'order.failed' };
	}
	if (armed.outcome === 'held') {
		// Held before it is sent: its prints wait with it.
		return {
			status: HELD,
			lastError: null,
			type: 'order.held',
			hold: { ...armed.hold },
		};
	}
	// The submission was made; only its answer is missing.
	return {
		status: 'outcome_unknown',
		lastError: null,
		type: 'order.submitted',
	};
}

type Placing = {
	storeId: string;
	tenantId: string;
	prints: Print[];
	printshop: Printshop;
	blank: Blank | undefined;
	variant: PlacedVariant | null;
	quantity: number;
	externalRef: string | null;
	recipient: Recipient | null;
	shippingAddress: Address | null;
	replacesOrderId: string | null;
};

/** Places an order as its printshop answers it now, and records it. */
function placeOrder(context: HandlerContext, placing: Placing): Order {
	const { storeId, prints, printshop, blank } = placing;
	const now = context.clock.now();
	// An order of prints still rendering waits for them, and a printshop that
	// cannot take the order fails it unsent: no armed outcome is used.
	const outcome: Outcome = prints.some((p) => p.status !== 'completed')
		? { status: 'awaiting_prints', lastError: null, type: 'order.created' }
		: printshop.refused === null
			? takeArmedOutcome(context.state)
			: {
					status: 'failed',
					lastError: printshop.refused,
					type: 'order.failed',
				};
	const order: Order = {
		id: `ord_${randomUUID()}`,
		tenantId: placing.tenantId,
		printIds: prints.map((p) => p.id),
		prints: prints.map((p) => {
			const placementId =
				findDesign(context.state, p.designId, storeId)?.proof.placementId ??
				null;
			const placement = blank?.placements.find((b) => b.id === placementId);
			return {
				printId: p.id,
				designId: p.designId,
				designRevision: p.designRevision,
				placementId,
				// The name the printshop knows the placement by.
				printedAs:
					placement === undefined
						? null
						: (placement.shopName ?? placement.name),
				spotOutput: SPOT_OUTPUT,
				placementFields: [],
				status: p.status,
			};
		}),
		blankId: blank?.id ?? null,
		variant: placing.variant,
		quantity: placing.quantity,
		externalRef: placing.externalRef,
		recipient: placing.recipient,
		shippingAddress: placing.shippingAddress,
		printshopId: printshop.id,
		printshopName: printshop.name,
		printshopAdapter: printshop.adapter,
		status: outcome.status,
		lastError: outcome.lastError,
		failureKind: null,
		hold: outcome.hold ?? null,
		externalOrderRef: null,
		tracking: null,
		replacesOrderId: placing.replacesOrderId,
		replacedBy: null,
		personalDataErasedAt: null,
		delivery: null,
		deliveryChanges: [],
		submittedAt: now,
		updatedAt: now,
		history: [
			{
				seq: 1,
				at: now,
				type: outcome.type,
				status: outcome.status,
				reason: outcome.lastError,
				via: VIA_INTEGRATION,
				automation: null,
				releasedBy: null,
			},
		],
	};
	putOwned(context.state, ORDERS, order.id, storeId, order);
	for (const print of prints) {
		print.orderCount += 1;
		if (!print.printshops.includes(printshop.name)) {
			print.printshops.push(printshop.name);
		}
	}
	return order;
}

/** The printshop refusal `printshopFor` gave, as the reference answers it. */
function refusedPrintshop({
	status,
	error,
	reason,
}: PrintshopRefusal): HandlerResult {
	return { status, body: reason === undefined ? { error } : { error, reason } };
}

/** The prints `printIds` names, or why one cannot be ordered. */
function namedPrints(
	context: HandlerContext,
	storeId: string,
	printIds: { printId: string }[],
): Print[] | HandlerResult {
	const prints: Print[] = [];
	for (const { printId } of printIds) {
		const print = findPrint(context.state, printId, storeId);
		if (print === undefined) {
			return refusal(NOT_FOUND_STATUS, 'print_not_found');
		}
		prints.push(print);
	}
	return prints;
}

/** A new print of each design `designIds` names, not yet recorded, or why
 *  one cannot be printed. */
function printsOfDesigns(
	context: HandlerContext,
	storeId: string,
	designIds: { designId: string }[],
): Print[] | HandlerResult {
	const designs: Design[] = [];
	for (const { designId } of designIds) {
		const design = findDesign(context.state, designId, storeId);
		if (design === undefined) {
			return refusal(NOT_FOUND_STATUS, 'design_not_found');
		}
		if (design.personalDataErasedAt !== null) {
			return refusal(CONFLICT_STATUS, 'design_erased');
		}
		if (design.status !== 'approved' || design.approvedRevision === null) {
			return refusal(CONFLICT_STATUS, 'design_not_approved');
		}
		designs.push(design);
	}
	return printDesigns(context.state, designs, storeId, context.clock.now());
}

/** The first of the prints' designs that has a live order of the store,
 *  refused naming the design and its newest live order; null when none has.
 *  A standing design is ordered any number of times. */
function refuseOrdered(
	state: State,
	storeId: string,
	prints: Print[],
): HandlerResult | null {
	// Orders are kept in the order they were placed: newest first here.
	const live = ownedBy<Order>(state, ORDERS, storeId)
		.filter((o) => !DEAD_STATUSES.includes(o.status))
		.reverse();
	for (const { designId } of prints) {
		if (findDesign(state, designId, storeId)?.standing !== undefined) {
			continue;
		}
		const newest = live.find((o) =>
			o.prints.some((p) => p.designId === designId),
		);
		if (newest !== undefined) {
			return {
				status: CONFLICT_STATUS,
				body: { error: 'design_already_ordered', designId, orderId: newest.id },
			};
		}
	}
	return null;
}

/** The first print whose design is sized for a row of its blank's
 *  `areasVaryBy` options the ordered variant is not in, refused naming the
 *  design, the variant and the row; null when every design fits it. */
function refuseOtherRow(
	state: State,
	storeId: string,
	prints: Print[],
	variant: PlacedVariant | null,
): HandlerResult | null {
	for (const { designId } of prints) {
		const row = findDesign(state, designId, storeId)?.variantRow ?? null;
		if (
			row === null ||
			(variant !== null &&
				Object.entries(row).every(
					([option, value]) => variant.options[option] === value,
				))
		) {
			continue;
		}
		return {
			status: UNPROCESSABLE_STATUS,
			body: {
				error: 'variant_not_of_design_row',
				designId,
				variant: variant?.ref ?? '',
				row,
				reason: `${designId} is sized for another row of the blank.`,
			},
		};
	}
	return null;
}

export const createOrder: Handler = (context) => {
	const caller = callerOf(context);
	const request = context.body as CreateOrderRequest;
	if ((request.prints === undefined) === (request.designs === undefined)) {
		return refusal(BAD_REQUEST_STATUS, 'bad_request');
	}
	// The fake keeps no address book, so it knows no saved address.
	if (typeof request.shipToAddressId === 'string') {
		return refusal(UNPROCESSABLE_STATUS, 'saved_address_not_found');
	}
	// Prints made for the designs named are recorded only once nothing
	// refuses the order.
	const made = request.designs !== undefined;
	const prints =
		request.designs !== undefined
			? printsOfDesigns(context, caller.storeId, request.designs)
			: namedPrints(context, caller.storeId, request.prints ?? []);
	if (!Array.isArray(prints)) {
		return prints;
	}
	const refused = refusePrints(context, caller.storeId, prints);
	if (refused !== null) {
		return refused;
	}
	const [lead] = prints;
	const blank =
		lead === undefined ? undefined : blankOf(context, caller.storeId, lead);
	const chosen = variantFor(blank, request.variant);
	if (!('variant' in chosen)) {
		return chosen;
	}
	const otherRow = refuseOtherRow(
		context.state,
		caller.storeId,
		prints,
		chosen.variant,
	);
	if (otherRow !== null) {
		return otherRow;
	}
	const printshop = printshopFor(
		context,
		caller.storeId,
		caller.tenantId,
		prints,
		{ named: request.printshopId },
	);
	if ('error' in printshop) {
		return refusedPrintshop(printshop);
	}
	const ordered =
		request.reorder === true
			? null
			: refuseOrdered(context.state, caller.storeId, prints);
	if (ordered !== null) {
		return ordered;
	}

	if (made) {
		for (const print of prints) {
			recordPrint(context.state, caller.storeId, print);
		}
	}
	const order = placeOrder(context, {
		storeId: caller.storeId,
		tenantId: caller.tenantId,
		prints,
		printshop,
		blank,
		variant: chosen.variant,
		quantity: request.quantity ?? DEFAULT_QUANTITY,
		externalRef: request.externalRef ?? null,
		recipient: request.recipient ?? null,
		shippingAddress: request.shippingAddress ?? null,
		replacesOrderId: null,
	});
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
 *  (Pixonaut's `filterPredicates`): `counts` is counted under these. */
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
	return (
		(printId === null || order.printIds.includes(printId)) &&
		(designId === null || order.prints.some((p) => p.designId === designId)) &&
		(externalRef === null || order.externalRef === externalRef) &&
		(printshop === null || order.printshopId === printshop) &&
		(blankId === null || order.blankId === blankId) &&
		(variant === null || order.variant?.ref === variant) &&
		(from === null || order.submittedAt >= Number(from)) &&
		(to === null || order.submittedAt <= Number(to)) &&
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
		counts[order.status] = (counts[order.status] ?? 0) + 1;
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
	const at = (o: Order) => (bySubmission ? o.submittedAt : o.updatedAt);
	const ascending = [...matching].sort((a, b) => at(a) - at(b));
	const sorted =
		query.get('order') === ASCENDING ? ascending : ascending.reverse();
	const orders = sorted.filter(
		(o) => statuses.length === 0 || statuses.includes(o.status),
	);
	const page = pageOf(orders, query);
	return {
		status: 200,
		body: {
			orders: page.rows.map((o) => orderBody(context.state, o)),
			total: page.total,
			page: page.page,
			pageSize: page.pageSize,
			counts: statusCounts(matching),
		},
	};
};

/** The moves a secret key may make: forward along the chain or to
 *  `failed`, nothing out of a final status, and an order whose outcome is
 *  unknown settled. */
function secretKeyMoves(status: string): string[] {
	if (FINAL_STATUSES.includes(status)) {
		return [];
	}
	if (status === 'outcome_unknown') {
		return SETTLED_STATUSES;
	}
	const at = FORWARD_STATUSES.indexOf(status);
	return [...FORWARD_STATUSES.slice(at + 1), 'failed'];
}

/** The statuses a store may set the order to now: in a sandbox every move a
 *  secret key may make; on live only settling an unknown outcome. */
function statusMoves(state: State, order: Order): string[] {
	if (state.tenants.get(order.tenantId)?.environment === 'sandbox') {
		return secretKeyMoves(order.status);
	}
	return order.status === 'outcome_unknown' ? SETTLED_STATUSES : [];
}

/** The order's prints, or why a retry cannot order them again: a print is
 *  gone, its design's personal data was erased, or it is of an earlier design
 *  revision. A failed print or one still being drawn does not refuse it. */
function retryPrints(
	context: HandlerContext,
	order: Order,
): Print[] | HandlerResult {
	const { storeId } = callerOf(context);
	const prints: Print[] = [];
	for (const printId of order.printIds) {
		const print = findPrint(context.state, printId, storeId);
		if (print === undefined) {
			return refusal(NOT_FOUND_STATUS, 'print_not_found');
		}
		const design = findDesign(context.state, print.designId, storeId);
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

/** A failed order no retry has replaced yet, whose prints a retry can order
 *  again. */
function canRetry(context: HandlerContext, order: Order): boolean {
	return (
		order.status === 'failed' &&
		order.replacedBy === null &&
		Array.isArray(retryPrints(context, order))
	);
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

/** Records a move on the order and in its history. */
function move(
	order: Order,
	now: number,
	type: string,
	status: string,
	reason: string | null,
): void {
	order.status = status;
	order.updatedAt = now;
	order.history.push({
		seq: order.history.length + 1,
		at: now,
		type,
		status,
		reason,
		via: VIA_STORE_KEY,
		automation: null,
		releasedBy: null,
	});
}

export const readOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	// Only an order holding personal data has any to erase.
	const erasable = order.recipient !== null || order.shippingAddress !== null;
	const retry = canRetry(context, order);
	return {
		status: 200,
		body: {
			...orderBody(context.state, order),
			history: order.history,
			retryable: retry,
			canRetry: retry,
			erasable,
			canErase: erasable,
			statusMoves: statusMoves(context.state, order),
			fallbackSpots: [],
			varnishLeftOut: false,
			// The fake places every order on its print's own blank.
			reroutes: [],
			deliveryChanges: order.deliveryChanges.map((change) => ({ ...change })),
			deliveryChangeRefusal: deliveryChangeRefusal(context.state, order),
			// The fake's printshops stitch nothing: none charges an embroidery
			// set-up fee or holds an order for its merchant to approve a change.
			embroideryFee: null,
			approvalHold: null,
		},
	};
};

/** Whether another order of the tenant holds the printshop's reference. */
function referenceTaken(state: State, order: Order, ref: string): boolean {
	if (order.externalOrderRef !== null && order.externalOrderRef !== ref) {
		return true;
	}
	return [...allOwned<Order>(state, ORDERS).values()].some(
		({ value }) =>
			value.id !== order.id &&
			value.tenantId === order.tenantId &&
			value.externalOrderRef === ref,
	);
}

export const updateOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const request = context.body as UpdateOrderRequest;
	const to = request.status;
	if (
		(request.tracking !== undefined && to !== 'shipped') ||
		(request.externalOrderRef !== undefined && to !== 'submitted')
	) {
		return refusal(BAD_REQUEST_STATUS, 'bad_request');
	}
	const from = order.status;
	if (!statusMoves(context.state, order).includes(to)) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_status_not_allowed', from, to },
		};
	}
	if (from === 'outcome_unknown' && request.reason === undefined) {
		return {
			status: UNPROCESSABLE_STATUS,
			body: { error: 'order_status_reason_required', from },
		};
	}
	const ref = request.externalOrderRef;
	if (ref !== undefined && referenceTaken(context.state, order, ref)) {
		return refusal(CONFLICT_STATUS, 'order_reference_taken');
	}

	const reason = request.reason ?? null;
	if (to === 'failed') {
		order.lastError = reason;
	}
	if (ref !== undefined) {
		order.externalOrderRef = ref;
	}
	if (request.tracking !== undefined) {
		order.tracking = request.tracking;
	}
	const type = to === 'failed' ? 'order.failed' : 'order.status_changed';
	move(order, context.clock.now(), type, to, reason);
	return { status: 200, body: orderBody(context.state, order) };
};

export const retryOrder: Handler = (context) => {
	const caller = callerOf(context);
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	if (order.replacedBy !== null) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_superseded', newerOrderId: order.replacedBy },
		};
	}
	if (order.status !== 'failed') {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_not_retryable', status: order.status },
		};
	}
	const prints = retryPrints(context, order);
	if (!Array.isArray(prints)) {
		return prints;
	}
	const printshop = printshopFor(
		context,
		caller.storeId,
		order.tenantId,
		prints,
		{ sentTo: order.printshopId },
	);
	if ('error' in printshop) {
		return refusedPrintshop(printshop);
	}

	// A failed print is drawn again in place, under its own id, and the new
	// order waits for it, as for a print still being drawn. The fake never
	// finishes the drawing on its own.
	for (const print of prints) {
		if (print.status === 'failed') {
			print.status = 'created';
			print.completedAt = null;
		}
	}
	const retried = placeOrder(context, {
		storeId: caller.storeId,
		tenantId: order.tenantId,
		prints,
		printshop,
		// A retry orders the failed order's variant, retired since or not.
		blank:
			order.blankId === null
				? undefined
				: context.state.blanks.get(order.blankId),
		variant: order.variant === null ? null : structuredClone(order.variant),
		quantity: order.quantity,
		externalRef: order.externalRef,
		recipient: order.recipient,
		shippingAddress: order.shippingAddress,
		replacesOrderId: order.id,
	});
	order.replacedBy = retried.id;
	return { status: CREATED_STATUS, body: orderBody(context.state, retried) };
};

/** Cancelling asks the printshop, which the fake always answers yes to while
 *  it has not made the order. */
export const cancelOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const from = order.status;
	if (from === 'cancelled') {
		return { status: 200, body: orderBody(context.state, order) };
	}
	if (MADE_STATUSES.includes(from)) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_at_printshop', cause: 'made' },
		};
	}
	if (!CANCELLABLE_STATUSES.includes(from)) {
		return {
			status: CONFLICT_STATUS,
			body: { error: 'order_status_not_allowed', from, to: 'cancelled' },
		};
	}
	move(order, context.clock.now(), 'order.cancelled', 'cancelled', null);
	return { status: 200, body: orderBody(context.state, order) };
};
