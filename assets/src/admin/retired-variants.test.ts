// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { initRetiredVariants } from './retired-variants';

/** A variant field over a blank selling A4, with A3 and A5 retired. */
function field(picked = ''): HTMLElement {
	const element = document.createElement('div');
	element.innerHTML = `
		<p class="form-field pixonaut-variant">
			<select class="pixonaut-variant-select">
				<option value="">Match by options</option>
				<option value="v_a3" data-retired>Size: A3 (retired)</option>
				<option value="v_a4">Size: A4</option>
				<option value="v_a5" data-retired>Size: A5 (retired)</option>
			</select>
			<label><input type="checkbox" class="pixonaut-variant-show-retired" /> Show retired variants</label>
		</p>`;
	const select = element.querySelector('select');
	if (select) {
		select.value = picked;
	}
	initRetiredVariants(element);
	return element;
}

function listed(element: HTMLElement): string[] {
	return [...element.querySelectorAll('option')].map((option) => option.value);
}

function toggle(element: HTMLElement, checked: boolean): void {
	const box = element.querySelector<HTMLInputElement>(
		'.pixonaut-variant-show-retired',
	);
	if (box) {
		box.checked = checked;
		box.dispatchEvent(new Event('change', { bubbles: true }));
	}
}

describe('initRetiredVariants', () => {
	it('lists only the variants on sale until the retired ones are asked for', () => {
		const element = field();

		expect(listed(element)).toEqual(['', 'v_a4']);
		toggle(element, true);
		expect(listed(element)).toEqual(['', 'v_a3', 'v_a4', 'v_a5']);
		toggle(element, false);
		expect(listed(element)).toEqual(['', 'v_a4']);
	});

	it('keeps a retired variant chosen listed and chosen', () => {
		const element = field('v_a5');

		expect(listed(element)).toEqual(['', 'v_a4', 'v_a5']);
		expect(element.querySelector('select')?.value).toBe('v_a5');
	});

	it('keeps a retired variant chosen while they were shown once hidden again', () => {
		const element = field();
		toggle(element, true);
		const select = element.querySelector('select');
		if (select) {
			select.value = 'v_a3';
		}

		toggle(element, false);

		expect(listed(element)).toEqual(['', 'v_a3', 'v_a4']);
		expect(select?.value).toBe('v_a3');
	});

	it('does not tell WooCommerce the variation changed', () => {
		const element = field();
		let changes = 0;
		element.addEventListener('change', () => {
			changes++;
		});

		toggle(element, true);

		expect(changes).toBe(0);
	});

	it('sets a field up once however often its panel is found', () => {
		const element = field();
		initRetiredVariants(element);

		toggle(element, true);

		expect(listed(element)).toEqual(['', 'v_a3', 'v_a4', 'v_a5']);
	});
});
