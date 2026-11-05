import { describe, expect, it } from 'vitest';
import {
	addGroup,
	arrange,
	moveGroup,
	moveItem,
	placeAttribute,
	removeGroup,
	stored,
	type TemplateGroup,
	unplaceAttribute,
} from './form-arrangement';

const TEMPLATE: TemplateGroup[] = [
	{
		id: 'photo',
		label: 'Photo',
		fields: [
			{
				field: 'motif:src',
				label: 'Motif',
				help: '',
				type: 'image',
				options: [],
			},
		],
	},
	{
		id: 'text',
		label: 'Text',
		fields: [
			{
				field: 'slogan:content',
				label: 'Slogan',
				help: '',
				type: 'text',
				options: [],
			},
			{
				field: 'slogan:fontFamily',
				label: 'Font',
				help: '',
				type: 'select',
				options: ['Anton', 'Poppins'],
			},
		],
	},
];

const ATTRIBUTES = [
	{ slug: 'colour', label: 'Colour' },
	{ slug: 'size', label: 'Size' },
];

describe('arrange', () => {
	it('shows the template’s groups with every option in the tray when nothing is saved', () => {
		const arrangement = arrange(TEMPLATE, ATTRIBUTES, null);

		expect(arrangement.groups.map((group) => group.id)).toEqual([
			'photo',
			'text',
		]);
		expect(arrangement.groups[1]?.items).toEqual([
			{ field: 'slogan:content' },
			{ field: 'slogan:fontFamily' },
		]);
		expect(arrangement.tray).toEqual(['colour', 'size']);
		expect(arrangement.gone).toEqual([]);
		// Nothing changed, nothing named: the template's own form.
		expect(stored(arrangement)).toEqual([
			{ id: 'photo', items: [{ field: 'motif:src' }] },
			{
				id: 'text',
				items: [{ field: 'slogan:content' }, { field: 'slogan:fontFamily' }],
			},
		]);
	});

	it('lays a saved arrangement over the template as it is now', () => {
		const arrangement = arrange(TEMPLATE, ATTRIBUTES, [
			{
				id: 'text',
				title: 'Your words',
				items: [
					{ attribute: 'size' },
					{ field: 'slogan:fontFamily', options: { Anton: 'Bold' } },
					{ field: 'back:content', label: 'Back text' },
					{ attribute: 'material' },
				],
			},
			{ id: 'gone', title: 'Old group', items: [] },
			{ id: 'wc-gift', title: 'Gift', items: [] },
		]);

		expect(arrangement.groups.map((group) => group.id)).toEqual([
			'text',
			'wc-gift',
			'photo',
		]);
		expect(arrangement.groups[0]?.items).toEqual([
			{ attribute: 'size' },
			{ field: 'slogan:fontFamily', options: { Anton: 'Bold' } },
			{ field: 'slogan:content', isNew: true },
		]);
		expect(arrangement.gone).toEqual(['Back text', 'Old group']);
		expect(arrangement.tray).toEqual(['colour']);
	});
});

describe('editing', () => {
	it('places, moves and takes back options, and stores only the words set', () => {
		const arrangement = arrange(TEMPLATE, ATTRIBUTES, null);

		placeAttribute(arrangement, 'size', { group: 1, index: 0 });
		moveItem(arrangement, { group: 1, index: 2 }, { group: 0, index: 0 });
		moveGroup(arrangement, 1, 0);
		const text = arrangement.groups[0];
		if (text === undefined) {
			throw new Error('no group');
		}
		text.title = ' Your words ';
		text.items[1] = {
			field: 'slogan:content',
			label: 'Name',
			help: '  ',
			options: { x: '' },
		};

		expect(stored(arrangement)).toEqual([
			{
				id: 'text',
				title: 'Your words',
				items: [
					{ attribute: 'size' },
					{ field: 'slogan:content', label: 'Name' },
				],
			},
			{
				id: 'photo',
				items: [{ field: 'slogan:fontFamily' }, { field: 'motif:src' }],
			},
		]);

		unplaceAttribute(arrangement, { group: 0, index: 0 }, ATTRIBUTES);
		expect(arrangement.tray).toEqual(['colour', 'size']);
	});

	it('adds a group of the product’s own and removes it again', () => {
		const arrangement = arrange(TEMPLATE, ATTRIBUTES, null);
		const own = addGroup(arrangement, 'Gift');
		placeAttribute(arrangement, 'colour', { group: 2, index: 0 });
		moveItem(arrangement, { group: 0, index: 0 }, { group: 2, index: 1 });
		// One without a name is not stored: the designer could not show it.
		addGroup(arrangement, '');

		expect(own.id).toBe('wc-group-3');
		expect(stored(arrangement).map((group) => group.id)).toEqual([
			'photo',
			'text',
			'wc-group-3',
		]);

		removeGroup(arrangement, 2, ATTRIBUTES);
		expect(arrangement.tray).toEqual(['colour', 'size']);
		expect(arrangement.groups[0]?.items).toEqual([{ field: 'motif:src' }]);
	});
});
