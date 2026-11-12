import { describe, expect, it, vi } from 'vitest';
import type { PixonautDesigner } from '../types/sdk';
import { carryUnkeyedValues } from './template-carry';

/** A designer showing a template whose form offers `fields`, holding
 *  `values` now. */
function designer(
	fields: Array<{ layerId: string; prop: string }>,
	values: Record<string, Record<string, unknown>> = {},
	layers: object[] = [],
) {
	const setValue = vi.fn();
	const shown = {
		getState: () => ({ templateId: 'mini-poster@1', values }),
		getTemplate: () => ({ layers }),
		getFormSchema: () => ({ steps: [{ fields }] }),
		setValue,
	} as unknown as PixonautDesigner;
	return { shown, setValue };
}

describe('carryUnkeyedValues', () => {
	it('gives a field on a layer of the same id the value the customer gave it', () => {
		const { shown, setValue } = designer([
			{ layerId: 'slogan', prop: 'content' },
			{ layerId: 'slogan', prop: 'fill' },
		]);

		carryUnkeyedValues(shown, {
			slogan: { content: 'Hello' },
			motiv: { src: 'ast_1' },
			$fields: { name: 'Kept by its key' },
		});

		expect(setValue.mock.calls).toEqual([['slogan', 'content', 'Hello']]);
	});

	it('writes over a value the template kept from an earlier visit, and leaves one alike', () => {
		const { shown, setValue } = designer(
			[
				{ layerId: 'slogan', prop: 'content' },
				{ layerId: 'date', prop: 'content' },
			],
			{ slogan: { content: 'Old' }, date: { content: '2026-11-12' } },
		);

		carryUnkeyedValues(shown, {
			slogan: { content: 'New' },
			date: { content: '2026-11-12' },
		});

		expect(setValue.mock.calls).toEqual([['slogan', 'content', 'New']]);
	});

	it('leaves a field the new template keys to the SDK, which carried its key’s value', () => {
		const { shown, setValue } = designer(
			[{ layerId: 'slogan', prop: 'content' }],
			{ $fields: { name: 'Ada' } },
			[{ id: 'slogan', props: { content: { fieldKey: 'name' } } }],
		);

		carryUnkeyedValues(shown, {
			slogan: { content: 'A caption' },
			$fields: { name: 'Ada' },
		});

		expect(setValue).not.toHaveBeenCalled();
	});
});
