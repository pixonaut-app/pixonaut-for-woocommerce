// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initSplitListing, nextChoice } from './split-listing';

function row(choice: string): HTMLElement {
	const element = document.createElement('table');
	element.innerHTML = `<tbody><tr class="pixonaut-split-listing"><td>
		<input type="hidden" class="pixonaut-split-listing__choice" name="pixonaut_wc_split_listing[0]" value="${choice}" />
		<button type="button" class="pixonaut-split-listing__toggle" role="checkbox" aria-checked="mixed"
			data-state-auto="Auto: on, as the attribute is" data-state-on="On for this product" data-state-off="Off for this product">
			<span class="pixonaut-split-listing__box"></span> Show each value as its own product
		</button>
		<span class="pixonaut-split-listing__state">Auto: on, as the attribute is</span>
	</td></tr></tbody>`;
	document.body.append(element);
	return element;
}

function click(root: ParentNode): void {
	root.querySelector<HTMLElement>('.pixonaut-split-listing__box')?.click();
}

initSplitListing(document);

afterEach(() => {
	document.body.innerHTML = '';
});

describe('nextChoice', () => {
	it('cycles Auto, On, Off and back', () => {
		expect(nextChoice('auto')).toBe('on');
		expect(nextChoice('on')).toBe('off');
		expect(nextChoice('off')).toBe('auto');
		expect(nextChoice('unknown')).toBe('auto');
	});
});

describe('the control on an attribute row', () => {
	it('moves to the next choice on each click, reading Auto as mixed', () => {
		const table = row('auto');
		const field = table.querySelector<HTMLInputElement>('input');
		const toggle = table.querySelector<HTMLElement>('button');
		const state = table.querySelector('.pixonaut-split-listing__state');

		click(table);
		expect(field?.value).toBe('on');
		expect(toggle?.getAttribute('aria-checked')).toBe('true');
		expect(state?.textContent).toBe('On for this product');

		click(table);
		expect(field?.value).toBe('off');
		expect(toggle?.getAttribute('aria-checked')).toBe('false');
		expect(state?.textContent).toBe('Off for this product');

		click(table);
		expect(field?.value).toBe('auto');
		expect(toggle?.getAttribute('aria-checked')).toBe('mixed');
		expect(state?.textContent).toBe('Auto: on, as the attribute is');
	});

	it('works on a row drawn after the screen, and tells WooCommerce the rows changed', () => {
		const changed = vi.fn();
		document.addEventListener('change', changed);
		const table = row('off');

		click(table);

		expect(table.querySelector<HTMLInputElement>('input')?.value).toBe('auto');
		expect(changed).toHaveBeenCalledTimes(1);
		document.removeEventListener('change', changed);
	});
});
