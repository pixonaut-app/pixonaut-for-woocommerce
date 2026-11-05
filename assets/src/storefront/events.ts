import { applyFilters, doAction } from '@wordpress/hooks';
import type { Approved, MountOptions } from '../types/sdk';

/** Filters a side's mount options, targets included, before it mounts. */
export const MOUNT_OPTIONS_FILTER = 'pixonaut.designer.mountOptions';

/** Fires when a side's design was approved and its proof token is ready. */
export const APPROVED_ACTION = 'pixonaut.designer.approved';

/** Fires before the add-to-cart form is sent with the proof tokens. */
export const BEFORE_SUBMIT_ACTION = 'pixonaut.cart.beforeSubmit';

/** The DOM events dispatched on the designer's form container beside each
 *  action; they bubble. */
export const APPROVED_EVENT = 'pixonaut:designer-approved';

/** Cancelable: a listener that calls `preventDefault()` keeps the form from
 *  being sent. */
export const BEFORE_SUBMIT_EVENT = 'pixonaut:cart-before-submit';

/** Dispatched after the chosen variation changed the price the product
 *  page shows, and after the original came back; it bubbles and cannot be
 *  cancelled. */
export const PRICE_EVENT = 'pixonaut:price';

export type SideContext = { productId: number; side: string };

/** `result` is what the side's `approve()` resolved: its proof, the
 *  `placementId` it is of, and `sides`, the proof of each side its designer
 *  approved, the lead first. */
export type ApprovedDetail = SideContext & { result: Approved };

export type BeforeSubmitDetail = {
	productId: number;
	form: HTMLFormElement;
	/** Proof tokens by side key. */
	designs: Record<string, string>;
};

export type PriceDetail = {
	productId: number;
	/** The side whose print size the price is for: the product's first. */
	side: string;
	/** That side's print size, an index into its mounted menu; null without
	 *  a size menu or a personalised variation. */
	printSizeIndex: number | null;
	/** The variation whose price is shown; null when the original came back. */
	variationId: number | null;
	/** The HTML written into the targets, formatted as the store formats
	 *  prices. */
	priceHtml: string;
};

export function filterMountOptions<T extends MountOptions>(
	options: T,
	context: SideContext,
): T {
	const filtered = applyFilters(MOUNT_OPTIONS_FILTER, options, context);
	return typeof filtered === 'object' && filtered !== null
		? (filtered as T)
		: options;
}

export function announceApproved(
	target: HTMLElement,
	detail: ApprovedDetail,
): void {
	doAction(APPROVED_ACTION, detail);
	target.dispatchEvent(
		new CustomEvent(APPROVED_EVENT, { detail, bubbles: true }),
	);
}

/** Runs the before-submit action and event; false when a listener cancelled
 *  the event. */
export function announceBeforeSubmit(
	target: HTMLElement,
	detail: BeforeSubmitDetail,
): boolean {
	doAction(BEFORE_SUBMIT_ACTION, detail);
	return target.dispatchEvent(
		new CustomEvent(BEFORE_SUBMIT_EVENT, {
			detail,
			bubbles: true,
			cancelable: true,
		}),
	);
}
