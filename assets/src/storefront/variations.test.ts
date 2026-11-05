// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	readVariations,
	selectAttributes,
	VARIATION_SETTLE_MS,
	type Variations,
	variationsSoldAs,
	watchVariation,
} from './variations';

function form(html: string): HTMLFormElement {
	document.body.innerHTML = `<form>${html}<input type="hidden" name="variation_id" value=""></form>`;
	const element = document.querySelector('form');
	if (element === null) {
		throw new Error('No form.');
	}
	return element;
}

function variationField(element: HTMLFormElement): HTMLInputElement {
	const field = element.querySelector<HTMLInputElement>(
		'[name="variation_id"]',
	);
	if (field === null) {
		throw new Error('No variation field.');
	}
	return field;
}

describe('watchVariation', () => {
	afterEach(() => {
		vi.useRealTimers();
		delete (window as { jQuery?: unknown }).jQuery;
	});

	it('reports the block form’s variation once its choice settled', () => {
		vi.useFakeTimers();
		const element = form('<button type="button" value="S">S</button>');
		const chosen = vi.fn();
		watchVariation(element, chosen);

		variationField(element).value = '11';
		element.querySelector('button')?.click();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS - 1);
		expect(chosen).not.toHaveBeenCalled();
		element.dispatchEvent(new Event('change'));
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		expect(chosen).toHaveBeenCalledExactlyOnceWith('11');
	});

	it('follows the classic form’s jQuery events, and reports none for its 0', () => {
		vi.useFakeTimers();
		const handlers: Array<() => void> = [];
		const on = vi.fn((_events: string, handler: () => void) =>
			handlers.push(handler),
		);
		(window as { jQuery?: unknown }).jQuery = () => ({ on });
		const element = form('');
		const chosen = vi.fn();
		watchVariation(element, chosen);
		expect(on).toHaveBeenCalledWith(
			'woocommerce_variation_select_change found_variation reset_data hide_variation',
			expect.any(Function),
		);

		variationField(element).value = '12';
		for (const handler of handlers) handler();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		variationField(element).value = '0';
		for (const handler of handlers) handler();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		expect(chosen.mock.calls).toEqual([['12'], ['']]);
	});

	it('reports a variation the page preselected', () => {
		vi.useFakeTimers();
		const element = form('');
		variationField(element).value = '13';
		const chosen = vi.fn();

		watchVariation(element, chosen);
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		expect(chosen).toHaveBeenCalledExactlyOnceWith('13');
	});
});

describe('readVariations', () => {
	it('reads the server’s variations, and none from a bad attribute', () => {
		const container = document.createElement('div');
		expect(readVariations(container)).toBeNull();
		container.dataset.pixonautVariations =
			'{"11":{"state":"plain","attributes":{}}}';
		expect(readVariations(container)).toEqual({
			'11': { state: 'plain', attributes: {} },
		});
		container.dataset.pixonautVariations = '{';
		expect(readVariations(container)).toBeNull();
	});
});

/** A colour and a garment-size attribute, and a print finish. */
const ATTRIBUTES = `
	<select name="attribute_colour"><option value="">Choose</option><option value="White">White</option><option value="Black">Black</option></select>
	<select name="attribute_size"><option value="">Choose</option><option value="S">S</option><option value="M">M</option></select>
	<input type="radio" name="attribute_finish" value="Matte">
	<input type="radio" name="attribute_finish" value="Gloss">`;

function sold(variant: string, attributes: Record<string, string>) {
	return {
		state: 'personalised',
		attributes,
		approvalMode: 'add_to_cart',
		variant,
		sides: {},
	};
}

const SOLD = {
	'11': sold('v_white_s', {
		attribute_colour: 'White',
		attribute_size: 'S',
		attribute_finish: 'Matte',
	}),
	'12': sold('v_white_s', {
		attribute_colour: 'White',
		attribute_size: 'S',
		attribute_finish: 'Gloss',
	}),
	'13': sold('v_black_m', {
		attribute_colour: 'Black',
		attribute_size: 'M',
		attribute_finish: '',
	}),
	'14': {
		state: 'plain',
		attributes: {
			attribute_colour: 'Black',
			attribute_size: 'S',
			attribute_finish: '',
		},
	},
} as Variations;

