// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProductDesigner } from './designer';
import { PRICE_EVENT, type PriceDetail } from './events';
import { PriceDisplay } from './price';
import { fakeSdk, MOUNTS, pageDeps, productPage, settle } from './test-support';
import { VARIATION_SETTLE_MS } from './variations';

const RANGE =
	'<span class="amount">100 kr</span> – <span class="amount">300 kr</span>';
const SMALL_PRICE = '<span class="amount">100 kr</span>';
const LARGE_PRICE = '<span class="amount">300 kr</span>';

const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };

const MOUNTED = {
	front: { ...MOUNTS.front, printSizes: [...MOUNTS.front.printSizes, SMALL] },
};

const FRONT = {
	templateId: 'mini-tee@2',
	blankId: 'blank_tee',
	placementId: 'front',
};

/** A variation per print size, each with its own price, and one bought
 *  without a design. */
const VARIATIONS = {
	'11': {
		state: 'personalised',
		attributes: { attribute_size: 'S' },
		approvalMode: 'add_to_cart',
		priceHtml: SMALL_PRICE,
		sides: { front: { ...FRONT, printSizes: [1], selectedPrintSizeIndex: 1 } },
	},
	'12': {
		state: 'personalised',
		attributes: { attribute_size: 'L' },
		approvalMode: 'add_to_cart',
		priceHtml: LARGE_PRICE,
		sides: { front: { ...FRONT, printSizes: [0], selectedPrintSizeIndex: 0 } },
	},
	'15': {
		state: 'plain',
		attributes: { attribute_size: 'P' },
		priceHtml: SMALL_PRICE,
	},
};

/** Puts the classic template's summary price before the form, a related
 *  product's price after it, and, with `target`, the theme's price target
 *  inside the form before its button. */
function page(target: boolean) {
	const result = productPage(MOUNTED, { variations: VARIATIONS });
	result.form.insertAdjacentHTML(
		'beforebegin',
		`<div class="summary"><p class="price">${RANGE}</p></div>`,
	);
	result.form.insertAdjacentHTML(
		'afterend',
		`<ul class="products"><li><span class="price">${RANGE}</span></li></ul>`,
	);
	if (target) {
		result.button.insertAdjacentHTML(
			'beforebegin',
			`<div class="pixonaut-shop-price" data-pixonaut-price data-keep="yes">${RANGE}</div>`,
		);
	}
	return result;
}

function html(selector: string): string | undefined {
	return document.querySelector(selector)?.innerHTML;
}

function events(container: HTMLElement): PriceDetail[] {
	const heard: PriceDetail[] = [];
	container.addEventListener(PRICE_EVENT, (event) => {
		heard.push((event as CustomEvent<PriceDetail>).detail);
	});
	return heard;
}

