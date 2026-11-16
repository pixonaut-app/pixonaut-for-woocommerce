// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { OWN_STEP_ATTRIBUTE } from './own-step';
import { liftResetLink, RESET_SELECTOR } from './reset-link';
import {
	gatherShopParts,
	printShopParts,
	returnShopParts,
	type ShopParts,
} from './shop-parts';

/** WooCommerce's Clear link, as its template prints it. */
const LINK =
	'<a class="reset_variations" href="#" aria-label="Clear options">Clear</a>';

/** A row of the classic form's variations table; the last holds the link. */
const row = (name: string, last = false) =>
	`<tr><th class="label"><label for="${name}">${name}</label></th><td class="value"><select id="${name}" name="${name}"></select>${last ? LINK : ''}</td></tr>`;

/** A place in the printed form; an attribute's in a step of its own is
 *  marked so, as the server prints it. */
const slot = (name: string, own: boolean) =>
	`<div class="pixonaut-placeholder__slot" data-pixonaut-slot="${name}"${own && name.startsWith('attribute_') ? ' data-pixonaut-own-step' : ''}></div>`;

/** A variable product's form with two attributes and the side's printed form
 *  holding `slots`, each attribute's in a step of its own unless `own` is
 *  false. */
function page(slots: string[], own = true) {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<table class="variations"><tbody>${row('attribute_size')}${row('attribute_colour', true)}</tbody></table>
			<div class="variations_button">
				<div class="pixonaut-form">
					<div id="pixonaut-form-front" class="pixonaut-form__side"><div class="pixonaut-form__placeholder">${slots.map((name) => slot(name, own)).join('')}</div></div>
				</div>
				<div class="quantity"><input type="number" name="quantity"></div>
				<button type="submit">Add to cart</button>
			</div>
		</form>`;
	const form = document.querySelector('form') as HTMLFormElement;
	const container = document.querySelector('.pixonaut-form') as HTMLElement;
	const target = document.querySelector('#pixonaut-form-front') as HTMLElement;
	const parts = gatherShopParts(container, form) as ShopParts;
	const link = () => form.querySelector(RESET_SELECTOR) as HTMLElement;
	return { form, container, target, parts, link };
}

describe('liftResetLink', () => {
	it('puts the link at the start of the cart row, named for every option, once the attributes stand in steps of their own', () => {
		const { form, target, parts, link } = page([
			'attribute_size',
			'attribute_colour',
			'cart',
		]);

		printShopParts(parts, target, form);

		expect(parts.cartRow.firstElementChild).toBe(link());
		expect(link().closest('tr')).toBeNull();
		expect(link().textContent).toBe('Clear options');
		// The cart row's own parts follow it, in their order.
		expect(
			Array.from(parts.cartRow.children)
				.slice(1)
				.map((part) => part.className),
		).toEqual(['quantity', '']);
	});

	it('leaves the link in the last row while the rows stand together', () => {
		const { form, target, parts, link } = page(['options', 'cart']);

		printShopParts(parts, target, form);

		expect(link().closest('tr')?.querySelector('select')?.name).toBe(
			'attribute_colour',
		);
		expect(link().textContent).toBe('Clear');
	});

	it('leaves the link in the last row of a step holding other attributes', () => {
		const { form, target, parts, link } = page(
			['attribute_size', 'attribute_colour', 'cart'],
			false,
		);

		printShopParts(parts, target, form);

		expect(link().closest('tr')?.querySelector('select')?.name).toBe(
			'attribute_colour',
		);
		expect(parts.resetLink).toBeUndefined();
	});

	it('takes the link once, however often it is asked', () => {
		const { form, target, parts, link } = page([
			'attribute_size',
			'attribute_colour',
			'cart',
		]);
		printShopParts(parts, target, form);

		liftResetLink(parts, form);
		printShopParts(parts, target, form);

		expect(form.querySelectorAll(RESET_SELECTOR)).toHaveLength(1);
		expect(parts.cartRow.firstElementChild).toBe(link());
		expect(link().textContent).toBe('Clear options');
	});

	it('keeps the words of a link with no longer name', () => {
		const { form, target, parts, link } = page([
			'attribute_size',
			'attribute_colour',
			'cart',
		]);
		link().removeAttribute('aria-label');

		printShopParts(parts, target, form);

		expect(parts.cartRow.firstElementChild).toBe(link());
		expect(link().textContent).toBe('Clear');
	});
});

describe('returnShopParts', () => {
	it('puts the link back in the row it was taken from, with its words there', () => {
		const { form, container, target, parts, link } = page([
			'attribute_size',
			'attribute_colour',
			'cart',
		]);
		printShopParts(parts, target, form);

		returnShopParts(parts, container);

		expect(link().closest('td')?.querySelector('select')?.name).toBe(
			'attribute_colour',
		);
		expect(link().textContent).toBe('Clear');
		expect(parts.resetLink).toBeUndefined();
		expect(form.querySelectorAll(RESET_SELECTOR)).toHaveLength(1);
	});

	it('keeps the link in the form when its row is gone', () => {
		const { form, container, target, parts, link } = page([
			'attribute_size',
			'attribute_colour',
			'cart',
		]);
		printShopParts(parts, target, form);
		(form.querySelector('select[name="attribute_colour"]') as HTMLElement)
			.closest('table')
			?.remove();

		returnShopParts(parts, container);

		expect(form.contains(link())).toBe(true);
		expect(link().textContent).toBe('Clear');
	});

	it('takes the link again for the next designer', () => {
		const { form, container, target, parts, link } = page([
			'attribute_size',
			'attribute_colour',
			'cart',
		]);
		printShopParts(parts, target, form);
		returnShopParts(parts, container);
		const returned = link();

		// The next designer lends the row to a step of its own again, and
		// gathers the cart row again, out of the form.
		returned.closest('table')?.toggleAttribute(OWN_STEP_ATTRIBUTE, true);
		liftResetLink(parts, form);

		expect(parts.cartRow.firstElementChild).toBe(returned);
	});
});
