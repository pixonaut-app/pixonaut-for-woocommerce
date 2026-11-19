// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import type { ChoicePrices } from './choice-prices';
import { drawOptionPrices } from './option-prices';

/** A poster in two sizes, bare or framed; the large size has no oak frame. */
const POSTER: ChoicePrices = {
	fields: ['attribute_size', 'attribute_frame'],
	roles: { attribute_size: 'price', attribute_frame: 'add_on' },
	decimals: 0,
	variations: [
		{ values: { attribute_size: 's', attribute_frame: 'none' }, price: 499 },
		{ values: { attribute_size: 's', attribute_frame: 'oak' }, price: 698 },
		{ values: { attribute_size: 'l', attribute_frame: 'none' }, price: 1599 },
	],
	format: {
		pattern: '%2$s %1$s',
		symbol: 'kr',
		decimalSeparator: ',',
		thousandSeparator: ' ',
	},
	words: { add_on: '+%s', from: 'from %s', price: '%s' },
	label: '%1$s · %2$s',
};

const SIZES =
	'<option value="s">30×40</option><option value="l">50×70</option>';
const FRAMES =
	'<option value="none">No frame</option><option value="oak">Oak</option>';

function classicForm(): HTMLFormElement {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<select name="attribute_size"><option value="">Choose an option</option>${SIZES}</select>
			<select name="attribute_frame"><option value="">Choose an option</option>${FRAMES}</select>
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

function blockForm(): HTMLFormElement {
	const chips = (field: string, options: Array<[string, string]>) => `
		<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">
			<div role="radiogroup">
				${options
					.map(
						([value, name]) =>
							`<button type="button" role="radio" value="${value}" aria-checked="false"><span class="wc-block-product-filter-chips__label"><span class="wc-block-product-filter-chips__text">
								${name}
							</span></span></button>`,
					)
					.join('')}
			</div>
			<input type="hidden" name="${field}" value="">
		</div>`;
	document.body.innerHTML = `<form>${chips('attribute_size', [
		['s', '30×40'],
		['l', '50×70'],
	])}${chips('attribute_frame', [
		['none', 'No frame'],
		['oak', 'Oak'],
	])}</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

function texts(form: HTMLFormElement, field: string): string[] {
	const select = form.querySelector<HTMLSelectElement>(
		`select[name="${field}"]`,
	);
	if (select !== null) {
		return Array.from(select.options)
			.filter((option) => option.value !== '')
			.map((option) => option.text);
	}
	const input = form.querySelector(`input[name="${field}"]`);
	return Array.from(input?.parentElement?.querySelectorAll('button') ?? []).map(
		(chip) => (chip.textContent ?? '').trim(),
	);
}

function choose(form: HTMLFormElement, field: string, value: string): void {
	const select = form.querySelector<HTMLSelectElement>(
		`select[name="${field}"]`,
	);
	if (select === null) {
		throw new Error(`No select ${field}`);
	}
	select.value = value;
	select.dispatchEvent(new Event('change', { bubbles: true }));
}

/** Lets a MutationObserver's callback run. */
const observed = () => new Promise((resolve) => setTimeout(resolve));

describe('drawOptionPrices on the classic form', () => {
	it("writes each size's price with the frame chosen, and follows the frame", () => {
		const form = classicForm();
		choose(form, 'attribute_frame', 'none');

		drawOptionPrices(form, POSTER);

		expect(texts(form, 'attribute_size')).toEqual([
			'30×40 · 499 kr',
			'50×70 · 1 599 kr',
		]);
		choose(form, 'attribute_frame', 'oak');
		expect(texts(form, 'attribute_size')).toEqual(['30×40 · 698 kr', '50×70']);
	});

	it('shows a size no price while the open frame leaves it more than one', () => {
		const form = classicForm();

		drawOptionPrices(form, POSTER);

		expect(texts(form, 'attribute_size')).toEqual([
			'30×40',
			'50×70 · 1 599 kr',
		]);
	});

	it('writes the add-on amounts in their options too', () => {
		const form = classicForm();
		choose(form, 'attribute_size', 's');

		drawOptionPrices(form, POSTER);

		expect(texts(form, 'attribute_frame')).toEqual([
			'No frame',
			'Oak · +199 kr',
		]);
	});

	it('writes the prices again once WooCommerce rebuilds the options from the printed ones', async () => {
		const form = classicForm();
		choose(form, 'attribute_frame', 'none');
		drawOptionPrices(form, POSTER);
		const select = form.querySelector(
			'select[name="attribute_size"]',
		) as HTMLSelectElement;
		const printed = select.innerHTML;
		choose(form, 'attribute_frame', 'oak');

		// The classic form's script sets the select's options to a copy of
		// those it read first, without a change event.
		select.innerHTML = printed;
		await observed();

		expect(texts(form, 'attribute_size')).toEqual(['30×40 · 698 kr', '50×70']);
	});

	it('writes the names back when stopped', () => {
		const form = classicForm();
		choose(form, 'attribute_frame', 'none');
		const stop = drawOptionPrices(form, POSTER);

		stop();

		expect(texts(form, 'attribute_size')).toEqual(['30×40', '50×70']);
	});
	it("keeps the theme's own words in the printed name, such as a block template's", () => {
		const form = classicForm();
		choose(form, 'attribute_frame', 'none');
		const option = form.querySelector(
			'select[name="attribute_size"] option[value="s"]',
		) as HTMLOptionElement;
		// Printed as "30x40 · 499 kr", the text texturized to "30×40".
		option.setAttribute('data-pixonaut-name', '30x40');
		option.setAttribute('data-pixonaut-shown', '30x40 · 499 kr');
		option.textContent = '30\u00d740 · 499 kr';

		drawOptionPrices(form, POSTER);

		expect(texts(form, 'attribute_size')[0]).toBe('30\u00d740 · 499 kr');
	});
});

describe('drawOptionPrices on the block form', () => {
	it("writes each chip's price and follows the chips the shopper picks", async () => {
		const form = blockForm();
		drawOptionPrices(form, POSTER);
		expect(texts(form, 'attribute_size')).toEqual([
			'30×40',
			'50×70 · 1 599 kr',
		]);

		const frame = form.querySelector(
			'input[name="attribute_frame"]',
		) as HTMLInputElement;
		frame.value = 'none';
		form
			.querySelector('button[value="none"]')
			?.setAttribute('aria-checked', 'true');
		await observed();

		expect(texts(form, 'attribute_size')).toEqual([
			'30×40 · 499 kr',
			'50×70 · 1 599 kr',
		]);
	});

	it('follows a chip picked in an attribute that shows no price', async () => {
		const form = blockForm();
		drawOptionPrices(form, { ...POSTER, roles: { attribute_size: 'price' } });
		const frame = form.querySelector(
			'input[name="attribute_frame"]',
		) as HTMLInputElement;

		frame.value = 'oak';
		form
			.querySelector('button[value="oak"]')
			?.setAttribute('aria-checked', 'true');
		await observed();

		expect(texts(form, 'attribute_size')).toEqual(['30×40 · 698 kr', '50×70']);
	});

	it("takes words the block writes in a chip as the option's name", async () => {
		const form = blockForm();
		(
			form.querySelector('input[name="attribute_frame"]') as HTMLInputElement
		).value = 'none';
		drawOptionPrices(form, POSTER);
		const text = form.querySelector(
			'button[value="s"] .wc-block-product-filter-chips__text',
		) as HTMLElement;

		text.textContent = 'Small';
		await observed();

		expect(texts(form, 'attribute_size')[0]).toBe('Small · 499 kr');
	});
});
