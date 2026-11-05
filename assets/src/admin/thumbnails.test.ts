// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	chosenThumbnail,
	THUMBNAIL_CLASS,
	thumbnailOf,
	withThumbnail,
} from './thumbnails';

describe('withThumbnail', () => {
	it('shows a search result with its picture beside its name', () => {
		const row = withThumbnail({
			id: 'blank_tee',
			text: 'Classic T-shirt',
			thumbnail: 'http://127.0.0.1/i/photo.png',
		}) as HTMLElement;

		const img = row.querySelector('img');
		expect(img?.getAttribute('src')).toBe('http://127.0.0.1/i/photo.png');
		expect(img?.alt).toBe('');
		expect(row.textContent).toBe('Classic T-shirt');
	});

	it('shows a note under the name, such as the sizes a template cannot be printed at', () => {
		const row = withThumbnail({
			id: 'panel-tee@1',
			text: 'Panel tee',
			note: 'Cannot print at XL and 3XL',
		}) as HTMLElement;

		expect(row.querySelector('.pixonaut-option__name')?.textContent).toBe(
			'Panel teeCannot print at XL and 3XL',
		);
		expect(row.querySelector('.pixonaut-option__note')?.textContent).toBe(
			'Cannot print at XL and 3XL',
		);
	});

	it('shows no note for an option that has none', () => {
		const row = withThumbnail({
			id: 'tote@1',
			text: 'Tote',
			note: '',
		}) as HTMLElement;

		expect(row.querySelector('.pixonaut-option__note')).toBeNull();
		expect(row.textContent).toBe('Tote');
	});

	it('shows an empty tile for an option without a picture', () => {
		const row = withThumbnail({
			id: 'poster@1',
			text: 'Poster',
			thumbnail: '',
		}) as HTMLElement;

		expect(row.querySelector('img')).toBeNull();
		expect(row.querySelector(`.${THUMBNAIL_CLASS}--empty`)).not.toBeNull();
	});

	it("reads a select option's picture from its data attribute", () => {
		const element = new Option('Front', 'front');
		element.dataset.thumbnail = 'http://127.0.0.1/i/front.png';

		expect(thumbnailOf({ id: 'front', text: 'Front', element })).toBe(
			'http://127.0.0.1/i/front.png',
		);
	});

	it('leaves the placeholder and loading rows as text', () => {
		expect(withThumbnail({ id: '', text: 'Choose a placement' })).toBe(
			'Choose a placement',
		);
		expect(withThumbnail({ text: 'Searching…', loading: true })).toBe(
			'Searching…',
		);
	});
});

describe('chosenThumbnail', () => {
	it("shows the chosen option's picture before the field, and hides it without one", () => {
		const holder = document.createElement('p');
		const select = document.createElement('select');
		const option = new Option('Front', 'front', true, true);
		option.dataset.thumbnail = 'http://127.0.0.1/i/front.png';
		select.append(option);
		holder.append(select);

		const show = chosenThumbnail(select);
		const img = holder.querySelector('img');

		expect(img?.nextElementSibling).toBe(select);
		expect(img?.getAttribute('src')).toBe('http://127.0.0.1/i/front.png');
		show('');
		expect(img?.hidden).toBe(true);
	});
});
