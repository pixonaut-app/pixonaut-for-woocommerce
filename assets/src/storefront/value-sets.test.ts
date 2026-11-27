import { describe, expect, it } from 'vitest';
import {
	baseIdOf,
	sameValueSets,
	valueSetOf,
	valueSetsFor,
} from './value-sets';

const SET = 'vs_3f2e9a1b7c4d5e6f8a9b0c1d';

const OTHER_SET = 'vs_0a1b2c3d4e5f6a7b8c9d0e1f';

describe('value sets', () => {
	it('names a template by its base id', () => {
		expect(baseIdOf('mini-mug@3')).toBe('mini-mug');
		expect(baseIdOf('mini-mug')).toBe('mini-mug');
	});

	it('finds the set options open their template on, by its base id or its id', () => {
		expect(
			valueSetOf({ templateId: 'mini-mug@3', valueSets: { 'mini-mug': SET } }),
		).toBe(SET);
		expect(
			valueSetOf({
				templateId: 'mini-mug@3',
				valueSets: { 'mini-mug@3': SET },
			}),
		).toBe(SET);
		expect(
			valueSetOf({ templateId: 'mini-tee@2', valueSets: { 'mini-mug': SET } }),
		).toBeUndefined();
		expect(valueSetOf({ templateId: 'mini-mug@3' })).toBeUndefined();
		expect(valueSetOf(undefined)).toBeUndefined();
	});

	it('opens a template on a set by its base id, and on none without one', () => {
		expect(valueSetsFor('mini-mug@3', SET)).toEqual({
			valueSets: { 'mini-mug': SET },
		});
		expect(valueSetsFor('mini-mug@3', undefined)).toEqual({});
	});

	it('tells the same sets from others whatever their order', () => {
		expect(
			sameValueSets({ a: SET, b: OTHER_SET }, { b: OTHER_SET, a: SET }),
		).toBe(true);
		expect(sameValueSets(undefined, {})).toBe(true);
		expect(sameValueSets({ a: SET }, { a: OTHER_SET })).toBe(false);
		expect(sameValueSets({ a: SET }, undefined)).toBe(false);
	});
});
