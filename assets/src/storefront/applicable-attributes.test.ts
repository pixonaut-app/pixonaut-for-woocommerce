import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
	type ApplicableAttributes,
	type AttributeValues,
	dependentAttributes,
	hiddenAttributes,
} from './applicable-attributes';

type SharedCases = {
	products: Record<string, ApplicableAttributes>;
	cases: Array<{
		name: string;
		product: string;
		chosen: AttributeValues;
		hidden: string[];
	}>;
};

/** The cases the server's `ApplicableAttributesTest` answers too. */
const shared: SharedCases = JSON.parse(
	readFileSync('tests/fixtures/applicable-attributes.json', 'utf8'),
);

describe('hiddenAttributes', () => {
	it.each(shared.cases)('$name', ({ product, chosen, hidden }) => {
		expect(hiddenAttributes(shared.products[product], chosen)).toEqual(hidden);
	});
});

describe('dependentAttributes', () => {
	it('keeps only an attribute some variations set and others leave unset', () => {
		expect(
			dependentAttributes({
				fields: ['attribute_all', 'attribute_some', 'attribute_none'],
				variations: [
					{ attribute_all: 'a', attribute_some: 'x', attribute_none: '' },
					{ attribute_all: 'b' },
				],
			}),
		).toEqual(['attribute_some']);
	});
});
