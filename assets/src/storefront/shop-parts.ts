import type { SplitMountOptions } from '../types/sdk';

/** The add-to-cart form's own option fields: the classic form's variation
 *  table and the block form's variation selector. */
const OPTIONS_SELECTOR =
	'table.variations, .wp-block-woocommerce-add-to-cart-with-options-variation-selector';

/** The classic variation form's price and availability of the chosen
 *  variation, which it prints apart from the quantity and the button. */
const VARIATION_PRICE_SELECTOR = '.single_variation';

/** The checkbox approval's box and proof buttons, in the form container. */
const APPROVAL_SELECTOR = '.pixonaut-approval';

/** Elements after the form container that draw nothing. */
const UNDRAWN_SELECTOR = 'input[type="hidden"], script, template';

/** The element the cart row is gathered in. */
export const CART_ROW_CLASS = 'pixonaut-cart-row';

/** A place in the printed form for a part of the add-to-cart form; it
 *  names the part in `data-pixonaut-slot`. */
const SLOT_SELECTOR = '.pixonaut-placeholder__slot';
const SLOT_CLASS = 'pixonaut-placeholder__slot';

/** The slots of the option fields and of the cart row. Any other holds the
 *  attribute field it is named after. */
const OPTIONS_SLOT = 'options';
const CART_SLOT = 'cart';
const CART_SLOT_SELECTOR = `[data-pixonaut-slot="${CART_SLOT}"]`;

/** The form a slot is in, and the side's form it is printed in. */
const PRINTED_FORM_SELECTOR = '.pixonaut-form__placeholder';
const FORM_CONTAINER_SELECTOR = '.pixonaut-form';

/** The block form's element of one attribute. */
const BLOCK_ATTRIBUTE_SELECTOR =
	'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute';

/** The classic form's table of attribute rows. */
const CLASSIC_TABLE_SELECTOR = 'table.variations';

/** The table a classic attribute row is moved in. It keeps the classic
 *  form's `variations` class, which WooCommerce's script finds the fields
 *  by. */
export const ATTRIBUTE_TABLE_CLASS = 'variations pixonaut-attribute';

/**
 * The parts of the add-to-cart form the designer draws inside its own
 * steps: the form's option fields, at the start, and the cart row (the
 * approval box, the chosen variation's price, the quantity, Add to cart and
 * whatever the theme or another plugin prints beside them), at the end.
 */
export type ShopParts = {
	/** The option fields; null on a product without variations. */
	options: HTMLElement | null;
	/** The cart row, gathered in an element of its own. */
	cartRow: HTMLElement;
	/** Where each part that is not the cart row goes back to
	 *  (`returnShopParts()`): the option fields, and each attribute's field
	 *  put in a slot of its own. */
	homes: Map<HTMLElement, Comment>;
};

/** The form container, with the parts already gathered from it. */
type GatheredContainer = HTMLElement & {
	pixonautShopParts?: ShopParts | null;
};

/**
 * Gathers the cart row of the add-to-cart form around `container` (the
 * form container) in one element, put where the form container's next
 * part was, and finds the form's option fields. The cart row is the
 * approval box, the classic form's variation price, then every part after
 * the form container but the option fields and what draws nothing; null
 * when there is none of them, such as a theme's form without a button.
 * Moved, never copied, so the listeners on each part keep working; the
 * designer gives the cart row back to its place when it is destroyed. The
 * parts are gathered once: the script printed inline and the storefront
 * script ask in turn.
 */
export function gatherShopParts(
	container: HTMLElement,
	form: HTMLFormElement,
): ShopParts | null {
	const gathered = container as GatheredContainer;
	if (gathered.pixonautShopParts === undefined) {
		gathered.pixonautShopParts = gatherParts(container, form);
	}
	return gathered.pixonautShopParts;
}

function gatherParts(
	container: HTMLElement,
	form: HTMLFormElement,
): ShopParts | null {
	const options = form.querySelector<HTMLElement>(OPTIONS_SELECTOR);
	const parts: Element[] = [];
	const approval = container.querySelector(APPROVAL_SELECTOR);
	if (approval !== null) {
		parts.push(approval);
	}
	const price = form.querySelector(VARIATION_PRICE_SELECTOR);
	if (price !== null && !container.contains(price)) {
		parts.push(price);
	}
	const following: Element[] = [];
	for (
		let next = container.nextElementSibling;
		next !== null;
		next = next.nextElementSibling
	) {
		if (
			!next.matches(UNDRAWN_SELECTOR) &&
			!(options !== null && next.contains(options))
		) {
			following.push(next);
		}
	}
	if (following.length === 0) {
		return null;
	}
	const cartRow = container.ownerDocument.createElement('div');
	cartRow.className = CART_ROW_CLASS;
	container.after(cartRow);
	cartRow.append(...parts, ...following);
	return { options, cartRow, homes: new Map() };
}

