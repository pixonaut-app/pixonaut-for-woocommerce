import { describe, expect, it, vi } from 'vitest';
import { fieldValues } from './field-values';

type Field = {
	layerId: string;
	prop: string;
	type: string;
	label: string;
	constraints?: Record<string, unknown>;
};

/** A designer whose form has `fields` in one step, and `values` by
 *  `<layerId>.<prop>`. */
function designer(fields: Field[], values: Record<string, unknown>) {
	return {
		getFormSchema: vi.fn().mockReturnValue({
			steps: [
				{
					fields: fields.map((field) => ({
						constraints: {},
						default: null,
						...field,
					})),
				},
			],
		}),
		getValue: vi.fn(
			(layerId: string, prop: string) => values[`${layerId}.${prop}`],
		),
	} as unknown as Parameters<typeof fieldValues>[0];
}

describe('fieldValues', () => {
	it('lists each field the customer filled by its label, in the form’s order', () => {
		const values = fieldValues(
			designer(
				[
					{ layerId: 'name', prop: 'content', type: 'text', label: ' Name ' },
					{
						layerId: 'size',
						prop: 'variant',
						type: 'select',
						label: 'Size',
						constraints: {
							options: [{ value: 'l', label: 'Large' }, 'm'],
						},
					},
					{ layerId: 'age', prop: 'content', type: 'number', label: 'Age' },
					{ layerId: 'bg', prop: 'fill', type: 'color', label: 'Colour' },
					{ layerId: 'ink', prop: 'fill', type: 'color', label: 'Ink' },
					{ layerId: 'print', prop: 'fill', type: 'color', label: 'Print' },
					{ layerId: 'photo', prop: 'src', type: 'image', label: 'Photo' },
				],
				{
					'name.content': 'Anna\nAndersson',
					'size.variant': 'l',
					'age.content': 7,
					'bg.fill': { rgb: [255, 0, 10] },
					'ink.fill': { spot: { name: 'Pantone 186 C' } },
					'print.fill': { cmyk: [0, 100, 100, 0] },
					'photo.src': 'asset_1',
				},
			),
		);

		expect(values).toEqual([
			{ label: 'Name', type: 'text', value: 'Anna\nAndersson' },
			{ label: 'Size', type: 'select', value: 'Large' },
			{ label: 'Age', type: 'number', value: '7' },
			{ label: 'Colour', type: 'color', value: '#ff000a' },
			{ label: 'Ink', type: 'color', value: 'Pantone 186 C' },
			{ label: 'Print', type: 'color', value: 'C0 M100 Y100 K0' },
			{ label: 'Photo', type: 'image', value: '' },
		]);
	});

	it('leaves out empty fields, fields that only place the design, and repeats', () => {
		const values = fieldValues(
			designer(
				[
					{ layerId: 'name', prop: 'content', type: 'text', label: 'Name' },
					{ layerId: 'name', prop: 'content', type: 'text', label: 'Name' },
					{ layerId: 'note', prop: 'content', type: 'text', label: 'Note' },
					{ layerId: 'photo', prop: 'src', type: 'image', label: 'Photo' },
					{ layerId: 'photo', prop: 'crop', type: 'crop', label: 'Crop' },
					{ layerId: 'photo', prop: 'opacity', type: 'percent', label: '' },
				],
				{
					'name.content': 'Anna',
					'note.content': '  ',
					'photo.crop': { x: 0 },
					'photo.opacity': 0.5,
				},
			),
		);

		expect(values).toEqual([{ label: 'Name', type: 'text', value: 'Anna' }]);
	});

	describe('with an SDK that reads its fields as text', () => {
		/** `designer(fields, {})` that also answers `getFieldTexts()` with
		 *  `texts`. */
		function withTexts(
			fields: Field[],
			texts: { layerId: string; prop: string; label: string; value: string }[],
		) {
			return Object.assign(designer(fields, {}), {
				getFieldTexts: vi.fn().mockReturnValue(texts),
			});
		}

		it('lists each value in the words the form shows it in', () => {
			const values = fieldValues(
				withTexts(
					[
						{ layerId: 'name', prop: 'content', type: 'text', label: 'Name' },
						{ layerId: 'size', prop: 'fontSize', type: 'number', label: '' },
						{ layerId: 'ink', prop: 'fill', type: 'color', label: 'Colour' },
						{ layerId: 'photo', prop: 'src', type: 'image', label: 'Photo' },
					],
					[
						{ layerId: 'name', prop: 'content', label: 'Name', value: 'Anna' },
						{ layerId: 'size', prop: 'fontSize', label: 'Size', value: '2.3%' },
						{ layerId: 'ink', prop: 'fill', label: 'Colour', value: 'Red' },
						{ layerId: 'photo', prop: 'src', label: 'Photo', value: 'me.jpg' },
					],
				),
			);

			expect(values).toEqual([
				{ label: 'Name', type: 'text', value: 'Anna' },
				{ label: 'Size', type: 'number', value: '2.3%' },
				{ label: 'Colour', type: 'color', value: 'Red' },
				{ label: 'Photo', type: 'image', value: '' },
			]);
		});

		it('leaves out empty fields, fields that only place the design, and repeats', () => {
			const values = fieldValues(
				withTexts(
					[
						{ layerId: 'name', prop: 'content', type: 'text', label: 'Name' },
						{ layerId: 'note', prop: 'content', type: 'text', label: 'Note' },
						{ layerId: 'photo', prop: 'src', type: 'image', label: 'Photo' },
						{ layerId: 'photo', prop: 'opacity', type: 'percent', label: '' },
					],
					[
						{ layerId: 'name', prop: 'content', label: 'Name', value: 'Anna' },
						{ layerId: 'name', prop: 'content', label: 'Name', value: 'Anna' },
						{ layerId: 'note', prop: 'content', label: 'Note', value: ' ' },
						{ layerId: 'photo', prop: 'src', label: 'Photo', value: '' },
						{
							layerId: 'photo',
							prop: 'opacity',
							label: 'Opacity',
							value: '50%',
						},
						{ layerId: 'gone', prop: 'content', label: 'Gone', value: 'x' },
					],
				),
			);

			expect(values).toEqual([{ label: 'Name', type: 'text', value: 'Anna' }]);
		});
	});
});
