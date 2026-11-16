/** Each global attribute's short name in the shop's addresses, by its slug
 *  without `pa_` (`storlek`), as the server prints it (`QueryNames`). */
export type QueryNames = Record<string, string>;

/** The global the server prints the names into (`ShortAddresses::NAMES_GLOBAL`). */
export const NAMES_GLOBAL = 'pixonautQueryNames';

/** A product page's attribute field, before the attribute's slug. */
const FIELD_PREFIX = 'attribute_pa_';

/** An archive's attribute filter, before the attribute's slug. */
const FILTER_PREFIX = 'filter_';

/** How a filter's values combine, before the attribute's slug. */
const QUERY_TYPE_PREFIX = 'query_type_';

/** The way the Product Filters block combines a filter's values unless
 *  told otherwise, which a short address means by naming none. */
const ANY_VALUE = 'or';

/** The way WooCommerce combines a filter's values when its address names
 *  none, as the classic layered nav widget's do. */
const ALL_VALUES = 'and';

export function readQueryNames(
	scope: Record<string, unknown> = globalThis as Record<string, unknown>,
): QueryNames {
	const names = scope[NAMES_GLOBAL];
	if (typeof names !== 'object' || names === null) {
		return {};
	}
	return Object.fromEntries(
		Object.entries(names).filter(
			(entry): entry is [string, string] => typeof entry[1] === 'string',
		),
	);
}

/** The attribute a name in an address names after a prefix, when it has a
 *  short name. */
function slugAfter(
	key: string,
	prefix: string,
	names: QueryNames,
): string | null {
	if (!key.startsWith(prefix)) {
		return null;
	}
	const slug = key.slice(prefix.length);
	return Object.hasOwn(names, slug) ? slug : null;
}

/** The name a form field goes by in the address: a global attribute's
 *  short name, else the field's own (a custom attribute's). */
export function addressName(field: string, names: QueryNames): string {
	const slug = slugAfter(field, FIELD_PREFIX, names);
	return slug === null ? field : names[slug];
}

/**
 * An address naming each attribute by its short name where it names it
 * WooCommerce's way: `attribute_pa_storlek` and `filter_storlek` become
 * `storlek`, and a filter's `query_type_storlek=or` goes. A filter naming
 * no way of combining, which WooCommerce reads as `and`, gains
 * `query_type_storlek=and`, and a short name the address also gives goes,
 * as WooCommerce's name wins over it. Null when it names none that way, so
 * an address is only ever rewritten for a reason.
 */
export function shortenAddress(url: URL, names: QueryNames): URL | null {
	const pairs = [...url.searchParams];
	const filtered = new Set<string>();
	const long = new Set<string>();
	const typed = new Set<string>();
	for (const [key] of pairs) {
		const filter = slugAfter(key, FILTER_PREFIX, names);
		if (filter !== null) {
			filtered.add(filter);
		}
		const slug = slugAfter(key, FIELD_PREFIX, names) ?? filter;
		if (slug !== null) {
			long.add(names[slug]);
		}
		const type = slugAfter(key, QUERY_TYPE_PREFIX, names);
		if (type !== null) {
			typed.add(type);
		}
	}
	let changed = false;
	const kept: Array<[string, string]> = [];
	for (const [key, value] of pairs) {
		if (long.has(key)) {
			changed = true;
			continue;
		}
		const filter = slugAfter(key, FILTER_PREFIX, names);
		const slug = slugAfter(key, FIELD_PREFIX, names) ?? filter;
		if (slug !== null) {
			kept.push([names[slug], value]);
			if (filter !== null && !typed.has(filter)) {
				kept.push([`${QUERY_TYPE_PREFIX}${filter}`, ALL_VALUES]);
			}
			changed = true;
			continue;
		}
		const type = slugAfter(key, QUERY_TYPE_PREFIX, names);
		if (
			type !== null &&
			filtered.has(type) &&
			value.toLowerCase() === ANY_VALUE
		) {
			changed = true;
			continue;
		}
		kept.push([key, value]);
	}
	if (!changed) {
		return null;
	}
	const shortened = new URL(url.href);
	shortened.search = new URLSearchParams(kept).toString();
	return shortened;
}
