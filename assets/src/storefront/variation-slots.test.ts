// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { followVariationSlots, showVariation } from './variation-slots';
import { VARIATION_SETTLE_MS } from './variations';

const SLOT = `<div data-pixonaut-variation-slot style="display:grid">
	<p data-pixonaut-variations="11 12" style="grid-area:1/1;visibility:hidden">Short</p>
	<p data-pixonaut-variations="13" style="grid-area:1/1;visibility:hidden">A longer text</p>
</div>`;

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
