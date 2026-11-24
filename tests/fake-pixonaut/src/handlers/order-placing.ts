// What placing an item checks, as Pixonaut's order routes refuse it, and how
// a placed item turns out: the prints it holds, the variant it is for, the
// printshop it goes to, and what an armed outcome makes of it.
import { randomUUID } from 'node:crypto';
import { type Blank, type Hold, ownedBy, type State } from '../state';
import { type Design, findDesign } from './designs-store';
import {
	DEAD_STATUSES,
	FAILED,
	type Item,
	newSubmission,
	ORDERS,
	type Order,
	type OrderPrint,
	OUTCOME_UNKNOWN,
	type PlacedVariant,
} from './order-model';
import { findPrint, type Print } from './prints';
import { type HandlerContext, type HandlerResult, refusal } from './types';

const BAD_REQUEST_STATUS = 400;
const NOT_FOUND_STATUS = 404;
const CONFLICT_STATUS = 409;
const UNPROCESSABLE_STATUS = 422;

/** How the fake renders spot colours: as process colour, never as plates. */
const SPOT_OUTPUT = 'process';

/** The blank `print`'s design was committed on, if any. */
export function blankOf(
	context: HandlerContext,
	storeId: string,
	print: Print,
): Blank | undefined {
	const blankId = findDesign(context.state, print.designId, storeId)?.proof
		.blankId;
	return blankId == null ? undefined : context.state.blanks.get(blankId);
}

/** Why `prints` cannot be one item, as the reference refuses it; null when
 *  they can: prints of their designs' current revisions that did not fail and,
 *  for several prints, all on one blank, each in a placement of its own. One
 *  print is the item it is. */
