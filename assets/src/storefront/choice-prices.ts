/**
 * The price beside each swatch of an attribute the merchant has it shown for
 * (`attribute-swatches.ts`): what an add-on adds to the base product
 * ("+180 kr"), a base choice's lowest price ("from 469 kr"), or a choice's
 * own price with the shopper's other choices ("599 kr"), worked out
 * from the variations' prices for the shopper's choices. The server prints
 * what the rule reads on the form in `data-pixonaut-choice-prices`
 * (`ProductChoicePrices`), and the swatches the page opens with with their
 * prices. `Storefront\ChoicePriceTable` states the rule;
 * `tests/fixtures/choice-prices.json` holds the cases both must answer alike.
 */

import {
	type AttributeValues,
	dependentAttributes,
	matches,
} from './applicable-attributes';

export const ADD_ON = 'add_on';
export const FROM = 'from';
export const PRICE = 'price';

type Role = typeof ADD_ON | typeof FROM | typeof PRICE;

export type PricedVariation = { values: AttributeValues; price: number };

/** A product's variations and prices, and which attributes show a price. */
export type ChoicePriceProduct = {
	/** The `attribute_*` fields, in the product's order of attributes. */
	fields: string[];
	/** Each field's role; a field left out shows no price. */
	roles: Record<string, Role>;
	/** The decimals prices are shown with. */
	decimals: number;
	variations: PricedVariation[];
	/** The values the product offers for each attribute, by field, in its
	 *  order; a variation leaving an attribute unset holds each of them. */
	options?: Record<string, string[]>;
};

/** How the shop writes a price, as WooCommerce's settings say. */
export type PriceFormat = {
	/** `%1$s` the currency symbol, `%2$s` the number. */
	pattern: string;
	symbol: string;
	decimalSeparator: string;
	thousandSeparator: string;
	/** Whether a whole price is written without its decimals, as
	 *  WooCommerce's `wc_trim_zeros()` writes it for the shop. */
	trimZeros?: boolean;
};

/** What the form prints: the product, the price format and the words an
 *  amount is shown in, `%s` standing for the price. */
export type ChoicePrices = ChoicePriceProduct & {
	format: PriceFormat;
	words: Record<Role, string>;
	/** An option's words with its price in the theme's own field: `%1$s`
	 *  the option's name, `%2$s` the amount's text. */
	label: string;
};

export type Amount = { kind: Role; amount: number };

/** The amounts each field's values show, by field and value; a value that
 *  shows none is left out. */
export function choiceAmounts(
	{ fields, roles, decimals, variations, options = {} }: ChoicePriceProduct,
	chosen: AttributeValues,
): Record<string, Record<string, Amount>> {
	const dependent = dependentAttributes({
		fields,
		variations: variations.map((variation) => variation.values),
	});
	const base = fields.filter((field) => roles[field] !== ADD_ON);
	const amounts: Record<string, Record<string, Amount>> = {};
	fields.forEach((field, place) => {
		const role = roles[field];
		if (role === FROM) {
			const lowest = lowestPrices(
				variations,
				field,
				options[field] ?? [],
				chosenOf(
					chosen,
					base.filter((other) => other !== field),
				),
				false,
			);
			for (const [value, price] of lowest) {
				amounts[field] ??= {};
				amounts[field][value] = { kind: FROM, amount: round(price, decimals) };
			}
			return;
		}
		if (role === PRICE) {
			const held = heldPrices(
				variations,
				field,
				options[field] ?? [],
				chosenOf(
					chosen,
					fields.filter((other) => other !== field),
				),
				false,
			);
			for (const [value, prices] of held) {
				const lowest = round(Math.min(...prices), decimals);
				// Choices still open that change the price leave the value's
				// price unknown.
				if (round(Math.max(...prices), decimals) === lowest) {
					amounts[field] ??= {};
					amounts[field][value] = { kind: PRICE, amount: lowest };
				}
			}
			return;
		}
		if (role !== ADD_ON) {
			return;
		}
		let context = chosenOf(chosen, base);
		if (!settled(variations, base, context)) {
			return;
		}
		const setOnly = dependent.includes(field);
		if (setOnly) {
			const before = fields
				.slice(0, place)
				.filter((other) => roles[other] === ADD_ON);
			context = { ...context, ...chosenOf(chosen, before) };
		}
		const lowest = lowestPrices(
			variations,
			field,
			options[field] ?? [],
			context,
			setOnly,
		);
		if (lowest.size === 0) {
			return;
		}
		const cheapest = Math.min(...lowest.values());
		for (const [value, price] of lowest) {
			const amount = round(price - cheapest, decimals);
			if (amount > 0) {
				amounts[field] ??= {};
				amounts[field][value] = { kind: ADD_ON, amount };
			}
		}
	});
	return amounts;
}

