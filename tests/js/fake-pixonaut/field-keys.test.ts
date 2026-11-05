import { describe, expect, it } from 'vitest';
import { fieldKeysOf } from '../../fake-pixonaut/src/field-keys';

describe('fieldKeysOf', () => {
	it('lists the keys of the fields a customer is offered, in order, each once', () => {
		const doc = {
			background: { color: { editable: true, fieldKey: 'backdrop' } },
			shape: { editable: false, fieldKey: 'outline' },
			layers: [
				{
					id: 'l1',
					props: {
						text: { editable: true, fieldKey: 'name' },
						font: { editable: true },
					},
					opacity: { editable: true, fieldKey: 'name' },
				},
				{ id: 'l2', props: { src: { editable: true, fieldKey: ' photo' } } },
				{ props: { text: { editable: true, fieldKey: 'unnamed' } } },
			],
		};

		expect(fieldKeysOf(doc)).toEqual(['backdrop', 'name']);
	});

	it('answers none for a document without fields', () => {
		expect(fieldKeysOf({})).toEqual([]);
	});
});
