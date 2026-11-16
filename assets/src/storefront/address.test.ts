// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { followAddress } from './address';
import { NOT_APPLICABLE_ATTRIBUTE } from './applicable-form';
import { VARIATION_SETTLE_MS } from './variations';

function form(): HTMLFormElement {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<select name="attribute_pa_framing">
				<option value="">Choose an option</option>
				<option value="frame" selected>Frame</option>
				<option value="hanger">Hanger</option>
			</select>
			<select name="attribute_size">
				<option value="">Choose an option</option>
				<option value="70x100">70x100</option>
			</select>
			<input type="hidden" name="variation_id" value="0">
			<input type="text" name="note" value="hello">
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

function choose(name: string, value: string): void {
	const field = document.querySelector(
		`select[name="${name}"]`,
	) as HTMLSelectElement;
	field.value = value;
	field.dispatchEvent(new Event('change', { bubbles: true }));
	vi.advanceTimersByTime(VARIATION_SETTLE_MS);
}

describe('followAddress', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		history.replaceState(null, '', '/product/poster/?utm=mail#reviews');
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('leaves the address the page was opened at until a choice changes', () => {
		followAddress(form());
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		expect(location.pathname + location.search + location.hash).toBe(
			'/product/poster/?utm=mail#reviews',
		);
	});

	it('names every choice in the address, keeping its other parts and adding no history entry', () => {
		followAddress(form());
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		const entries = history.length;

		choose('attribute_size', '70x100');

		expect(location.pathname).toBe('/product/poster/');
		expect(location.hash).toBe('#reviews');
		expect(Object.fromEntries(new URLSearchParams(location.search))).toEqual({
			utm: 'mail',
			attribute_pa_framing: 'frame',
			attribute_size: '70x100',
		});
		expect(history.length).toBe(entries);
	});

	it('leaves out an attribute that does not apply to the choice', () => {
		const element = form();
		followAddress(element);
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		const size = element.querySelector(
			'[name="attribute_size"]',
		) as HTMLSelectElement;
		const row = document.createElement('div');
		row.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
		size.replaceWith(row);
		row.append(size);
		size.value = '70x100';

		choose('attribute_pa_framing', 'hanger');

		expect(location.search).toBe('?utm=mail&attribute_pa_framing=hanger');
	});

	it('drops a choice taken back', () => {
		history.replaceState(
			null,
			'',
			'/product/poster/?attribute_pa_framing=frame&attribute_size=70x100',
		);
		const element = form();
		(
			element.querySelector('[name="attribute_size"]') as HTMLSelectElement
		).value = '70x100';
		followAddress(element);
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		choose('attribute_size', '');
		choose('attribute_pa_framing', 'hanger');

		expect(location.search).toBe('?attribute_pa_framing=hanger');
	});
});