/** The lowest price of each value of `field` among the variations holding
 *  `context`, in the order of `heldPrices()`. */
function lowestPrices(
	variations: PricedVariation[],
	field: string,
	offered: string[],
	context: AttributeValues,
	setOnly: boolean,
): Map<string, number> {
	return new Map(
		[...heldPrices(variations, field, offered, context, setOnly)].map(
			([value, prices]) => [value, Math.min(...prices)],
		),
	);
}

/** The prices of the variations holding `context` and each value of
 *  `field`: the values the product offers, in its order, then any other a
 *  variation holds, in the order the variations first hold them. A value no
 *  such variation holds is left out. */
function heldPrices(
	variations: PricedVariation[],
	field: string,
	offered: string[],
	context: AttributeValues,
	setOnly: boolean,
): Map<string, number[]> {
	const values = new Set<string>(offered);
	for (const variation of variations) {
		const value = variation.values[field] ?? '';
		if (value !== '') {
			values.add(value);
		}
	}
	const prices = new Map<string, number[]>();
	for (const variation of variations) {
		if (!matches(variation.values, context)) {
			continue;
		}
		const held = variation.values[field] ?? '';
		if (held === '' && setOnly) {
			continue;
		}
		for (const value of held === '' ? values : [held]) {
			prices.set(value, [...(prices.get(value) ?? []), variation.price]);
		}
	}
	return new Map(
		[...values].flatMap((value) => {
			const held = prices.get(value);
			return held === undefined ? [] : [[value, held] as const];
		}),
	);
}

/** Whether the variations holding `context` hold one value at most of each
 *  of `fields`: the base product is known. */
function settled(
	variations: PricedVariation[],
	fields: string[],
	context: AttributeValues,
): boolean {
	const held = new Map<string, Set<string>>();
	for (const variation of variations) {
		if (!matches(variation.values, context)) {
			continue;
		}
		for (const field of fields) {
			const value = variation.values[field] ?? '';
			if (value !== '') {
				held.set(field, (held.get(field) ?? new Set()).add(value));
			}
		}
	}
	return [...held.values()].every((values) => values.size <= 1);
}

function chosenOf(chosen: AttributeValues, fields: string[]): AttributeValues {
	return Object.fromEntries(
		fields.map((field) => [field, chosen[field] ?? '']),
	);
}

/** Rounds as PHP's `round()` does: halves away from zero. */
function round(value: number, decimals: number): number {
	const scale = 10 ** decimals;
	return (Math.sign(value) * Math.round(Math.abs(value) * scale)) / scale;
}

/** The text an amount shows: its words around the price as the shop writes
 *  it, as `ProductChoicePrices::text()` writes it. */
export function amountText(
	{ kind, amount }: Amount,
	{
		format,
		words,
		decimals,
	}: Pick<ChoicePrices, 'format' | 'words' | 'decimals'>,
): string {
	const [whole = '0', fraction] = amount.toFixed(decimals).split('.');
	const grouped = whole.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		format.thousandSeparator,
	);
	const number =
		fraction === undefined || (format.trimZeros && /^0+$/.test(fraction))
			? grouped
			: grouped + format.decimalSeparator + fraction;
	const price = format.pattern
		.replace('%1$s', format.symbol)
		.replace('%2$s', number);
	return words[kind].replace('%s', price);
}

/** An option's name followed by its amount's text, as
 *  `ProductChoicePrices::label()` writes it. */
export function optionLabel(
	name: string,
	price: string,
	{ label }: Pick<ChoicePrices, 'label'>,
): string {
	return label.replace('%1$s', name).replace('%2$s', price);
}

/** What the form printed; null for none, or for JSON the page mangled. */
export function readChoicePrices(container: HTMLElement): ChoicePrices | null {
	const raw = container.dataset.pixonautChoicePrices;
	if (raw === undefined || raw === '') {
		return null;
	}
	try {
		const parsed = JSON.parse(raw) as Partial<ChoicePrices> | null;
		return parsed !== null &&
			Array.isArray(parsed.fields) &&
			Array.isArray(parsed.variations) &&
			typeof parsed.roles === 'object' &&
			typeof parsed.format === 'object' &&
			typeof parsed.words === 'object' &&
			typeof parsed.label === 'string'
			? (parsed as ChoicePrices)
			: null;
	} catch {
		return null;
	}
}

/** The values of the product's attribute fields the form holds, by
 *  field: a classic select's, or the block form's posted input's. */
export function formChoice(
	form: HTMLElement,
	fields: string[],
): AttributeValues {
	const chosen: AttributeValues = {};
	for (const field of fields) {
		const control = Array.from(
			form.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
				'select[name^="attribute_"], input[name^="attribute_"]',
			),
		).find((element) => element.name === field);
		chosen[field] = control?.value ?? '';
	}
	return chosen;
}
