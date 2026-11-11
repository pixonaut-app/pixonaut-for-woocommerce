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

/** A row's control followed by its cards, the attribute on by default or not. */
function rowWithCards(choice: string, attributeDefault: boolean): HTMLElement {
	const table = row(choice);
	table.querySelector('tbody')?.insertAdjacentHTML(
		'beforeend',
		`<tr class="pixonaut-split-cards" data-attribute-default="${attributeDefault ? '1' : '0'}"><td colspan="2">
			<div class="pixonaut-split-card">
				<input type="text" class="pixonaut-split-card__title" name="pixonaut_wc_split_card[7][title]" />
				<input type="hidden" class="pixonaut-split-card__image-id" name="pixonaut_wc_split_card[7][image]" value="" />
				<img class="pixonaut-split-card__preview" src="" alt="" hidden />
				<button type="button" class="button pixonaut-split-card__choose" data-frame-title="Image for “Med bild”" data-frame-button="Use this image">Choose</button>
				<button type="button" class="button-link pixonaut-split-card__remove" hidden>Remove</button>
			</div>
		</td></tr>`,
	);
	return table;
}

function cardsOf(table: HTMLElement): HTMLElement {
	const cards = table.querySelector<HTMLElement>('.pixonaut-split-cards');
	if (!cards) {
		throw new Error('no cards');
	}
	return cards;
}

describe('each value’s card', () => {
	it('shows while the choice lists the values as their own products', () => {
		const table = rowWithCards('auto', true);
		const cards = cardsOf(table);

		click(table);
		expect(cards.hidden).toBe(false);
		click(table);
		expect(cards.hidden).toBe(true);
		click(table);
		expect(cards.hidden).toBe(false);
	});

	it('hides on Auto when the attribute is off', () => {
		const table = rowWithCards('off', false);

		click(table);

		expect(cardsOf(table).hidden).toBe(true);
	});

	it('takes the image chosen in the media library, and removes it', () => {
		const changed = vi.fn();
		document.addEventListener('change', changed);
		let select = () => {};
		const open = vi.fn();
		const media = vi.fn(() => ({
			on: (_event: 'select', handler: () => void) => {
				select = handler;
			},
			open,
			state: () => ({
				get: () => ({
					first: () => ({
						toJSON: () => ({
							id: 42,
							url: 'full.png',
							sizes: { thumbnail: { url: 'thumb.png' } },
						}),
					}),
				}),
			}),
		}));
		window.wp = { media };
		const table = rowWithCards('on', false);
		const field = table.querySelector<HTMLInputElement>(
			'.pixonaut-split-card__image-id',
		);
		const preview = table.querySelector<HTMLImageElement>(
			'.pixonaut-split-card__preview',
		);
		const remove = table.querySelector<HTMLElement>(
			'.pixonaut-split-card__remove',
		);

		table.querySelector<HTMLElement>('.pixonaut-split-card__choose')?.click();
		expect(media).toHaveBeenCalledWith(
			expect.objectContaining({
				title: 'Image for “Med bild”',
				button: { text: 'Use this image' },
				multiple: false,
			}),
		);
		expect(open).toHaveBeenCalled();
		select();
		expect(field?.value).toBe('42');
		expect(preview?.getAttribute('src')).toBe('thumb.png');
		expect(preview?.hidden).toBe(false);
		expect(remove?.hidden).toBe(false);

		remove?.click();
		expect(field?.value).toBe('');
		expect(preview?.hidden).toBe(true);
		expect(remove?.hidden).toBe(true);
		expect(changed).toHaveBeenCalledTimes(2);
		document.removeEventListener('change', changed);
		delete window.wp;
	});
});
