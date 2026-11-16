// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initSplitListing, listsCards } from './split-listing';

function row(choice: string): HTMLElement {
	const element = document.createElement('table');
	element.innerHTML = `<tbody><tr class="pixonaut-split-listing"><td>
		<label for="pixonaut-split-listing-0">Cards in the shop</label>
		<select class="pixonaut-split-listing__choice" name="pixonaut_wc_split_listing[0]" id="pixonaut-split-listing-0">
			<option value="auto">Auto: A card per value, as the attribute</option>
			<option value="on">A card per value</option>
			<option value="filtered">A card per value while filtered by it</option>
			<option value="off">One card for all values</option>
		</select>
	</td></tr></tbody>`;
	document.body.append(element);
	const select = element.querySelector('select');
	if (select) {
		select.value = choice;
	}
	return element;
}

/** Chooses in the row's menu, as a store manager does. */
function choose(root: ParentNode, choice: string): void {
	const select = root.querySelector<HTMLSelectElement>('select');
	if (!select) {
		throw new Error('no menu');
	}
	select.value = choice;
	select.dispatchEvent(new Event('change', { bubbles: true }));
}

initSplitListing(document);

afterEach(() => {
	document.body.innerHTML = '';
});

describe('listsCards', () => {
	it('lists cards always or while filtered, and on Auto as the attribute does', () => {
		expect(listsCards('on', 'off')).toBe(true);
		expect(listsCards('filtered', 'off')).toBe(true);
		expect(listsCards('off', 'on')).toBe(false);
		expect(listsCards('auto', 'filtered')).toBe(true);
		expect(listsCards('auto', 'off')).toBe(false);
	});
});

/** A row's control followed by its cards, with the attribute's own setting. */
function rowWithCards(choice: string, attributeMode: string): HTMLElement {
	const table = row(choice);
	table.querySelector('tbody')?.insertAdjacentHTML(
		'beforeend',
		`<tr class="pixonaut-split-cards" data-attribute-mode="${attributeMode}"><td colspan="2">
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
		const table = rowWithCards('auto', 'filtered');
		const cards = cardsOf(table);

		choose(table, 'off');
		expect(cards.hidden).toBe(true);
		choose(table, 'filtered');
		expect(cards.hidden).toBe(false);
		choose(table, 'off');
		choose(table, 'on');
		expect(cards.hidden).toBe(false);
		choose(table, 'auto');
		expect(cards.hidden).toBe(false);
	});

	it('hides on Auto when the attribute is off', () => {
		const table = rowWithCards('on', 'off');

		choose(table, 'auto');

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
		const table = rowWithCards('on', 'off');
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
