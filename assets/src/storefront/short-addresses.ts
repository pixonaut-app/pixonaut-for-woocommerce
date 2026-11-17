import { type QueryNames, shortenAddress } from './query-names';

/** An address given to the history API, shortened when it names an
 *  attribute WooCommerce's way; anything else as it came. */
function shortened(
	url: string | URL | null | undefined,
	view: Window,
	names: QueryNames,
): string | URL | null | undefined {
	if (url === null || url === undefined) {
		return url;
	}
	const address = new URL(url, view.location.href);
	if (address.origin !== view.location.origin) {
		return url;
	}
	return shortenAddress(address, names)?.href ?? url;
}

/**
 * Keeps the browser's address naming each attribute by its short name. The
 * Product Filters block writes WooCommerce's names into the address it goes
 * to; the address the page was opened at may name them too. Either is
 * replaced in place by the same address with the short names, which the
 * server reads alike.
 *
 * WordPress's Interactivity router keeps each page it navigated to by the
 * address it was given, and on back or forward renders the one kept for the
 * browser's address, or loads the page when it has none. So each shortened
 * address remembers the one it stood for, and while back or forward lands
 * on it, the browser's address is that one until the router has read it.
 */
export function followShortAddresses(view: Window, names: QueryNames): void {
	if (Object.keys(names).length === 0) {
		return;
	}
	const { history } = view;
	const push = history.pushState.bind(history);
	const replace = history.replaceState.bind(history);
	/** By a shortened address's path and query, as the router keys its
	 *  pages, the path and query it was given as. */
	const given = new Map<string, string>();
	const pageOf = (address: URL) => address.pathname + address.search;
	const follow =
		(write: History['pushState']): History['pushState'] =>
		(data, unused, url) => {
			const short = shortened(url, view, names);
			const long =
				short === url || url === null || url === undefined
					? null
					: pageOf(new URL(url, view.location.href));
			write(data, unused, short);
			if (long !== null) {
				given.set(pageOf(new URL(view.location.href)), long);
			}
		};
	history.pushState = follow(push);
	history.replaceState = follow(replace);
	const opened = shortenAddress(new URL(view.location.href), names);
	if (opened !== null) {
		replace(history.state, '', opened.href);
	}
	// Capturing, so before the router's own listener, which reads the address at once.
	view.addEventListener(
		'popstate',
		() => {
			const short = view.location.href;
			const page = given.get(pageOf(new URL(short)));
			if (page === undefined) {
				return;
			}
			// The entry's own fragment, which may be another than the navigation's.
			const long = new URL(page + view.location.hash, short).href;
			replace(history.state, '', long);
			// Once every listener has run: the short address back.
			view.setTimeout(() => {
				if (view.location.href === long) {
					replace(history.state, '', short);
				}
			});
		},
		{ capture: true },
	);
}
