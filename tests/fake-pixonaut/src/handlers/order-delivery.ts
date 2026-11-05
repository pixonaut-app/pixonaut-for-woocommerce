// Changing an order's address after it was sent (`PATCH
// /v1/orders/{id}/delivery`). The fake's printshops answer at once: one that
// takes address changes accepts every change, one that does not is refused
// before it is asked. An order still waiting for its prints takes the change
// without asking its printshop, which has not been sent it (`carried`). An order that has shipped, failed, been cancelled or
// erased, whose outcome is unknown, or that its printshop is making is past
// changing. The fake emits no `order.delivery_changed`: a test emits it
// through the control API.
import type { State } from '../state';
import {
	type Address,
	type Order,
	orderBody,
	pathOrder,
	type Recipient,
} from './orders';
import { type Handler, refusal } from './types';

const NOT_FOUND_STATUS = 404;
const CONFLICT_STATUS = 409;

/** Statuses an order no longer takes a new address in. */
const PAST_ADDRESS_CHANGE = [
	'queued',
	'printed',
	'shipped',
	'failed',
	'outcome_unknown',
	'cancelled',
];
/** Who made a change, when it was a store's integration key. */
const BY_STORE_KEY = 'store_key';
/** What an accepted change shows as. */
const ACCEPTED = 'accepted';
/** What a change to an order still waiting for its prints shows as: the
 *  printshop has not been sent it, so the order takes the address there. */
const CARRIED = 'carried';
/** The status of an order still waiting for its prints. */
const AWAITING_PRINTS = 'awaiting_prints';

type ChangeDeliveryRequest = {
	recipient: Recipient;
	shippingAddress: Address;
};

/** Why a change of `order`'s address would be refused now, as `GET
 *  /v1/orders/{id}` answers it; null when it would be taken. */
export function deliveryChangeRefusal(
	state: State,
	order: Order,
): Record<string, unknown> | null {
	if (
		order.personalDataErasedAt !== null ||
		PAST_ADDRESS_CHANGE.includes(order.status)
	) {
		return {
			code: 'order_past_address_change',
			status: order.status,
			unanswered: false,
		};
	}
	if (
		order.status !== AWAITING_PRINTS &&
		state.printshops.get(order.printshopId)?.acceptsAddressUpdates !== true
	) {
		return { code: 'address_change_not_accepted' };
	}
	return null;
}

export const changeOrderDelivery: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	const refused = deliveryChangeRefusal(context.state, order);
	if (refused?.code === 'order_past_address_change') {
		return {
			status: CONFLICT_STATUS,
			body: {
				error: 'order_past_address_change',
				status: order.status,
				words: null,
			},
		};
	}
	if (refused !== null) {
		return {
			status: CONFLICT_STATUS,
			body: {
				error: 'address_change_not_accepted',
				printshop: order.printshopName,
			},
		};
	}
	const request = context.body as ChangeDeliveryRequest;
	const now = context.clock.now();
	const added = order.shippingAddress === null;
	order.recipient = request.recipient;
	order.shippingAddress = request.shippingAddress;
	// No printshop is asked about an order it has not been sent.
	const outcome = order.status === AWAITING_PRINTS ? CARRIED : ACCEPTED;
	order.delivery = { state: outcome, words: null, at: now };
	order.deliveryChanges.push({
		at: now,
		added,
		by: BY_STORE_KEY,
		outcome,
		words: null,
	});
	order.updatedAt = now;
	order.history.push({
		seq: order.history.length + 1,
		at: now,
		type: 'order.delivery_changed',
		status: order.status,
		reason: null,
		via: BY_STORE_KEY,
		automation: null,
		releasedBy: null,
	});
	return { status: 200, body: orderBody(context.state, order) };
};
