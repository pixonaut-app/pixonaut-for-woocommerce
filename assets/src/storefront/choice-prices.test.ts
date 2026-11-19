// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import type { AttributeValues } from './applicable-attributes';
import {
	type Amount,
	amountText,
	type ChoicePriceProduct,
	choiceAmounts,
	formChoice,
	optionLabel,
	readChoicePrices,
} from './choice-prices';

type SharedCases = {
	products: Record<string, ChoicePriceProduct>;
	cases: Array<{
		name: string;
		product: string;
		chosen: AttributeValues;
		amounts: Record<string, Record<string, Amount>>;
	}>;
};

/** The cases the server's `ChoicePriceTableTest` answers too. */
const shared: SharedCases = JSON.parse(
	readFileSync('tests/fixtures/choice-prices.json', 'utf8'),
);

describe('choiceAmounts', () => {
	it.each(shared.cases)('$name', ({ product, chosen, amounts }) => {
		const of = shared.products[product];
		if (of === undefined) {
			throw new Error(`No product ${product}`);
		}
		expect(choiceAmounts(of, chosen)).toEqual(amounts);
	});
});

const SWEDISH = {
	format: {
		pattern: '%2$s %1$s',
		symbol: 'kr',
		decimalSeparator: ',',
		thousandSeparator: ' ',
	},
	words: { add_on: '+%s', from: 'från %s', price: '%s' },
	label: '%1$s · %2$s',
	decimals: 0,
};

describe('amountText', () => {
	it('writes an add-on as the shop writes prices, thousands grouped', () => {
		expect(amountText({ kind: 'add_on', amount: 1350 }, SWEDISH)).toBe(
			'+1 350 kr',
		);
	});

	it('writes a base price in its own words', () => {
		expect(amountText({ kind: 'from', amount: 469 }, SWEDISH)).toBe(
			'från 469 kr',
		);
	});

	it('keeps the shop decimals', () => {
		expect(
			amountText(
				{ kind: 'add_on', amount: 2.5 },
				{
					format: {
						pattern: '%1$s%2$s',
						symbol: '$',
						decimalSeparator: '.',
						thousandSeparator: ',',
					},
					words: { add_on: '+%s', from: 'from %s', price: '%s' },
					decimals: 2,
				},
			),
		).toBe('+$2.50');
	});
});

describe('amountText with zeros trimmed', () => {
	const trimmed = {
		...SWEDISH,
		decimals: 2,
		format: { ...SWEDISH.format, trimZeros: true },
	};

	it('writes a whole amount without decimals, as the shop writes prices', () => {
		expect(amountText({ kind: 'add_on', amount: 180 }, trimmed)).toBe(
			'+180\u00a0kr',
		);
	});

	it('keeps the decimals of an amount with cents', () => {
		expect(amountText({ kind: 'add_on', amount: 2.5 }, trimmed)).toBe(
			'+2,50\u00a0kr',
		);
	});
});

describe('optionLabel', () => {
	it('writes a name and a price holding `$` as they are', () => {
		expect(optionLabel('A $& B', "$' 5", SWEDISH)).toBe("A $& B · $' 5");
	});
});

describe('readChoicePrices', () => {
	it('reads what the form printed, and nothing from mangled JSON', () => {
		const container = document.createElement('div');
		expect(readChoicePrices(container)).toBeNull();
		container.dataset.pixonautChoicePrices = '{"fields":';
		expect(readChoicePrices(container)).toBeNull();
		container.dataset.pixonautChoicePrices = JSON.stringify({
			...shared.products.cents,
			...SWEDISH,
		});
		expect(readChoicePrices(container)?.fields).toEqual([
			'attribute_size',
			'attribute_gift-wrap',
		]);
	});
});

describe('formChoice', () => {
	it('reads a classic select and the block form posted input', () => {
		const form = document.createElement('form');
		form.innerHTML = `
			<select name="attribute_pa_size"><option value="">Choose</option><option value="m" selected>M</option></select>
			<input type="hidden" name="attribute_pa_frame" value="oak">`;
		expect(
			formChoice(form, [
				'attribute_pa_size',
				'attribute_pa_frame',
				'attribute_x',
			]),
		).toEqual({
			attribute_pa_size: 'm',
			attribute_pa_frame: 'oak',
			attribute_x: '',
		});
	});
});
