// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ProductDesigner } from './designer';
import { type Drawn, variationDrawnFor } from './partial-choice';
import {
	fakeDesigner,
	fakeSdk,
	MOUNTS,
	pageDeps,
	productPage,
	settle,
} from './test-support';
import { VARIATION_SETTLE_MS, type Variations } from './variations';

const WITH_PICTURE = 'photo-print@1';
const WITHOUT_PICTURE = 'text-print@1';
const POSTER = 'blank_poster';
const FRAMED = 'blank_framed';

const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };

/** The front mounted as the poster with a picture, with the menu of every
 *  variation: the product's size, then the small one. */
const MOUNTED = {
	front: {
		...MOUNTS.front,
		templateId: WITH_PICTURE,
		blankId: POSTER,
		printSizes: [...MOUNTS.front.printSizes, SMALL],
	},
};

function sold(
	id: string,
	frame: string,
	picture: string,
	size = 0,
): [string, object] {
	return [
		id,
		{
			state: 'personalised',
			attributes: { attribute_frame: frame, attribute_picture: picture },
			approvalMode: 'add_to_cart',
			priceHtml: `<span>price ${id}</span>`,
			sides: {
				front: {
					templateId: picture === 'without' ? WITHOUT_PICTURE : WITH_PICTURE,
					blankId: frame === 'framed' ? FRAMED : POSTER,
					placementId: 'front',
					printSizes: [size],
				},
			},
		},
	];
}

/** A print sold framed or not, with a picture or without: the choice with
 *  a picture comes last, so the first variation that fits is never it. */
const VARIATIONS = Object.fromEntries([
	sold('51', 'poster', 'without'),
	sold('52', 'poster', 'with'),
	sold('53', 'framed', 'without'),
	sold('54', 'framed', 'with'),
]) as Variations;

/** What the page draws before a variation is chosen. */
const OPENING: Drawn = {
	sides: {
		front: {
			templateId: WITH_PICTURE,
			blankId: POSTER,
			placementId: 'front',
			size: 0,
		},
	},
};

const FIELDS = `
	<select name="attribute_frame">
		<option value="">Choose</option>
		<option value="poster">Poster</option>
		<option value="framed">Framed</option>
	</select>
	<select name="attribute_picture">
		<option value="">Choose</option>
		<option value="with">With a picture</option>
		<option value="without">Without a picture</option>
	</select>`;

function formHolding(choices: Record<string, string>): HTMLFormElement {
	document.body.innerHTML = `<form>${FIELDS}</form>`;
	const form = document.querySelector('form') as HTMLFormElement;
	for (const [name, value] of Object.entries(choices)) {
		(form.elements.namedItem(name) as HTMLSelectElement).value = value;
	}
	return form;
}

const all = () => true;

describe('variationDrawnFor', () => {
	it('takes the chosen value, and for the rest the variation that changes least of what is drawn', () => {
		const form = formHolding({ attribute_frame: 'framed' });

		expect(variationDrawnFor(VARIATIONS, form, OPENING, all)).toBe('54');
	});

	it('keeps the values of the variation drawn for the attributes not chosen', () => {
		const form = formHolding({ attribute_frame: 'poster' });
		// Drawn alike on the page, so only the attributes tell them apart.
		const variations = Object.fromEntries([
			sold('61', 'poster', 'matte'),
			sold('62', 'poster', 'glossy'),
			sold('63', 'framed', 'glossy'),
		]) as Variations;
		const drawn = { ...OPENING, variation: variations['63'] };

		expect(variationDrawnFor(variations, form, drawn, all)).toBe('62');
	});

	it('keeps the size shown when a variation with the chosen values sells it', () => {
		const form = formHolding({ attribute_frame: 'framed' });
		const variations = Object.fromEntries([
			sold('71', 'framed', 'with', 0),
			sold('72', 'framed', 'with', 1),
		]) as Variations;
		const small = {
			sides: { front: { ...OPENING.sides.front, size: 1 } },
		};

		expect(variationDrawnFor(variations, form, small, all)).toBe('72');
	});

	it('keeps the variant shown', () => {
		const form = formHolding({ attribute_frame: 'framed' });
		const [, oak] = sold('81', 'framed', 'with');
		const [, black] = sold('82', 'framed', 'with');
		const variations = {
			'81': { ...oak, variant: 'v_oak' },
			'82': { ...black, variant: 'v_black' },
		} as unknown as Variations;

		expect(
			variationDrawnFor(
				variations,
				form,
				{ ...OPENING, variant: 'v_black' },
				all,
			),
		).toBe('82');
	});

	it('lets a variation of any value take the one chosen', () => {
		const form = formHolding({ attribute_frame: 'framed' });
		const variations = Object.fromEntries([
			sold('91', 'poster', 'with'),
			sold('92', '', 'without'),
		]) as Variations;

		expect(variationDrawnFor(variations, form, OPENING, all)).toBe('92');
	});

	it('draws none while the form holds no value', () => {
		expect(
			variationDrawnFor(VARIATIONS, formHolding({}), OPENING, all),
		).toBeUndefined();
	});

	it('draws none once every attribute holds a value, which the form answers', () => {
		const form = formHolding({
			attribute_frame: 'framed',
			attribute_picture: 'with',
		});

		expect(variationDrawnFor(VARIATIONS, form, OPENING, all)).toBeUndefined();
	});

	it('draws none when no variation has the values chosen', () => {
		const form = formHolding({ attribute_frame: 'framed' });
		const variations = Object.fromEntries([
			sold('51', 'poster', 'with'),
		]) as Variations;

		expect(variationDrawnFor(variations, form, OPENING, all)).toBeUndefined();
	});

	it('passes over a variation bought without a design, and one that cannot be used', () => {
		const form = formHolding({ attribute_frame: 'framed' });
		const variations = {
			...VARIATIONS,
			'54': {
				state: 'plain',
				attributes: { attribute_frame: 'framed', attribute_picture: 'with' },
			},
		} as Variations;

		expect(variationDrawnFor(variations, form, OPENING, all)).toBe('53');
		expect(
			variationDrawnFor(VARIATIONS, form, OPENING, (id) => id !== '54'),
		).toBe('53');
	});
});

