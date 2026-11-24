// An order as every operation answers it, and as a row of the order list.
import type { State } from '../state';
import { deliveryChangeRefusal } from './order-delivery';
import {
	canRetry,
	type Item,
	itemDesignId,
	type Order,
	orderStatus,
	type Parcel,
	SHIPPED,
	statusMoves,
} from './order-model';

/** How the fake renders spot colours: as process colour, never as plates. */
const SPOT_OUTPUT = 'process';

/** The blank `blankId` names as it reads now; null for none, or one no
 *  longer there. */
export function blankSummary(
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

/** How many of the item's copies are in parcels that left; null when one of
 *  those parcels does not say how many it holds. */
function shippedQuantity(order: Order, item: Item): number | null {
	let shipped = 0;
	for (const parcel of order.parcels) {
		const held = parcel.items.find((entry) => entry.itemId === item.id);
		if (held === undefined || parcel.status === 'preparing') {
			continue;
		}
		if (held.quantity === null) {
			return null;
		}
		shipped += held.quantity;
	}
	return shipped;
}

/** The item fields an order list row shows too. */
function itemSummary(state: State, order: Order, item: Item) {
	return {
		id: item.id,
		externalRef: item.externalRef,
		status: item.status,
		quantity: item.quantity,
		shippedQuantity: shippedQuantity(order, item),
		designId: itemDesignId(item),
		printIds: [...item.printIds],
		blankId: item.blankId,
		blank: blankSummary(state, item.blankId),
		variantRef: item.variant?.ref ?? null,
		variant: item.variant === null ? null : structuredClone(item.variant),
		printshopConnectionId: item.printshopId,
		printshopName: item.printshopName,
		lastError: item.lastError,
		failureKind: item.failureKind,
	};
}

function itemBody(
	state: State,
	order: Order,
	item: Item,
): Record<string, unknown> {
	const retry = canRetry(state, order, item);
	return {
		...itemSummary(state, order, item),
		prints: structuredClone(item.prints),
		printshopAsPlaced: {
			name: item.printshopName,
			adapter: item.printshopAdapter,
		},
		spotOutput: SPOT_OUTPUT,
		fallbackSpots: [],
		varnishLeftOut: false,
		// The fake's printshops stitch nothing and ask no merchant to approve a
		// change: no item is held for approval or charged an embroidery fee.
		hold: null,
		approvalHold: null,
		embroideryFee: null,
		retryable: retry,
		canRetry: retry,
		statusMoves: statusMoves(state, order, item),
		// The fake places every item on its print's own blank.
		reroutes: [],
		parcels: order.parcels.flatMap((parcel) =>
			parcel.items
				.filter((entry) => entry.itemId === item.id)
				.map((entry) => ({ parcelId: parcel.id, quantity: entry.quantity })),
		),
		submissions: item.submissions.map((submission) => ({
			...submission,
			// The fake's printshops take an order whole, never line by line.
			externalLineRef: null,
			struck: false,
		})),
	};
}

function parcelBody(order: Order, parcel: Parcel): Record<string, unknown> {
	return {
		id: parcel.id,
		printshopOrderId: parcel.printshopOrderId,
		status: parcel.status,
		tracking: parcel.tracking === null ? null : { ...parcel.tracking },
		shippedAt: parcel.shippedAt,
		deliveredAt: null,
		items: parcel.items.map(({ itemId, quantity }) => {
			const item = order.items.find((i) => i.id === itemId);
			const ofQuantity = item?.quantity ?? 0;
			return {
				itemId,
				externalRef: item?.externalRef ?? null,
				quantity,
				ofQuantity,
				whole: quantity === null || quantity === ofQuantity,
			};
		}),
	};
}

/** The order as every operation answers it. */
export function orderBody(state: State, order: Order): Record<string, unknown> {
	// Only an order holding personal data has any to erase.
	const erasable = order.recipient !== null || order.shippingAddress !== null;
	return {
		id: order.id,
		tenantId: order.tenantId,
		externalRef: order.externalRef,
		status: orderStatus(order),
		hold: order.hold === null ? null : { ...order.hold },
		recipient: order.recipient,
		// The fake sends no mail, so none bounces.
		recipientEmailBounced: false,
		shippingAddress: order.shippingAddress,
		// The fake ships every order to the customer's address.
		shipTo: null,
		personalDataErasedAt: order.personalDataErasedAt,
		delivery: order.delivery === null ? null : { ...order.delivery },
		deliveryChanges: order.deliveryChanges.map((change) => ({
			...change,
			// A change is asked of the whole order at once.
			printshopOrderId: null,
		})),
		deliveryChangeRefusal: deliveryChangeRefusal(state, order),
		erasable,
		canErase: erasable,
		createdAt: order.createdAt,
		updatedAt: order.updatedAt,
		history: order.history.map((entry) => ({ ...entry })),
		items: order.items.map((item) => itemBody(state, order, item)),
		printshopOrders: order.printshopOrders.map((printshopOrder) => ({
			id: printshopOrder.id,
			printshopConnectionId: printshopOrder.printshopId,
			printshopName: printshopOrder.printshopName,
			adapter: printshopOrder.adapter,
			externalOrderRef: printshopOrder.externalOrderRef,
			// The fake's printshops make an order in one place.
			parts: [],
			itemIds: [...printshopOrder.itemIds],
			submittedAt: printshopOrder.submittedAt,
			lastCheckedAt: null,
		})),
		parcels: order.parcels.map((parcel) => parcelBody(order, parcel)),
	};
}

/** The order as a row of `GET /v1/orders`. */
export function orderRow(state: State, order: Order): Record<string, unknown> {
	const parcelsIn = (status: string) =>
		order.parcels.filter((parcel) => parcel.status === status).length;
	return {
		id: order.id,
		tenantId: order.tenantId,
		externalRef: order.externalRef,
		status: orderStatus(order),
		hold: order.hold === null ? null : { ...order.hold },
		recipient: order.recipient,
		shipTo: null,
		shippingAddress: order.shippingAddress,
		personalDataErasedAt: order.personalDataErasedAt,
		createdAt: order.createdAt,
		updatedAt: order.updatedAt,
		itemCount: order.items.length,
		copies: order.items.reduce((sum, item) => sum + item.quantity, 0),
		parcelCount: order.parcels.length,
		parcels: {
			preparing: parcelsIn('preparing'),
			shipped: parcelsIn(SHIPPED),
			delivered: parcelsIn('delivered'),
		},
		printshopNames: [...new Set(order.items.map((i) => i.printshopName))],
		itemsPreview: order.items.map((item) => ({
			itemId: item.id,
			designId: itemDesignId(item),
			printId: item.printIds[0] ?? null,
			quantity: item.quantity,
			status: item.status,
		})),
		items: order.items.map((item) => itemSummary(state, order, item)),
	};
}