/**
 * Finds the part of `form` that holds the attribute field `name`: the block
 * form's attribute element, or the classic form's row, in a table of its
 * own put before the form's table, where the designer gives it back. Null
 * when the form has no such field.
 */
export function attributePart(
	form: HTMLFormElement,
	name: string,
): HTMLElement | null {
	const field = Array.from(
		form.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
			'input, select',
		),
	).find((one) => one.name === name);
	if (field === undefined) {
		return null;
	}
	const block = field.closest<HTMLElement>(BLOCK_ATTRIBUTE_SELECTOR);
	if (block !== null) {
		return block;
	}
	const row = field.closest('tr');
	const table = row?.closest<HTMLTableElement>(CLASSIC_TABLE_SELECTOR);
	if (
		row === null ||
		row === undefined ||
		table === null ||
		table === undefined
	) {
		return null;
	}
	// A row lent before is in its own table already.
	if (table.className === ATTRIBUTE_TABLE_CLASS) {
		return table;
	}
	const own = form.ownerDocument.createElement('table');
	own.className = ATTRIBUTE_TABLE_CLASS;
	own.setAttribute('role', 'presentation');
	const body = own.createTBody();
	table.before(own);
	body.append(row);
	return own;
}

/**
 * Whether `element` holds a printed form's slot for the cart row. Without one
 * (no printed form, or an older theme copy of it) the cart row would stay
 * outside the form container, out of the approval's reach.
 */
export function hasCartSlot(element: Element): boolean {
	return element.querySelector(CART_SLOT_SELECTOR) !== null;
}

/**
 * Puts the add-to-cart form's parts in the slots of the printed form in
 * `target` (the side's form target), where its designer draws them, so
 * nothing moves when the designer takes them. A part with no slot stays
 * where it is, such as under a theme's copy of the printed form without
 * them. Returns the parts holding option fields that stand in a slot, once
 * or from an earlier call.
 */
export function printShopParts(
	parts: ShopParts,
	target: HTMLElement,
	form: HTMLFormElement,
): HTMLElement[] {
	for (const slot of target.querySelectorAll<HTMLElement>(SLOT_SELECTOR)) {
		if (slot.firstElementChild !== null) {
			continue;
		}
		const name = slot.dataset.pixonautSlot ?? '';
		if (name === CART_SLOT) {
			slot.append(parts.cartRow);
			continue;
		}
		const part =
			name === OPTIONS_SLOT ? parts.options : attributePart(form, name);
		if (part !== null) {
			const home = target.ownerDocument.createComment('');
			part.before(home);
			parts.homes.set(part, home);
			slot.append(part);
		}
	}
	return [...parts.homes.keys()].filter((part) => inSlot(part));
}

/** Whether `part` stands in a slot of the printed form. */
function inSlot(part: HTMLElement): boolean {
	return part.parentElement?.classList.contains(SLOT_CLASS) ?? false;
}

/**
 * Puts the parts of the printed form on the page in its slots, as soon as
 * the add-to-cart form is printed: only a product with one side has any.
 */
export function printPageParts(page: Document): void {
	for (const printed of page.querySelectorAll(PRINTED_FORM_SELECTOR)) {
		const target = printed.parentElement;
		const container = printed.closest<HTMLElement>(FORM_CONTAINER_SELECTOR);
		const form = printed.closest('form');
		if (target === null || container === null || form === null) {
			continue;
		}
		if (!hasCartSlot(printed)) {
			continue;
		}
		const parts = gatherShopParts(container, form);
		if (parts !== null) {
			printShopParts(parts, target, form);
		}
	}
}

/**
 * Puts each part no designer holds back where the add-to-cart form had it:
 * one in a slot of the printed form, and one a destroyed designer gave back
 * where it took it, in a printed form it has since replaced. A part in
 * `lent`, which a designer is about to take, stays.
 */
export function returnShopParts(
	parts: ShopParts,
	container: HTMLElement,
	lent: ReadonlySet<unknown> = new Set(),
): void {
	const away = (part: HTMLElement) => {
		if (lent.has(part)) {
			return false;
		}
		const printed = inSlot(part);
		if (part.isConnected && !printed) {
			return false;
		}
		if (printed) {
			part.parentElement?.remove();
		}
		return true;
	};
	for (const [part, home] of parts.homes) {
		if (away(part)) {
			home.after(part);
			home.remove();
			parts.homes.delete(part);
		}
	}
	if (away(parts.cartRow)) {
		container.after(parts.cartRow);
	}
}

/** The mount options that draw a host's own parts in the designer's form:
 *  its option fields in a first step, its cart row on the last. */
export type ShopStepOptions = Pick<
	SplitMountOptions,
	'optionsStep' | 'lastStepContent'
>;

/** The mount options that draw `parts` in the designer's steps. */
export function shopStepOptions(parts: ShopParts): ShopStepOptions {
	return {
		optionsStep: parts.options === null ? {} : { content: parts.options },
		lastStepContent: parts.cartRow,
	};
}
