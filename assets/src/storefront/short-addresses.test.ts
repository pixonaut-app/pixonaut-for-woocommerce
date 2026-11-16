// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { followShortAddresses } from './short-addresses';

const NAMES = { storlek: 'storlek' };

describe('followShortAddresses', () => {
	const push = history.pushState;
	const replace = history.replaceState;

	beforeEach(() => {
		replace.call(history, null, '', '/shop/');
	});

	afterEach(() => {
		history.pushState = push;
		history.replaceState = replace;
	});

	it('shortens the address the page was opened at, adding no history entry', () => {
		replace.call(
			history,
			null,
			'',
			'/shop/?filter_storlek=50x70-cm&query_type_storlek=or',
		);
		const entries = history.length;

		followShortAddresses(window, NAMES);

		expect(location.pathname + location.search).toBe('/shop/?storlek=50x70-cm');
		expect(history.length).toBe(entries);
	});

	it('shortens an address the filters go to', () => {
		followShortAddresses(window, NAMES);

		history.pushState(
			{ router: true },
			'',
			'/shop/?filter_storlek=30x40-cm&query_type_storlek=or',
		);

		expect(location.search).toBe('?storlek=30x40-cm');
		expect(history.state).toEqual({ router: true });
	});

	it('leaves another address as it is', () => {
		followShortAddresses(window, NAMES);

		history.pushState(null, '', '/shop/?orderby=price');

		expect(location.search).toBe('?orderby=price');
	});

	it('changes nothing without names', () => {
		followShortAddresses(window, {});

		expect(history.pushState).toBe(push);
	});
});