/** The product's page with both attribute fields, the form holding no
 *  variation. */
function start(sdk = fakeSdk()) {
	const page = productPage(MOUNTED, { variations: VARIATIONS });
	const table = page.form.querySelector('table.variations td') as HTMLElement;
	table.innerHTML = FIELDS;
	const price = document.createElement('p');
	price.dataset.pixonautPrice = '';
	price.innerHTML = '<span>from</span>';
	page.form.append(price);
	const designer = new ProductDesigner(page.container, {
		loadSdk: () => Promise.resolve(sdk.sdk),
		label: (key) => `label:${key}`,
		report: vi.fn(),
		...pageDeps(),
	});
	designer.start();
	return { ...page, ...sdk, sdkDesigner: sdk.designer, price };
}

/** Puts one attribute field on a value, as the customer does, and lets the
 *  designer follow. `found` is the variation WooCommerce then finds. */
async function pick(
	form: HTMLFormElement,
	name: string,
	value: string,
	found = '0',
): Promise<void> {
	const field = form.elements.namedItem(name) as HTMLSelectElement;
	field.value = value;
	(form.elements.namedItem('variation_id') as HTMLInputElement).value = found;
	field.dispatchEvent(new Event('change', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
	await settle();
}

function options(mount: ReturnType<typeof vi.fn>, call: number) {
	return mount.mock.calls[call]?.[0] as Record<string, unknown>;
}

describe('ProductDesigner while the form holds part of a choice', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
	});

	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('draws the value picked with the template it shows, before the other attribute is chosen', async () => {
		const sdk = fakeSdk();
		sdk.mount
			.mockResolvedValueOnce(sdk.designer)
			.mockResolvedValue(fakeDesigner());
		const { form, mount } = start(sdk);
		await settle();
		expect(mount).toHaveBeenCalledOnce();

		await pick(form, 'attribute_frame', 'framed');

		expect(mount).toHaveBeenCalledTimes(2);
		expect(options(mount, 1)).toMatchObject({
			templateId: WITH_PICTURE,
			blankId: FRAMED,
		});
	});

	it('leaves the form without a variation, and its price the product’s own', async () => {
		const { form, price } = start();
		await settle();

		await pick(form, 'attribute_frame', 'framed');

		const data = new FormData(form);
		expect(data.get('variation_id')).toBe('0');
		expect(data.get('attribute_picture')).toBe('');
		expect(price.innerHTML).toBe('<span>from</span>');
	});

	it('follows the next value from what the first one drew', async () => {
		const sdk = fakeSdk();
		const framed = fakeDesigner();
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(framed);
		const { form, mount } = start(sdk);
		await settle();
		await pick(form, 'attribute_frame', 'framed');

		await pick(form, 'attribute_picture', 'without', '53');

		expect(framed.setTemplate).toHaveBeenCalledExactlyOnceWith(WITHOUT_PICTURE);
		expect(mount).toHaveBeenCalledTimes(2);
	});

	it('does not switch again once the customer completes the choice it drew', async () => {
		const sdk = fakeSdk();
		const framed = fakeDesigner();
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(framed);
		const { form, mount, price } = start(sdk);
		await settle();
		await pick(form, 'attribute_frame', 'framed');

		await pick(form, 'attribute_picture', 'with', '54');

		expect(mount).toHaveBeenCalledTimes(2);
		expect(framed.setTemplate).not.toHaveBeenCalled();
		expect(price.innerHTML).toBe('<span>price 54</span>');
	});

	it('keeps what it draws once the form holds no value', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		await pick(form, 'attribute_picture', 'without');
		expect(sdkDesigner.setTemplate).toHaveBeenCalledExactlyOnceWith(
			WITHOUT_PICTURE,
		);

		await pick(form, 'attribute_picture', '');

		expect(sdkDesigner.setTemplate).toHaveBeenCalledOnce();
		expect(mount).toHaveBeenCalledOnce();
	});
});