describe('variationsSoldAs', () => {
	it('lists the variations sold as the variant, those keeping most of the form’s choices first', () => {
		const element = form(ATTRIBUTES);
		const any = () => true;
		expect(variationsSoldAs(SOLD, 'v_white_s', element, any)).toEqual([
			'11',
			'12',
		]);

		element.querySelector<HTMLInputElement>('[value="Gloss"]')?.click();
		expect(variationsSoldAs(SOLD, 'v_white_s', element, any)).toEqual([
			'12',
			'11',
		]);
		expect(variationsSoldAs(SOLD, 'v_black_m', element, any)).toEqual(['13']);
	});

	it('lists none for a variant no usable personalised variation is sold as', () => {
		const element = form(ATTRIBUTES);

		expect(variationsSoldAs(SOLD, 'v_black_s', element, () => true)).toEqual(
			[],
		);
		expect(
			variationsSoldAs(SOLD, 'v_black_m', element, (id) => id !== '13'),
		).toEqual([]);
	});
});

/** The combinations the classic form below sells. */
const COMBINATIONS = [
	{ attribute_colour: 'White', attribute_size: 'S' },
	{ attribute_colour: 'Black', attribute_size: 'M' },
];

/**
 * Narrows each attribute select to the options the other selects' choices
 * allow after every change, as WooCommerce's classic variation form does
 * (`onUpdateAttributes`), clearing a choice no longer offered.
 */
function narrowAsWooCommerce(element: HTMLFormElement): void {
	const selects = Array.from(element.querySelectorAll('select'));
	const all = new Map(
		selects.map((select) => [
			select.name,
			Array.from(select.options).map((option) => option.value),
		]),
	);
	element.addEventListener('change', () => {
		for (const select of selects) {
			const others = selects.filter((other) => other !== select);
			const offered = (all.get(select.name) ?? []).filter(
				(value) =>
					value === '' ||
					COMBINATIONS.some(
						(combination) =>
							combination[select.name as keyof typeof combination] === value &&
							others.every(
								(other) =>
									other.value === '' ||
									combination[other.name as keyof typeof combination] ===
										other.value,
							),
					),
			);
			const chosen = select.value;
			select.replaceChildren(
				...offered.map((value) => new Option(value, value)),
			);
			select.value = offered.includes(chosen) ? chosen : '';
		}
	});
}

describe('selectAttributes', () => {
	it('puts each field on its value, keeps an "any" one, and announces the change', () => {
		const element = form(ATTRIBUTES);
		const gloss = element.querySelector<HTMLInputElement>('[value="Gloss"]');
		gloss?.click();
		const changed = vi.fn();
		element.addEventListener('change', (event) =>
			changed((event.target as HTMLInputElement).name),
		);

		expect(
			selectAttributes(element, {
				attribute_colour: 'Black',
				attribute_size: 'M',
				attribute_finish: '',
			}),
		).toBe(true);

		expect(new FormData(element).get('attribute_colour')).toBe('Black');
		expect(new FormData(element).get('attribute_size')).toBe('M');
		expect(gloss?.checked).toBe(true);
		expect(changed.mock.calls).toEqual([
			['attribute_colour'],
			['attribute_size'],
		]);
	});

	it('changes nothing when the form has no field for an attribute', () => {
		const element = form(ATTRIBUTES);

		expect(
			selectAttributes(element, {
				attribute_colour: 'Black',
				attribute_fit: 'Slim',
			}),
		).toBe(false);

		expect(new FormData(element).get('attribute_colour')).toBe('');
	});

	it('is false when a field cannot hold its value', () => {
		const element = form(ATTRIBUTES);

		expect(
			selectAttributes(element, {
				attribute_colour: 'Black',
				attribute_size: 'XL',
			}),
		).toBe(false);
	});

	it('moves the classic form between combinations its narrowed options rule out', () => {
		const element = form(ATTRIBUTES);
		narrowAsWooCommerce(element);
		selectAttributes(element, COMBINATIONS[0] ?? {});
		const colour = element.querySelector('select');
		expect(
			Array.from(colour?.options ?? []).map((option) => option.value),
		).toEqual(['', 'White']);

		expect(selectAttributes(element, COMBINATIONS[1] ?? {})).toBe(true);

		expect(new FormData(element).get('attribute_colour')).toBe('Black');
		expect(new FormData(element).get('attribute_size')).toBe('M');
	});
});