export function refusePrints(
	context: HandlerContext,
	storeId: string,
	prints: Print[],
): HandlerResult | null {
	const placed: { blankId: string; placementId: string }[] = [];
	for (const print of prints) {
		const paged =
			findDesign(context.state, print.designId, storeId)?.proof.page !==
			undefined;
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
		// Every page of a book is printed in its placement of pages.
		if (!paged) {
			placed.push({ blankId, placementId });
		}
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

/** Why the pages an item names do not match the pages its designs were
 *  proofed as, as the reference refuses them; null when they do. A design
 *  of a side printed as pages is named with the page it was proofed as, and
 *  every page of its book from 1 is named once, each book in its own
 *  placement; a design of no pages names none. `pages` holds each print's
 *  `page`, in the order of `prints`. */
export function refusePages(
	context: HandlerContext,
	storeId: string,
	prints: Print[],
	pages: (number | undefined)[],
): HandlerResult | null {
	/** Each book's pages named, and its page count, by placement. */
	const books = new Map<string, { named: Set<number>; pageCount: number }>();
	for (const [index, print] of prints.entries()) {
		const proof = findDesign(context.state, print.designId, storeId)?.proof;
		const proofed = proof?.page;
		const page = pages[index];
		if (proofed === undefined) {
			if (page !== undefined) {
				return refusal(UNPROCESSABLE_STATUS, 'page_on_unpaged_placement');
			}
			continue;
		}
		if (page !== proofed.page) {
			return {
				status: UNPROCESSABLE_STATUS,
				body: {
					error: 'page_not_as_proofed',
					designId: print.designId,
					page: page ?? null,
					proofedPage: proofed.page,
				},
			};
		}
		const placement = proof?.placementId ?? '';
		const book = books.get(placement) ?? {
			named: new Set<number>(),
			pageCount: proofed.pageCount,
		};
		if (book.pageCount !== proofed.pageCount) {
			// Pages of one placement proofed at other counts are not one book.
			return refusal(UNPROCESSABLE_STATUS, 'page_not_as_proofed');
		}
		if (book.named.has(page)) {
			return refusal(UNPROCESSABLE_STATUS, 'duplicate_page');
		}
		book.named.add(page);
		books.set(placement, book);
	}
	for (const book of books.values()) {
		if (book.named.size !== book.pageCount) {
			return refusal(UNPROCESSABLE_STATUS, 'page_missing');
		}
	}
	return null;
}

/** The frozen copy of `variant` an item keeps. */
function placed(variant: Blank['variants'][number]): PlacedVariant {
	return { ref: variant.ref, options: { ...variant.options }, fields: [] };
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

/** The variant an item of `prints` is for (Pixonaut's `order-variant`): the
 *  one named, which must be a sold variant of the prints' blank; when none is
 *  named, the blank's only variant, and none for prints on no blank. A blank
 *  of several variants needs one named. */
export function variantFor(
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

/** Where an item goes. `refused` says why that printshop cannot take it now:
 *  one nobody named still gets the item, placed as failed. */
export type Printshop = {
	id: string;
	name: string;
	adapter: string;
	refused: string | null;
};

type PrintshopRefusal = { status: number; error: string; reason?: string };

/** Where the item goes: the blank's printshop for a print on a blank, which
 *  refuses any printshop named; for a print on no blank, the one named, else
 *  the tenant's default. A retry sends the item back where it went. A
 *  disconnected printshop the call named, or a retry's, is refused; one
 *  nobody named still takes the item, which fails (Pixonaut's
 *  `routeForOrder`). */
export function printshopFor(
	context: HandlerContext,
	storeId: string,
	tenantId: string,
	prints: Print[],
	route: { named?: string | null; sentTo?: string },
): Printshop | HandlerResult {
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
			return refused({ status: BAD_REQUEST_STATUS, error: 'bad_request' });
		}
		if (named !== null) {
			return refused({
				status: UNPROCESSABLE_STATUS,
				error: 'printshop_differs_from_blank',
				reason: `The blank is made by ${blank.defaultPrintshopId}.`,
			});
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
		return refused({ status: CONFLICT_STATUS, error: 'print_needs_printshop' });
	}
	const printshop = context.state.printshops.get(id);
	if (printshop === undefined || printshop.tenantId !== tenantId) {
		return refused({
			status: UNPROCESSABLE_STATUS,
			error: 'unknown_printshop',
			reason: `No printshop ${id}.`,
		});
	}
	const { adapter } = printshop;
	const off = printshop.active ? null : `${printshop.name} is disconnected.`;
	if (off !== null && chosen) {
		return refused({
			status: UNPROCESSABLE_STATUS,
			error: 'printshop_disconnected',
			reason: off,
		});
	}
	return { id, name: printshop.name, adapter, refused: off };
}

/** A printshop refusal, as the reference answers it. */
function refused({ status, error, reason }: PrintshopRefusal): HandlerResult {
	return { status, body: reason === undefined ? { error } : { error, reason } };
}

/** Whether `chosen` is a printshop rather than a refusal of one. */
export function isPrintshop(
	chosen: Printshop | HandlerResult,
): chosen is Printshop {
	return 'id' in chosen;
}

/** The prints `printIds` names, or why one cannot be ordered. */
export function namedPrints(
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

/** The designs `designIds` names, each approved, or why one cannot be
 *  printed. */
export function approvedDesigns(
	context: HandlerContext,
	storeId: string,
	designIds: { designId: string }[],
): Design[] | HandlerResult {
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
	return designs;
}

/** The first of the prints' designs that has a live item of the store,
 *  refused naming the design, its newest live item and that item's order;
 *  null when none has. A standing design is ordered any number of times. */
export function refuseOrdered(
	state: State,
	storeId: string,
	prints: Print[],
): HandlerResult | null {
	// Orders are kept in the order they were placed: newest first here.
	const live = ownedBy<Order>(state, ORDERS, storeId)
		.flatMap((order) =>
			order.items
				.filter((item) => !DEAD_STATUSES.includes(item.status))
				.map((item) => ({ order, item })),
		)
		.reverse();
	for (const { designId } of prints) {
		if (findDesign(state, designId, storeId)?.standing !== undefined) {
			continue;
		}
		const newest = live.find(({ item }) =>
			item.prints.some((p) => p.designId === designId),
		);
		if (newest !== undefined) {
			return {
				status: CONFLICT_STATUS,
				body: {
					error: 'design_already_ordered',
					designId,
					orderId: newest.order.id,
					itemId: newest.item.id,
				},
			};
		}
	}
	return null;
}

/** The first print whose design is sized for a row of its blank's
 *  `areasVaryBy` options the ordered variant is not in, refused naming the
 *  design, the variant and the row; null when every design fits it. */
export function refuseOtherRow(
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

/** The hold the next order is placed under, if one is armed; uses it up. */
export function takeArmedHold(state: State): Hold | null {
	const armed = state.armedOrderOutcome;
	if (armed?.outcome !== 'held') {
		return null;
	}
	armed.remaining -= 1;
	if (armed.remaining <= 0) {
		state.armedOrderOutcome = null;
	}
	return { ...armed.hold };
}

/** How an item sent to its printshop turns out. */
export type Outcome = { status: string; lastError: string | null };

/** How the printshop answers an item with external reference `ref` when a
 *  failure or a lost answer is armed for it; uses one up. Null when none is
 *  armed, or it is armed for another item. */
export function takeArmedOutcome(
	state: State,
	ref: string | null,
): Outcome | null {
	const armed = state.armedOrderOutcome;
	if (
		armed === null ||
		armed.outcome === 'held' ||
		(armed.itemExternalRef !== undefined && armed.itemExternalRef !== ref)
	) {
		return null;
	}
	armed.remaining -= 1;
	if (armed.remaining <= 0) {
		state.armedOrderOutcome = null;
	}
	return armed.outcome === 'failed'
		? { status: FAILED, lastError: armed.reason }
		: // The submission was made; only its answer is missing.
			{ status: OUTCOME_UNKNOWN, lastError: null };
}

/** How the item's prints went into it, each named as the printshop knows its
 *  placement. */
export function orderPrints(
	context: HandlerContext,
	storeId: string,
	blank: Blank | undefined,
	prints: Print[],
): OrderPrint[] {
	return prints.map((p) => {
		const placementId =
			findDesign(context.state, p.designId, storeId)?.proof.placementId ?? null;
		const placement = blank?.placements.find((b) => b.id === placementId);
		return {
			printId: p.id,
			designId: p.designId,
			designRevision: p.designRevision,
			placementId,
			printedAs:
				placement === undefined ? null : (placement.shopName ?? placement.name),
			spotOutput: SPOT_OUTPUT,
			placementFields: [],
			status: p.status,
		};
	});
}

/** What an item is placed with, once nothing refuses it. */
export type ItemPlacing = {
	externalRef: string | null;
	prints: Print[];
	blank: Blank | undefined;
	variant: PlacedVariant | null;
	quantity: number;
	printshop: Printshop;
};

/** A new item in `status`, its prints counted as ordered. */
export function newItem(
	context: HandlerContext,
	storeId: string,
	placing: ItemPlacing,
	status: string,
	now: number,
): Item {
	const { prints, printshop, blank } = placing;
	for (const print of prints) {
		print.orderCount += 1;
		if (!print.printshops.includes(printshop.name)) {
			print.printshops.push(printshop.name);
		}
	}
	const item: Item = {
		id: `itm_${randomUUID()}`,
		externalRef: placing.externalRef,
		status,
		quantity: placing.quantity,
		printIds: prints.map((p) => p.id),
		prints: orderPrints(context, storeId, blank, prints),
		blankId: blank?.id ?? null,
		variant: placing.variant,
		printshopId: printshop.id,
		printshopName: printshop.name,
		printshopAdapter: printshop.adapter,
		lastError: null,
		failureKind: null,
		submissions: [newSubmission(status, now)],
	};
	return item;
}
