// Erasing personal data: what a customer put into a design, and the delivery
// an order was sent to. An erased design is never printed, ordered or retried
// again (`409 design_erased`); an order is erased only once it has shipped,
// failed or been cancelled.
import { readDesign } from './designs';
import { findDesign, wasDeleted } from './designs-store';
import { FINAL_STATUSES, orderBody, pathOrder } from './orders';
import { callerOf, type Handler, refusal } from './types';

const NO_CONTENT_STATUS = 204;
const NOT_FOUND_STATUS = 404;
const CONFLICT_STATUS = 409;

/** The event Pixonaut emits when it erases a design. */
const DESIGN_ERASED_EVENT = 'design.personal_data_erased';

/** Erases the design once, emitting `design.personal_data_erased`; erasing it
 *  again answers it unchanged and emits nothing. A deleted design is erased
 *  too, and answered `204`. */
export const eraseDesign: Handler = async (context) => {
	const { storeId } = callerOf(context);
	const id = context.params.id ?? '';
	const design = findDesign(context.state, id, storeId);
	if (design === undefined) {
		return wasDeleted(context.state, id, storeId)
			? { status: NO_CONTENT_STATUS }
			: refusal(NOT_FOUND_STATUS, 'not_found');
	}
	if (design.personalDataErasedAt === null) {
		design.personalDataErasedAt = context.clock.now();
		// A store whose endpoint is revoked gets no delivery, as in Pixonaut.
		await context.webhooks.emit(storeId, [
			{ type: DESIGN_ERASED_EVENT, payload: { designId: design.id } },
		]);
	}
	// Answered as the design route answers it, so both keep one shape.
	return readDesign(context);
};

/** Erases the order's recipient, address and failure reasons once it is
 *  final; before that it is refused `order_in_flight`. */
export const eraseOrder: Handler = (context) => {
	const order = pathOrder(context);
	if (order === undefined) {
		return refusal(NOT_FOUND_STATUS, 'order_not_found');
	}
	if (!FINAL_STATUSES.includes(order.status)) {
		return refusal(CONFLICT_STATUS, 'order_in_flight');
	}
	if (order.personalDataErasedAt === null) {
		order.recipient = null;
		order.shippingAddress = null;
		order.lastError = null;
		for (const entry of order.history) {
			entry.reason = null;
		}
		order.personalDataErasedAt = context.clock.now();
	}
	return { status: 200, body: orderBody(context.state, order) };
};
