// Changing an order's address after it was sent (`PATCH
// /v1/orders/{id}/delivery`). The fake's printshops answer at once: one that
// takes address changes accepts every change, one that does not is refused
// before it is asked. An order whose items all still wait for their prints
// takes the change without asking a printshop, which has not been sent it
// (`carried`). An order with an item that has shipped, failed, been cancelled,
// whose outcome is unknown, or that its printshop is making, or an erased one,
// is past changing. The fake emits no `order.delivery_changed`: a test emits
// it through the control API.
import type { State } from '../state';
import { orderBody } from './order-body';
import {
	type Address,
	AWAITING_PRINTS,
	CANCELLED,
	type Item,
	type Order,
	pathOrder,
	type Recipient,
	recordForEach,
} from './order-model';
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
	const past = pastItem(order);
	if (order.personalDataErasedAt !== null || past !== undefined) {
		return {
			code: 'order_past_address_change',
			status: past?.status ?? CANCELLED,
			unanswered: false,
		};
	}
	if (refusingItem(state, order) !== undefined) {
		return { code: 'address_change_not_accepted' };
	}
	return null;
}

/** The items a change of address still reaches: every one not cancelled. */
function liveItems(order: Order): Item[] {
	return order.items.filter((item) => item.status !== CANCELLED);
}

/** The first item past taking a new address; one when none is left. */
function pastItem(order: Order): Item | { status: string } | undefined {
	const live = liveItems(order);
	return live.length === 0
		? { status: CANCELLED }
		: live.find((item) => PAST_ADDRESS_CHANGE.includes(item.status));
}

/** The first item sent to a printshop that takes no address changes. */
function refusingItem(state: State, order: Order): Item | undefined {
	return liveItems(order).find(
		(item) =>
			item.status !== AWAITING_PRINTS &&
			state.printshops.get(item.printshopId)?.acceptsAddressUpdates !== true,
	);
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
				status: refused.status,
				words: null,
			},
		};
	}
	if (refused !== null) {
		return {
			status: CONFLICT_STATUS,
			body: {
				error: 'address_change_not_accepted',
				printshop: refusingItem(context.state, order)?.printshopName,
			},
		};
	}
	const request = context.body as ChangeDeliveryRequest;
	const now = context.clock.now();
	const added = order.shippingAddress === null;
	order.recipient = request.recipient;
	order.shippingAddress = request.shippingAddress;
	// No printshop is asked about an order it has not been sent.
	const outcome = liveItems(order).every(
		(item) => item.status === AWAITING_PRINTS,
	)
		? CARRIED
		: ACCEPTED;
	order.delivery = { state: outcome, words: null, at: now };
	order.deliveryChanges.push({
		at: now,
		added,
		by: BY_STORE_KEY,
		outcome,
		words: null,
	});
	recordForEach(order, now, { type: 'order.delivery_changed' });
	return { status: 200, body: orderBody(context.state, order) };
};
