import { addFilter, removeFilter } from '@wordpress/hooks';
import { afterEach, describe, expect, it } from 'vitest';
import { createLabeler, LABEL_FILTER } from './labels';

const NAMESPACE = 'pixonaut/test';

describe('createLabeler', () => {
	afterEach(() => {
		removeFilter(LABEL_FILTER, NAMESPACE);
	});

	it('returns the printed label', () => {
		const label = createLabeler({ line_state_shipped: 'Shipped' });

		expect(label('line_state_shipped')).toBe('Shipped');
	});

	it('reads an unknown key as itself', () => {
		expect(createLabeler({})('no_such_label')).toBe('no_such_label');
	});

	it('reads a prototype member name as itself', () => {
		const label = createLabeler({});

		expect(label('toString')).toBe('toString');
		expect(label('__proto__')).toBe('__proto__');
		expect(label('constructor')).toBe('constructor');
	});

	it('runs the filter with the key and context', () => {
		addFilter(
			LABEL_FILTER,
			NAMESPACE,
			(text: string, key: string, context: { side?: string }) =>
				key === 'line_state_shipped' ? `${text} (${context.side})` : text,
		);
		const label = createLabeler({ line_state_shipped: 'Shipped' });

		expect(label('line_state_shipped', { side: 'front' })).toBe(
			'Shipped (front)',
		);
	});

	it('keeps the label when a filter returns something other than a string', () => {
		addFilter(LABEL_FILTER, NAMESPACE, () => 42);

		expect(createLabeler({ a: 'A' })('a')).toBe('A');
	});
});
