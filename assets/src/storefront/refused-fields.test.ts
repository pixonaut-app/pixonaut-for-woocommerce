import { describe, expect, it, vi } from 'vitest';
import { joinLabels, refusedFieldLabels } from './refused-fields';
import { requestError } from './test-support';

/** A designer whose form heads `fields`, each `[layerId, prop, label]`. */
function designer(fields: Array<[string, string, string]>) {
	return {
		getFieldTexts: vi.fn().mockReturnValue(
			fields.map(([layerId, prop, label]) => ({
				layerId,
				prop,
				label,
				value: '',
			})),
		),
	};
}

/** A refusal of the design's fields, as `approve()` rejects with one. */
function refused(...fields: Array<[string, string, string]>): Error {
	return Object.assign(requestError(422, 'constraint_violation'), {
		violations: fields.map(([layerId, prop, rule]) => ({
			layerId,
			prop,
			rule,
		})),
	});
}

const FORM = designer([
	['photo', 'src', 'Photo'],
	['name', 'content', 'Name'],
	['class', 'content', 'Class'],
]);

describe('refusedFieldLabels', () => {
	it('names each refused field by its label, in the refusal’s order', () => {
		expect(
			refusedFieldLabels(
				refused(['name', 'content', 'unchanged'], ['photo', 'src', 'required']),
				FORM,
			),
		).toEqual(['Name', 'Photo']);
	});

	it('names the fields an embroidery machine cannot stitch', () => {
		const notStitchable = Object.assign(
			requestError(422, 'design_not_stitchable'),
			{
				stitchFindings: [
					{
						layerId: 'class',
						prop: 'content',
						rule: 'stitch_text_too_small',
						given: 6,
						allowed: 8,
					},
				],
			},
		);
		expect(refusedFieldLabels(notStitchable, FORM)).toEqual(['Class']);
	});

	it('names a field refused twice once, whatever the rule', () => {
		expect(
			refusedFieldLabels(
				refused(
					['class', 'content', 'unchanged'],
					['class', 'content', 'max_length'],
				),
				FORM,
			),
		).toEqual(['Class']);
	});

	it('names two fields headed alike once', () => {
		const alike = designer([
			['first', 'content', 'Name'],
			['second', 'content', 'Name'],
		]);
		expect(
			refusedFieldLabels(
				refused(
					['first', 'content', 'unchanged'],
					['second', 'content', 'unchanged'],
				),
				alike,
			),
		).toEqual(['Name']);
	});

	it('leaves out a field the form does not head', () => {
		expect(
			refusedFieldLabels(
				refused(
					['', 'templateId', 'unknown_template'],
					['name', 'content', 'unchanged'],
				),
				FORM,
			),
		).toEqual(['Name']);
	});

	it('leaves out an entry that names no field', () => {
		const error = Object.assign(requestError(422, 'constraint_violation'), {
			violations: [
				null,
				{ layerId: 'name' },
				{ layerId: 'name', prop: 'content' },
			],
		});
		expect(refusedFieldLabels(error, FORM)).toEqual(['Name']);
	});

	it.each([
		['a refusal without violations', requestError(422, 'asset_map_too_large')],
		['an empty list', refused()],
		['a network failure', new TypeError('Failed to fetch')],
		['nothing', null],
	])('names nothing for %s', (_, error) => {
		expect(refusedFieldLabels(error, FORM)).toEqual([]);
	});

	it('names nothing through a designer that cannot name its fields', () => {
		expect(
			refusedFieldLabels(refused(['name', 'content', 'unchanged']), {}),
		).toEqual([]);
		expect(
			refusedFieldLabels(refused(['name', 'content', 'unchanged']), undefined),
		).toEqual([]);
	});
});

describe('joinLabels', () => {
	it('joins the labels in the locale’s words', () => {
		expect(joinLabels(['Photo', 'Name', 'Class'], 'en-US')).toBe(
			'Photo, Name, and Class',
		);
		expect(joinLabels(['Foto', 'Namn', 'Klass'], 'sv-SE')).toBe(
			'Foto, Namn och Klass',
		);
	});

	it('reads one label as itself', () => {
		expect(joinLabels(['Name'], 'en-US')).toBe('Name');
	});

	it('falls back to the page’s language for a tag it cannot read', () => {
		expect(joinLabels(['Photo', 'Name'], 'not a tag!')).toContain('Photo');
	});
});
