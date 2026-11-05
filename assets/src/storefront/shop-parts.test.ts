// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	CART_ROW_CLASS,
	gatherShopParts,
	printPageParts,
	printShopParts,
	returnShopParts,
	shopStepOptions,
} from './shop-parts';

/** The form container as the server prints it, with the approval box. */
const CONTAINER = `
	<div class="pixonaut-form">
		<div id="pixonaut-form-front" class="pixonaut-form__side"></div>
		<div class="pixonaut-approval"><input type="checkbox" class="pixonaut-approval__box"></div>
	</div>`;

function parts(markup: string) {
	document.body.innerHTML = markup;
	const form = document.querySelector('form') as HTMLFormElement;
	const container = document.querySelector('.pixonaut-form') as HTMLElement;
	return { form, container, gathered: gatherShopParts(container, form) };
}

/** The class names of the cart row's parts, in order. */
function rowParts(row: HTMLElement | undefined): string[] {
	return Array.from(row?.children ?? []).map(
		(part) => part.className || part.tagName.toLowerCase(),
	);
}

describe('gatherShopParts', () => {
	it('gathers the classic variation form’s approval, variation price, quantity and button, and finds its variation table', () => {
		const { form, container, gathered } = parts(`
			<form class="variations_form cart">
				<table class="variations"><tbody><tr><td><select name="attribute_size"></select></td></tr></tbody></table>
				<div class="single_variation_wrap">
					<div class="woocommerce-variation single_variation"></div>
					<div class="woocommerce-variation-add-to-cart variations_button">
						${CONTAINER}
						<div class="quantity"><input type="number" name="quantity"></div>
						<button type="submit" class="single_add_to_cart_button">Add to cart</button>
						<input type="hidden" name="add-to-cart" value="7">
						<input type="hidden" name="variation_id" value="0">
					</div>
				</div>
			</form>`);

		expect(gathered?.options).toBe(form.querySelector('table.variations'));
		expect(rowParts(gathered?.cartRow)).toEqual([
			'pixonaut-approval',
			'woocommerce-variation single_variation',
			'quantity',
			'single_add_to_cart_button',
		]);
		// Put where the quantity was, the hidden fields left as they are.
		expect(container.nextElementSibling).toBe(gathered?.cartRow);
		expect(gathered?.cartRow.className).toBe(CART_ROW_CLASS);
		expect(gathered?.cartRow.nextElementSibling?.getAttribute('name')).toBe(
			'add-to-cart',
		);
	});

	it('gathers a simple product’s quantity and button, with no option fields', () => {
		const { gathered } = parts(`
			<form class="cart">
				${CONTAINER}
				<div class="quantity"><input type="number" name="quantity"></div>
				<button type="submit" name="add-to-cart" value="7" class="single_add_to_cart_button">Add to cart</button>
			</form>`);

		expect(gathered?.options).toBeNull();
		expect(rowParts(gathered?.cartRow)).toEqual([
			'pixonaut-approval',
			'quantity',
			'single_add_to_cart_button',
		]);
		expect(gathered === null ? null : shopStepOptions(gathered)).toEqual({
			optionsStep: {},
			lastStepContent: gathered?.cartRow,
		});
	});

	it('leaves the block form’s variation selector out of the cart row', () => {
		const { form, gathered } = parts(`
			<form class="wc-block-add-to-cart-with-options cart">
				${CONTAINER}
				<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector"></div>
				<div class="wp-block-woocommerce-product-stock-indicator"></div>
				<div class="wp-block-group"><button type="submit">Add to cart</button></div>
				<div class="single_variation_wrap"><input type="hidden" name="variation_id"></div>
			</form>`);

		const selector = form.querySelector<HTMLElement>(
			'.wp-block-woocommerce-add-to-cart-with-options-variation-selector',
		);
		expect(gathered?.options).toBe(selector);
		expect(rowParts(gathered?.cartRow)).toEqual([
			'pixonaut-approval',
			'wp-block-woocommerce-product-stock-indicator',
			'wp-block-group',
			'single_variation_wrap',
		]);
		expect(gathered === null ? null : shopStepOptions(gathered)).toEqual({
			optionsStep: { content: selector },
			lastStepContent: gathered?.cartRow,
		});
	});

	it('gathers nothing from a form with nothing after the container', () => {
		const { gathered } = parts(`<form class="cart">${CONTAINER}</form>`);

		expect(gathered).toBeNull();
		expect(document.querySelector(`.${CART_ROW_CLASS}`)).toBeNull();
	});
});

/** A place for a part of the add-to-cart form in the printed form. */
const slot = (name: string) =>
	`<div class="pixonaut-placeholder__slot" data-pixonaut-slot="${name}"></div>`;

const slotOf = (name: string) =>
	document.querySelector(`[data-pixonaut-slot="${name}"]`);

/** A variable product's form with the side's printed form, holding `slots`. */
function printedPage(slots: string[] = ['options', 'cart']) {
	return parts(`
		<form class="variations_form cart">
			<table class="variations"><tbody><tr><td><select name="attribute_size"></select></td></tr></tbody></table>
			<div class="variations_button">
				<div class="pixonaut-form">
					<div id="pixonaut-form-front" class="pixonaut-form__side"><div class="pixonaut-form__placeholder">${slots.map(slot).join('')}</div></div>
					<div class="pixonaut-approval"></div>
				</div>
				<button type="submit">Add to cart</button>
			</div>
		</form>`);
}

