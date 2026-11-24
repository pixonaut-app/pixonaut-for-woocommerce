// The order and item events Pixonaut sends as an order's items move, built
// from what the fake keeps of the order. Every payload names the order and
// its external reference; an item event names the item, its own reference,
// its latest submission and its prints.
import type { EmitEvent } from './deliveries';

/** The order as an event names it. */
export type EventOrder = { id: string; externalRef: string | null };

/** The item as an item event names it. */
export type EventItem = {
	id: string;
	externalRef: string | null;
	submissionId: string;
	printIds: string[];
};

/** What every item event's payload starts with. */
export function itemPayload(
	order: EventOrder,
	item: EventItem,
): Record<string, unknown> {
	return {
		orderId: order.id,
		externalRef: order.externalRef,
		itemId: item.id,
		itemExternalRef: item.externalRef,
		submissionId: item.submissionId,
		printIds: [...item.printIds],
	};
}

/** `item.status_changed` for one step of an item. */
export function itemStatusChanged(
	order: EventOrder,
	item: EventItem,
	previousStatus: string,
	status: string,
	via: string,
): EmitEvent {
	return {
		type: 'item.status_changed',
		payload: { ...itemPayload(order, item), previousStatus, status, via },
	};
}

/** `order.status_changed`, sent when the order's own status, read from its
 *  items', changes. */
export function orderStatusChanged(
	order: EventOrder,
	previousStatus: string,
	status: string,
	via: string,
): EmitEvent {
	return {
		type: 'order.status_changed',
		payload: {
			orderId: order.id,
			externalRef: order.externalRef,
			previousStatus,
			status,
			via,
		},
	};
}