async function choose(form: HTMLFormElement, id: string): Promise<void> {
	const field = form.querySelector<HTMLInputElement>('[name="variation_id"]');
	if (field === null) {
		throw new Error('The form has no variation field.');
	}
	field.value = id;
	form.dispatchEvent(new Event('change', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
	await settle();
}

const FRONT_CONTEXT = { productId: 42, side: 'front', printSizeIndex: 1 };

/** The attribute values the page was printed at `LARGE_PRICE` for. */
const OPENING_CHOICE = { attribute_size: 'L' };

describe('PriceDisplay', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('replaces only the inner HTML of every target inside the form', () => {
		const { form, container } = page(true);
		const target = document.querySelector('[data-pixonaut-price]');

		new PriceDisplay(container, form).show(11, SMALL_PRICE, FRONT_CONTEXT);

		expect(html('[data-pixonaut-price]')).toBe(SMALL_PRICE);
		expect(document.querySelector('[data-pixonaut-price]')).toBe(target);
		expect(target?.className).toBe('pixonaut-shop-price');
		expect(target?.getAttribute('data-keep')).toBe('yes');
		expect(html('.summary .price')).toBe(RANGE);
	});

	it('replaces the summary price without a target, not the price of a related product', () => {
		const { form, container } = page(false);

		new PriceDisplay(container, form).show(11, SMALL_PRICE, FRONT_CONTEXT);

		expect(html('.summary .price')).toBe(SMALL_PRICE);
		expect(html('.products .price')).toBe(RANGE);
	});

	it('replaces the price of the form’s own product only', () => {
		const { form, container } = productPage(MOUNTED, {
			variations: VARIATIONS,
		});
		// Another product shown before this one, and this one's own summary.
		const product = document.createElement('div');
		product.className = 'product';
		product.innerHTML = `<div class="summary"><p class="price" id="own">${RANGE}</p></div>`;
		form.before(product);
		product.append(form);
		product.insertAdjacentHTML(
			'beforebegin',
			`<div class="wp-block-woocommerce-single-product"><div class="wc-block-components-product-price" id="other">${RANGE}</div></div>`,
		);
		const price = new PriceDisplay(container, form);

		price.show(12, LARGE_PRICE, FRONT_CONTEXT);
		expect(html('#own')).toBe(LARGE_PRICE);
		expect(html('#other')).toBe(RANGE);

		price.show(null, undefined, FRONT_CONTEXT);
		expect(html('#own')).toBe(RANGE);
	});

	it('replaces the block template’s price without a target', () => {
		const { form, container } = productPage(MOUNTED, {
			variations: VARIATIONS,
		});
		form.insertAdjacentHTML(
			'beforebegin',
			`<div class="wp-block-woocommerce-product-price"><div class="wc-block-components-product-price">${RANGE}</div></div>`,
		);

		new PriceDisplay(container, form).show(12, LARGE_PRICE, FRONT_CONTEXT);

		expect(html('.wc-block-components-product-price')).toBe(LARGE_PRICE);
	});

	it('puts the original price back when none is given', () => {
		const { form, container } = page(true);
		const price = new PriceDisplay(container, form);

		price.show(11, SMALL_PRICE, FRONT_CONTEXT);
		price.show(12, LARGE_PRICE, FRONT_CONTEXT);
		price.show(null, undefined, { ...FRONT_CONTEXT, printSizeIndex: null });

		expect(html('[data-pixonaut-price]')).toBe(RANGE);
	});

	it('puts the original price back for a variation without a price, or priced as the original', () => {
		const { form, container } = page(true);
		const heard = events(container);
		const price = new PriceDisplay(container, form);

		price.show(11, SMALL_PRICE, FRONT_CONTEXT);
		price.show(13, undefined, FRONT_CONTEXT);
		price.show(12, LARGE_PRICE, FRONT_CONTEXT);
		price.show(14, RANGE, FRONT_CONTEXT);

		expect(html('[data-pixonaut-price]')).toBe(RANGE);
		expect(heard.map((detail) => detail.variationId)).toEqual([
			11,
			null,
			12,
			null,
		]);
		expect(heard[1]?.priceHtml).toBe(RANGE);
	});

	it('shows the page’s opening variation’s price until none is chosen, then the product’s own', () => {
		const { form, container } = page(true);
		container.dataset.pixonautProductPrice = RANGE;
		const target = document.querySelector<HTMLElement>('[data-pixonaut-price]');
		if (target !== null) {
			target.innerHTML = LARGE_PRICE;
		}
		const heard = events(container);
		const price = new PriceDisplay(container, form);

		price.show(12, LARGE_PRICE, FRONT_CONTEXT);
		expect(html('[data-pixonaut-price]')).toBe(LARGE_PRICE);
		expect(heard.map((detail) => detail.variationId)).toEqual([12]);

		price.show(null, undefined, FRONT_CONTEXT);
		expect(html('[data-pixonaut-price]')).toBe(RANGE);
		expect(heard[1]).toMatchObject({ variationId: null, priceHtml: RANGE });
	});

	it('keeps the printed opening price while the form holds the choice the server printed it for and has found no variation yet', () => {
		const { form, container } = page(true);
		container.dataset.pixonautProductPrice = RANGE;
		container.dataset.pixonautOpeningChoice = JSON.stringify(OPENING_CHOICE);
		const select = form.querySelector<HTMLSelectElement>(
			'select[name="attribute_size"]',
		) as HTMLSelectElement;
		select.value = 'L';
		const target = form.querySelector('[data-pixonaut-price]') as HTMLElement;
		target.innerHTML = LARGE_PRICE;
		const heard = events(container);
		const price = new PriceDisplay(container, form);

		price.show(null, undefined, FRONT_CONTEXT);
		expect(target.innerHTML).toBe(LARGE_PRICE);
		expect(heard).toEqual([]);

		select.value = '';
		price.show(null, undefined, FRONT_CONTEXT);
		expect(target.innerHTML).toBe(RANGE);
	});

	it('shows the product’s own price when the form already holds another complete choice as the script runs', () => {
		const { form, container } = page(true);
		container.dataset.pixonautProductPrice = RANGE;
		container.dataset.pixonautOpeningChoice = JSON.stringify(OPENING_CHOICE);
		const select = form.querySelector<HTMLSelectElement>(
			'select[name="attribute_size"]',
		) as HTMLSelectElement;
		select.value = 'S';
		const target = form.querySelector('[data-pixonaut-price]') as HTMLElement;
		target.innerHTML = LARGE_PRICE;

		new PriceDisplay(container, form).show(null, undefined, FRONT_CONTEXT);

		expect(target.innerHTML).toBe(RANGE);
	});

	it('holds nothing when the page’s choice is not JSON', () => {
		const { form, container } = page(true);
		container.dataset.pixonautProductPrice = RANGE;
		container.dataset.pixonautOpeningChoice = '{broken';
		const target = form.querySelector('[data-pixonaut-price]') as HTMLElement;
		target.innerHTML = LARGE_PRICE;

		new PriceDisplay(container, form).show(null, undefined, FRONT_CONTEXT);

		expect(target.innerHTML).toBe(RANGE);
	});

	it('shows the product’s own price when the form opens on none of the variations the page was printed for', () => {
		const { form, container } = page(true);
		container.dataset.pixonautProductPrice = RANGE;
		const target = document.querySelector<HTMLElement>('[data-pixonaut-price]');
		if (target !== null) {
			target.innerHTML = LARGE_PRICE;
		}

		new PriceDisplay(container, form).show(null, undefined, FRONT_CONTEXT);

		expect(html('[data-pixonaut-price]')).toBe(RANGE);
	});

	it('changes nothing and dispatches nothing while the original price is shown', () => {
		const { form, container } = page(true);
		const heard = events(container);
		const price = new PriceDisplay(container, form);

		price.show(13, '', FRONT_CONTEXT);
		price.show(null, undefined, FRONT_CONTEXT);

		expect(html('[data-pixonaut-price]')).toBe(RANGE);
		expect(heard).toEqual([]);
	});

	it('still dispatches the price with no target to write it in', () => {
		const { form, container } = productPage(MOUNTED, {
			variations: VARIATIONS,
		});
		const heard = events(container);

		new PriceDisplay(container, form).show(12, LARGE_PRICE, FRONT_CONTEXT);

		expect(heard).toEqual([
			{ ...FRONT_CONTEXT, variationId: 12, priceHtml: LARGE_PRICE },
		]);
	});

	it('dispatches pixonaut:price on the form container, bubbling', () => {
		const { form, container } = page(true);
		const heard: PriceDetail[] = [];
		document.addEventListener(PRICE_EVENT, (event) => {
			heard.push((event as CustomEvent<PriceDetail>).detail);
		});

		new PriceDisplay(container, form).show(11, SMALL_PRICE, FRONT_CONTEXT);

		expect(heard).toEqual([
			{ ...FRONT_CONTEXT, variationId: 11, priceHtml: SMALL_PRICE },
		]);
	});
});

describe('ProductDesigner’s price', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	function start(target = true) {
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
		const result = page(target);
		const sdk = fakeSdk();
		const heard = events(result.container);
		new ProductDesigner(result.container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		}).start();
		return { ...result, heard };
	}

	it('follows the chosen size’s variation, and goes back to the product’s price', async () => {
		const { form, heard } = start();
		await settle();

		await choose(form, '11');
		expect(html('[data-pixonaut-price]')).toBe(SMALL_PRICE);

		await choose(form, '12');
		expect(html('[data-pixonaut-price]')).toBe(LARGE_PRICE);

		await choose(form, '0');
		expect(html('[data-pixonaut-price]')).toBe(RANGE);

		expect(heard).toEqual([
			{
				productId: 42,
				side: 'front',
				printSizeIndex: 1,
				variationId: 11,
				priceHtml: SMALL_PRICE,
			},
			{
				productId: 42,
				side: 'front',
				printSizeIndex: 0,
				variationId: 12,
				priceHtml: LARGE_PRICE,
			},
			{
				productId: 42,
				side: 'front',
				printSizeIndex: null,
				variationId: null,
				priceHtml: RANGE,
			},
		]);
	});

	it('shows the price of a variation bought without a design', async () => {
		const { form, heard } = start(false);
		await settle();

		await choose(form, '15');

		expect(html('.summary .price')).toBe(SMALL_PRICE);
		expect(heard.at(-1)).toEqual({
			productId: 42,
			side: 'front',
			printSizeIndex: null,
			variationId: 15,
			priceHtml: SMALL_PRICE,
		});
	});
});
