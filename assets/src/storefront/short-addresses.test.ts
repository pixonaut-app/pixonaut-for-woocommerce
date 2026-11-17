// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { followShortAddresses } from './short-addresses';

const NAMES = { storlek: 'storlek' };

/** The popstate listeners the script adds, removed after each test. */
const added: Array<
	[
		EventListenerOrEventListenerObject,
		boolean | AddEventListenerOptions | undefined,
	]
> = [];

beforeEach(() => {
	const add = window.addEventListener.bind(window);
	vi.spyOn(window, 'addEventListener').mockImplementation(
		(
			type: string,
			listener: EventListenerOrEventListenerObject,
			options?: boolean | AddEventListenerOptions,
		) => {
			if (type === 'popstate') {
				added.push([listener, options]);
			}
			add(type, listener, options);
		},
	);
});

afterEach(() => {
	vi.restoreAllMocks();
	for (const [listener, options] of added.splice(0)) {
		window.removeEventListener('popstate', listener, options);
	}
});

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

describe('followShortAddresses on back and forward', () => {
	const push = history.pushState;
	const replace = history.replaceState;

	beforeEach(() => {
		vi.useFakeTimers();
		replace.call(history, null, '', '/shop/');
	});

	afterEach(() => {
		history.pushState = push;
		history.replaceState = replace;
		vi.useRealTimers();
	});

	/** The address a listener added after the script's, as the router's
	 *  is, reads while back or forward lands on the current entry. */
	function addressSeenOnPopstate(): string {
		let seen = '';
		const router = () => {
			seen = location.pathname + location.search;
		};
		window.addEventListener('popstate', router);
		window.dispatchEvent(new PopStateEvent('popstate'));
		return seen;
	}

	it('gives the address the filters went to while back or forward lands on it, then the short one again', () => {
		followShortAddresses(window, NAMES);
		history.pushState(
			{ router: true },
			'',
			'/shop/?filter_storlek=30x40-cm&query_type_storlek=or',
		);

		expect(addressSeenOnPopstate()).toBe(
			'/shop/?filter_storlek=30x40-cm&query_type_storlek=or',
		);

		vi.runAllTimers();
		expect(location.pathname + location.search).toBe('/shop/?storlek=30x40-cm');
		expect(history.state).toEqual({ router: true });
	});

	it('gives the address the filters went to on an entry of an anchor on it, keeping the anchor', () => {
		followShortAddresses(window, NAMES);
		history.pushState(
			null,
			'',
			'/shop/?filter_storlek=30x40-cm&query_type_storlek=or',
		);
		// An anchor's entry, which no script writes.
		replace.call(history, null, '', '/shop/?storlek=30x40-cm#products');

		expect(addressSeenOnPopstate()).toBe(
			'/shop/?filter_storlek=30x40-cm&query_type_storlek=or',
		);
		expect(location.hash).toBe('#products');

		vi.runAllTimers();
		expect(location.pathname + location.search + location.hash).toBe(
			'/shop/?storlek=30x40-cm#products',
		);
	});

	it('leaves an address that was not shortened as it is', () => {
		followShortAddresses(window, NAMES);
		history.pushState(null, '', '/shop/?orderby=price');

		expect(addressSeenOnPopstate()).toBe('/shop/?orderby=price');
	});

	it('leaves the address where it moved on before the short one is back', () => {
		followShortAddresses(window, NAMES);
		history.pushState(null, '', '/shop/?filter_storlek=30x40-cm');
		addressSeenOnPopstate();
		replace.call(history, null, '', '/shop/?orderby=price');

		vi.runAllTimers();

		expect(location.search).toBe('?orderby=price');
	});
});