describe('printShopParts', () => {
	const target = () =>
		document.querySelector('#pixonaut-form-front') as HTMLElement;

	it('puts the option fields and the cart row in their slots', () => {
		const { form, gathered } = printedPage();
		const shop = gathered as NonNullable<typeof gathered>;

		expect(printShopParts(shop, target(), form)).toEqual([shop.options]);

		expect(slotOf('options')?.firstElementChild).toBe(
			form.querySelector('table.variations'),
		);
		expect(slotOf('cart')?.firstElementChild).toBe(shop.cartRow);
	});

	it('does nothing more when the parts stand in their slots already', () => {
		const { form, gathered } = printedPage();
		const shop = gathered as NonNullable<typeof gathered>;
		printShopParts(shop, target(), form);

		expect(printShopParts(shop, target(), form)).toEqual([shop.options]);

		expect(slotOf('options')?.children).toHaveLength(1);
		expect(shop.homes.size).toBe(1);
	});

	it('puts an attribute’s field in the slot named after it, in a table of its own', () => {
		const { form, gathered } = printedPage(['attribute_size', 'cart']);
		const shop = gathered as NonNullable<typeof gathered>;

		const [part] = printShopParts(shop, target(), form);

		expect(slotOf('attribute_size')?.firstElementChild).toBe(part);
		expect(part?.querySelector('select')?.name).toBe('attribute_size');
		expect(
			form.querySelector('table.variations:not(.pixonaut-attribute) tr'),
		).toBeNull();
	});

	it('leaves a slot empty for a field the form does not have', () => {
		const { form, gathered } = printedPage(['attribute_colour']);

		printShopParts(gathered as NonNullable<typeof gathered>, target(), form);

		expect(slotOf('attribute_colour')?.children).toHaveLength(0);
	});

	it('leaves the parts where they are without slots', () => {
		const { form, gathered, container } = printedPage([]);
		const shop = gathered as NonNullable<typeof gathered>;

		expect(printShopParts(shop, target(), form)).toEqual([]);

		expect(shop.options?.parentElement).toBe(form);
		expect(container.nextElementSibling).toBe(shop.cartRow);
	});
});

describe('printPageParts', () => {
	it('places the parts, and the storefront script finds them gathered', () => {
		const { container, form } = (() => {
			document.body.innerHTML = `
				<form class="variations_form cart">
					<table class="variations"><tbody><tr><td><select name="attribute_size"></select></td></tr></tbody></table>
					<div class="pixonaut-form">
						<div id="pixonaut-form-front"><div class="pixonaut-form__placeholder">${slot('options')}${slot('cart')}</div></div>
					</div>
					<button type="submit">Add to cart</button>
				</form>`;
			return {
				container: document.querySelector('.pixonaut-form') as HTMLElement,
				form: document.querySelector('form') as HTMLFormElement,
			};
		})();

		printPageParts(document);

		const shop = gatherShopParts(container, form);
		expect(document.querySelectorAll(`.${CART_ROW_CLASS}`)).toHaveLength(1);
		expect(slotOf('cart')?.firstElementChild).toBe(shop?.cartRow);
		expect(slotOf('options')?.firstElementChild).toBe(shop?.options);
	});

	it('leaves a printed form without slots, and its approval box, alone', () => {
		document.body.innerHTML = `
			<form class="cart">
				<div class="pixonaut-form">
					<div class="pixonaut-form__placeholder"></div>
					<div class="pixonaut-approval"></div>
				</div>
				<button type="submit">Add to cart</button>
			</form>`;

		printPageParts(document);

		expect(document.querySelector(`.${CART_ROW_CLASS}`)).toBeNull();
		expect(
			document.querySelector('.pixonaut-form .pixonaut-approval'),
		).not.toBeNull();
	});

	it('leaves a page without a printed form alone', () => {
		document.body.innerHTML =
			'<form><div class="pixonaut-form"></div><button type="submit"></button></form>';

		printPageParts(document);

		expect(document.querySelector(`.${CART_ROW_CLASS}`)).toBeNull();
	});
});

describe('returnShopParts', () => {
	it('puts printed parts back where the form had them', () => {
		const { form, container, gathered } = printedPage();
		const shop = gathered as NonNullable<typeof gathered>;
		const target = document.querySelector(
			'#pixonaut-form-front',
		) as HTMLElement;
		printShopParts(shop, target, form);

		returnShopParts(shop, container);

		expect(form.firstElementChild).toBe(shop.options);
		expect(container.nextElementSibling).toBe(shop.cartRow);
		expect(slotOf('options')).toBeNull();
		expect(slotOf('cart')).toBeNull();
	});

	it('puts back parts a designer gave back beside a printed form it replaced', () => {
		const { form, container, gathered } = printedPage();
		const shop = gathered as NonNullable<typeof gathered>;
		const target = document.querySelector(
			'#pixonaut-form-front',
		) as HTMLElement;
		printShopParts(shop, target, form);
		// The designer took the parts, drew its form in place of the target's
		// content, then gave the parts back where it took them.
		const slots = [slotOf('options'), slotOf('cart')];
		const drawn = document.createElement('div');
		drawn.append(shop.options as HTMLElement, shop.cartRow);
		target.replaceChildren(drawn);
		slots[0]?.append(shop.options as HTMLElement);
		slots[1]?.append(shop.cartRow);

		returnShopParts(shop, container);

		expect(form.firstElementChild).toBe(shop.options);
		expect(container.nextElementSibling).toBe(shop.cartRow);
	});

	it('leaves parts a designer holds where they are', () => {
		const { container, gathered } = printedPage();
		const shop = gathered as NonNullable<typeof gathered>;
		const target = document.querySelector(
			'#pixonaut-form-front',
		) as HTMLElement;
		const drawn = document.createElement('div');
		target.replaceChildren(drawn);
		drawn.append(shop.options as HTMLElement, shop.cartRow);

		returnShopParts(shop, container);

		expect(shop.options?.parentElement).toBe(drawn);
		expect(shop.cartRow.parentElement).toBe(drawn);
	});
});
