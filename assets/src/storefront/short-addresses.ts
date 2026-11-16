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
 */
export function followShortAddresses(view: Window, names: QueryNames): void {
	if (Object.keys(names).length === 0) {
		return;
	}
	const { history } = view;
	const push = history.pushState.bind(history);
	const replace = history.replaceState.bind(history);
	history.pushState = (data, unused, url) =>
		push(data, unused, shortened(url, view, names));
	history.replaceState = (data, unused, url) =>
		replace(data, unused, shortened(url, view, names));
	const opened = shortenAddress(new URL(view.location.href), names);
	if (opened !== null) {
		replace(history.state, '', opened.href);
	}
}
