// The regional locales the plugin ships and the locale each one was copied
// from. WordPress does not fall back from a regional locale to its base, so
// a fix to a base translation is applied to each locale copied from it.
import { entryKey, parsePo } from './po.mjs';

/**
 * Regional locale → the locale its PO file was copied from. A base may be
 * regional itself (`de_CH` follows `de_DE_formal`), so a fix to `de_DE`
 * reaches `de_CH` through `de_DE_formal`.
 *
 * @type {Readonly<Record<string, string>>}
 */
export const REGIONAL_BASES = Object.freeze({
	de_AT: 'de_DE',
	de_DE_formal: 'de_DE',
	de_CH: 'de_DE_formal',
	fr_BE: 'fr_FR',
	fr_CA: 'fr_FR',
	es_MX: 'es_ES',
	es_AR: 'es_MX',
	es_CO: 'es_MX',
	es_CL: 'es_MX',
	nl_BE: 'nl_NL',
	nl_NL_formal: 'nl_NL',
});

/**
 * The locale `locale` was copied from, or undefined for a base locale.
 *
 * @param {string} locale
 * @returns {string | undefined}
 */
export function regionalBase(locale) {
	return Object.hasOwn(REGIONAL_BASES, locale)
		? REGIONAL_BASES[locale]
		: undefined;
}

/**
 * How many of a regional PO file's entries translate differently from its
 * base's, a string the base lacks included.
 *
 * @param {string} text The regional PO file.
 * @param {string} base The base locale's PO file.
 */
export function differingEntries(text, base) {
	const baseForms = new Map(
		parsePo(base).map((entry) => [
			entryKey(entry),
			JSON.stringify(entry.msgstr),
		]),
	);
	return parsePo(text).filter(
		(entry) => baseForms.get(entryKey(entry)) !== JSON.stringify(entry.msgstr),
	).length;
}
