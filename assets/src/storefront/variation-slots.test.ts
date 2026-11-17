// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { followVariationSlots, formOf, showVariation } from './variation-slots';
import { VARIATION_SETTLE_MS } from './variations';

/** The variable product the form is of. */
const PRODUCT_ID = '10';

const SLOT = `<div data-pixonaut-variation-slot data-pixonaut-variation-product="${PRODUCT_ID}" style="display:grid">
	<p data-pixonaut-variations="11 12" style="grid-row:1;grid-column:1;visibility:hidden">Short</p>
	<p data-pixonaut-variations="13" style="grid-row:1;grid-column:1;visibility:hidden">A longer text</p>
</div>`;

/** A form of another product, ahead of the one the place follows. */
const OTHER_FORM = `<form><input type="hidden" name="add-to-cart" value="20"><input type="hidden" name="variation_id" value="21"></form>`;

function page(): { form: HTMLFormElement; field: HTMLInputElement } {
	document.body.innerHTML = `<form>${SLOT}<input type="hidden" name="variation_id" value=""></form>`;
	const form = document.querySelector('form');
	const field = document.querySelector<HTMLInputElement>(
		'[name="variation_id"]',
	);
	if (form === null || field === null) {
		throw new Error('No form.');
	}
	return { form, field };
}

function announced(): string {
	return document.querySelector('[aria-live="polite"]')?.textContent ?? 'none';
}

function shown(): string[] {
	return [...document.querySelectorAll<HTMLElement>('p')]
		.filter((item) => item.style.visibility !== 'hidden')
		.map((item) => item.textContent ?? '');
}

describe('showVariation', () => {
	it('shows the text the chosen variation shares with others, and hides the rest', () => {
		page();
		const slot = document.querySelector('[data-pixonaut-variation-slot]');
		if (slot === null) {
			throw new Error('No slot.');
		}

		showVariation(slot, '12');

		expect(shown()).toEqual(['Short']);
		expect(document.querySelector('.is-chosen')?.textContent).toBe('Short');
		expect(announced(), 'the chosen text alone is announced').toBe('Short');
	});

	it('shows nothing, keeping every text’s room, for a variation no text is of', () => {
		page();
		const slot = document.querySelector('[data-pixonaut-variation-slot]');
		if (slot === null) {
			throw new Error('No slot.');
		}
		showVariation(slot, '13');

		showVariation(slot, '');

		expect(shown()).toEqual([]);
		expect(document.querySelectorAll('p')).toHaveLength(2);
		expect(document.querySelector('.is-chosen')).toBeNull();
		expect(announced()).toBe('');
		expect(document.querySelectorAll('[aria-live]')).toHaveLength(1);
	});
});

describe('followVariationSlots', () => {
	afterEach(() => {
		vi.useRealTimers();
		delete (window as { jQuery?: unknown }).jQuery;
	});

	it('shows the variation the classic form finds at once, ahead of the pause after a choice', () => {
		vi.useFakeTimers();
		const handlers: Array<[string, (event: unknown, data?: unknown) => void]> =
			[];
		(window as { jQuery?: unknown }).jQuery = () => ({
			on: (events: string, handler: (event: unknown, data?: unknown) => void) =>
				handlers.push([events, handler]),
		});
		page();
		followVariationSlots(document);
		// The last to follow it: the one that answers at once.
		const found = handlers
			.filter(([events]) => events.split(' ').includes('found_variation'))
			.at(-1);

		// WooCommerce's own handler puts the id in the form after this one.
		found?.[1]({}, { variation_id: 13 });

		expect(shown()).toEqual(['A longer text']);

		found?.[1]({});
		expect(shown(), 'none once the form holds no variation').toEqual([]);
	});

	it("follows its product's form from the attributes table, outside any form", () => {
		vi.useFakeTimers();
		document.body.innerHTML = `${OTHER_FORM}<form><input type="hidden" name="add-to-cart" value="${PRODUCT_ID}"><input type="hidden" name="variation_id" value=""></form><table><tr><td>${SLOT}</td></tr></table>`;
		const forms = document.querySelectorAll('form');
		const form = forms[1] as HTMLFormElement;
		const field = form.querySelector<HTMLInputElement>('[name="variation_id"]');
		const slot = document.querySelector('[data-pixonaut-variation-slot]');
		expect(slot === null ? null : formOf(slot)).toBe(form);
		followVariationSlots(document);

		if (field !== null) {
			field.value = '13';
		}
		form.dispatchEvent(new Event('change'));
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		expect(shown()).toEqual(['A longer text']);
	});

	it('follows the variation the form holds', () => {
		vi.useFakeTimers();
		const { form, field } = page();
		followVariationSlots(document);

		field.value = '13';
		form.dispatchEvent(new Event('change'));
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		expect(shown()).toEqual(['A longer text']);

		field.value = '';
		form.dispatchEvent(new Event('change'));
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		expect(shown()).toEqual([]);
	});
});
