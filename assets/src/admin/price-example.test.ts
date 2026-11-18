// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	example,
	type Format,
	initPriceExample,
	money,
	parseAmount,
	ruleOf,
} from './price-example';

/** Swedish VAT, which the store's prices include. */
const VAT = 25;

const KRONOR: Format = {
	symbol: 'kr',
	position: 'right_space',
	decimals: 2,
	decimal: ',',
	thousand: ' ',
	number_decimal: ',',
};

const kr = (units: number): number => units * 1000;

describe('the pricing rule example', () => {
	it('reads amounts as the plugin does, with a point or a comma', () => {
		expect(parseAmount('49,5')).toBe(49500);
		expect(parseAmount(' 0.25 ')).toBe(250);
		expect(parseAmount('1.0005')).toBe(1001);
		expect(parseAmount('')).toBeNull();
		expect(parseAmount('-5')).toBeNull();
	});

	it('prices as before without a fixed amount', () => {
		const parts = { margin: '50', step: '10', ending: '9' };
		for (const fixed of ['', '0']) {
			// 167 ÷ 0.5 = 334, 417.50 with VAT: 419.
			expect(example(ruleOf({ ...parts, fixed }), kr(167), VAT)?.price).toBe(
				kr(419),
			);
		}
		// Halfway between 329 and 339 takes the higher.
		expect(example(ruleOf(parts), kr(167), 0)?.price).toBe(kr(339));
	});

	it('adds the fixed amount to the price excluding VAT', () => {
		const rule = ruleOf({
			margin: '15',
			fixed: '300',
			step: '10',
			ending: '9',
		});

		// 143 ÷ 0.85 + 300 = 468.235, 585.294 with VAT.
		expect(example(rule, kr(143), VAT)).toEqual({
			price: kr(589),
			contribution: kr(328.2),
			margin: expect.closeTo(69.65, 2),
		});
		// 518 ÷ 0.85 + 300 = 909.412, 1136.765 with VAT.
		expect(example(rule, kr(518), VAT)?.price).toBe(kr(1139));
	});

	it('keeps prices within the minimum and maximum, and sets none without a margin', () => {
		const capped = ruleOf({
			margin: '50',
			step: '10',
			ending: '9',
			max: '500',
		});
		expect(example(capped, kr(400), 0)?.price).toBe(kr(499));
		expect(example(ruleOf({ margin: '' }), kr(100), 0)).toBeNull();
	});

	it('shows money as the store does', () => {
		expect(money(kr(1139), KRONOR)).toBe('1 139,00 kr');
		expect(money(-kr(10), { ...KRONOR, position: 'left', decimals: 0 })).toBe(
			'-kr10',
		);
	});

	it('draws the example again as a part is typed', () => {
		document.body.innerHTML = `
			<input id="s[price_margin]" value="15" />
			<input id="s[price_fixed]" value="" />
			<table data-pixonaut-price-example
				data-parts='{"margin":"s[price_margin]","fixed":"s[price_fixed]"}'
				data-vat="25"
				data-format='${JSON.stringify({ ...KRONOR, decimals: 0 })}'>
				<tr data-cost="143"><td>143 kr</td><td data-shows="price"></td><td data-shows="contribution"></td><td data-shows="margin"></td></tr>
			</table>`;
		initPriceExample(document);
		const fixed = document.getElementById('s[price_fixed]') as HTMLInputElement;
		fixed.value = '300';
		fixed.dispatchEvent(new Event('input'));

		const cells = [...document.querySelectorAll('[data-shows]')].map(
			(cell) => cell.textContent,
		);
		// No step given: one unit, so 585.294 lands on 585.
		expect(cells).toEqual(['585 kr', '325 kr', '69,4 %']);
	});
});
