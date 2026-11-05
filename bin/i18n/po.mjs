// Reads gettext PO files and finds what keeps a translation from being
// complete or correct: untranslated and fuzzy strings (a plural short of its
// language's forms included), translations whose printf or `{name}`
// placeholders differ from the source's or are not valid at all, and files
// that were not merged with the current template.

/**
 * @typedef {{
 *   context?: string,
 *   msgid: string,
 *   msgidPlural?: string,
 *   msgstr: string[],
 *   flags: string[],
 * }} PoEntry
 */

/**
 * Every percent sign with what follows it: `%%` (a literal percent sign), a
 * PHP printf conversion such as `%s`, `%1$s`, `%05.2f` or `%'*10d` (the
 * `'` flag takes the padding character after it), or, when neither follows, the
 * bare `%`, which PHP 8's `sprintf()` rejects with a ValueError.
 */
const PERCENT =
	/%(?:%|(?:(\d+)\$)?(?:[-+ 0]|'.)*\d*(?:\.\d+)?([bcdeEfFgGhHosuxX]))?/gsu;
/** A placeholder in braces, filled in by WooCommerce rather than `sprintf()`. */
const TOKEN = /\{[a-z_]+\}/g;
/** The header line that gives the language's number of plural forms. */
const PLURAL_FORMS = /^"Plural-Forms:[^"]*\bnplurals\s*=\s*(\d+)/m;
/** @type {Record<string, string>} */
const ESCAPES = { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\' };

/** @param {string} quoted A PO string literal, quotes included. */
function unquote(quoted) {
	return quoted
		.slice(1, -1)
		.replace(/\\(.)/g, (whole, char) => ESCAPES[char] ?? whole);
}

/**
 * The entries of a PO file, without the header and without obsolete (`#~`)
 * entries.
 *
 * @param {string} text
 * @returns {PoEntry[]}
 */
export function parsePo(text) {
	/** @type {PoEntry[]} */
	const entries = [];
	/** @type {PoEntry | undefined} */
	let entry;
	/** @type {string[]} */
	let flags = [];
	/** The field the next continuation line (`"..."`) appends to. */
	let append = (/** @type {string} */ _value) => {};

	const start = () => {
		if (entry === undefined) {
			entry = { msgid: '', msgstr: [], flags };
			entries.push(entry);
		}
		return entry;
	};
	/** Ends the current entry once it has its translation. */
	const endTranslated = () => {
		if (entry !== undefined && entry.msgstr.length > 0) {
			entry = undefined;
			flags = [];
		}
	};

	for (const raw of text.split(/\r?\n/)) {
		const line = raw.trim();
		if (line === '') {
			entry = undefined;
			flags = [];
			continue;
		}
		if (line.startsWith('#')) {
			endTranslated();
			if (line.startsWith('#,')) {
				flags.push(
					...line
						.slice(2)
						.split(',')
						.map((flag) => flag.trim()),
				);
			}
			continue;
		}
		const keyword = line.match(
			/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/,
		);
		if (keyword) {
			const [, name, index, quoted] = keyword;
			if (name === 'msgctxt' || name === 'msgid') {
				endTranslated();
			}
			const current = start();
			const value = unquote(quoted);
			if (name === 'msgctxt') {
				current.context = value;
				append = (more) => {
					current.context += more;
				};
			} else if (name === 'msgid') {
				current.msgid = value;
				append = (more) => {
					current.msgid += more;
				};
			} else if (name === 'msgid_plural') {
				current.msgidPlural = value;
				append = (more) => {
					current.msgidPlural += more;
				};
			} else {
				const form = Number(index ?? 0);
				current.msgstr[form] = value;
				append = (more) => {
					current.msgstr[form] += more;
				};
			}
			continue;
		}
		if (line.startsWith('"')) {
			append(unquote(line));
		}
	}
	return entries.filter((found) => found.msgid !== '');
}

/**
 * The printf conversions in `text`: each valid one as `<position>$<type>`,
 * sorted, so `%s %d` and `%2$d %1$s` compare equal, and each percent sign
 * that starts no valid conversion, as written (`%q`, or `%` at the end).
 *
 * @param {string} text
 */
function scan(text) {
	let next = 1;
	/** @type {string[]} */
	const found = [];
	/** @type {string[]} */
	const invalid = [];
	for (const match of text.matchAll(PERCENT)) {
		const [whole, position, type] = match;
		if (whole === '%%') {
			continue;
		}
		if (type === undefined) {
			// The percent sign and the character after it, if any.
			const rest = text.slice(match.index ?? 0);
			invalid.push(rest.match(/^%./su)?.[0] ?? whole);
			continue;
		}
		found.push(`${position ?? next++}$${type}`);
	}
	return { placeholders: found.sort(), invalid };
}

/**
 * The printf placeholders in `text`, each as `<position>$<type>`, sorted, so
 * `%s %d` and `%2$d %1$s` compare equal.
 *
 * @param {string} text
 */
export function placeholders(text) {
	return scan(text).placeholders;
}

/**
 * The `{name}` placeholders in `text`, sorted, such as the `{order_number}`
 * and `{site_title}` WooCommerce fills into an email's subject and heading.
 *
 * @param {string} text
 */
function tokens(text) {
	return (text.match(TOKEN) ?? []).sort();
}

/**
 * The number of plural forms the PO file's header gives its language, or
 * undefined when it has no `Plural-Forms`.
 *
 * @param {string} text
 */
export function pluralCount(text) {
	const match = text.match(PLURAL_FORMS);
	return match === null ? undefined : Number(match[1]);
}

/** @param {string[]} a @param {string[]} b */
function same(a, b) {
	return a.length === b.length && a.every((item, index) => item === b[index]);
}

/**
 * How `entry` reads to a translator: its context, if any, then its source.
 *
 * @param {PoEntry} entry
 */
export function label(entry) {
	return entry.context === undefined
		? JSON.stringify(entry.msgid)
		: `${JSON.stringify(entry.msgid)} (${entry.context})`;
}

/**
 * The problems in one PO file. A translation that is empty or fuzzy falls
 * back to English, so those are reported for completeness; a translation
 * whose placeholders differ from the source's, or that has a percent sign
 * starting no valid placeholder, prints wrong or breaks `sprintf()`, so it is
 * an error; `{name}` placeholders are compared in every entry, since
 * WooCommerce fills them in whether or not the string is a format string. A
 * plural entry with some forms empty, or fewer than the header's `nplurals`,
 * is untranslated, and its other forms are still checked, since WordPress
 * shows them.
 *
 * @param {string} text
 */
export function poProblems(text) {
	const entries = parsePo(text);
	const nplurals = pluralCount(text);
	/** @type {{ untranslated: string[], fuzzy: string[], placeholders: string[] }} */
	const problems = { untranslated: [], fuzzy: [], placeholders: [] };
	for (const entry of entries) {
		// A plural has as many forms as the header's `nplurals`; `Array.from`
		// fills the holes a file skipping a form index leaves, and the forms
		// a file stops short of.
		const count =
			entry.msgidPlural === undefined
				? entry.msgstr.length
				: Math.max(entry.msgstr.length, nplurals ?? 0);
		const forms = Array.from(
			{ length: count },
			(_, index) => entry.msgstr[index] ?? '',
		);
		if (forms.length === 0 || forms.includes('')) {
			problems.untranslated.push(label(entry));
		}
		if (entry.flags.includes('fuzzy')) {
			problems.fuzzy.push(label(entry));
		}
		const printf = entry.flags.includes('php-format');
		// Each plural form may follow the singular or the plural source,
		// since a language's forms do not map one to one onto English's.
		const sourceTexts = [entry.msgid, entry.msgidPlural].filter(
			(source) => source !== undefined,
		);
		const sources = sourceTexts.map(placeholders);
		const sourceTokens = sourceTexts.map(tokens);
		forms.forEach((form, index) => {
			if (form === '') {
				return;
			}
			const which = entry.msgidPlural === undefined ? '' : ` msgstr[${index}]`;
			const where = `${label(entry)}${which}: ${JSON.stringify(form)}`;
			if (!sourceTokens.some((expected) => same(expected, tokens(form)))) {
				problems.placeholders.push(
					`${where} (its {placeholders} differ from the source's; keep them as written)`,
				);
			}
			if (!printf) {
				return;
			}
			const found = scan(form);
			for (const sequence of found.invalid) {
				problems.placeholders.push(
					`${where} (${JSON.stringify(sequence)} is not a placeholder; write %% for a percent sign)`,
				);
			}
			if (
				found.invalid.length === 0 &&
				!sources.some((expected) => same(expected, found.placeholders))
			) {
				problems.placeholders.push(where);
			}
		});
	}
	return { total: entries.length, ...problems };
}

/**
 * What identifies an entry to gettext: its context, its source and, for a
 * plural, its plural source. Two entries with the same key are one string.
 *
 * @param {PoEntry} entry
 */
export function entryKey(entry) {
	return JSON.stringify([entry.context, entry.msgid, entry.msgidPlural]);
}

/**
 * How a PO file's strings differ from its template's: the template's strings
 * the PO file lacks, and the PO file's strings the template no longer has
 * (obsolete `#~` entries aside). Either means the PO file was not merged with
 * the current template.
 *
 * @param {string} pot The template.
 * @param {string} text The PO file.
 */
export function templateDifferences(pot, text) {
	const template = new Map(
		parsePo(pot).map((entry) => [entryKey(entry), entry]),
	);
	const translated = new Map(
		parsePo(text).map((entry) => [entryKey(entry), entry]),
	);
	/** @param {Map<string, PoEntry>} from @param {Map<string, PoEntry>} not */
	const only = (from, not) =>
		[...from]
			.filter(([found]) => !not.has(found))
			.map(([, entry]) => label(entry));
	return {
		missing: only(template, translated),
		extra: only(translated, template),
	};
}

/** @param {string} line */
const isObsolete = (line) => line.startsWith('#~');

/**
 * `text` without its fuzzy and obsolete (`#~`) entries, for compiling: WP-CLI's
 * `make-mo` and `make-php` keep a fuzzy translation, which would show an
 * unreviewed translation where the English source belongs. The header is kept.
 * Entries are the blank-line separated blocks gettext's tools write.
 *
 * @param {string} text
 */
export function withoutFuzzy(text) {
	const kept = text.split(/\r?\n(?:[ \t]*\r?\n)+/).filter((block) => {
		const lines = block
			.split(/\r?\n/)
			.map((line) => line.trim())
			.filter((line) => line !== '');
		const [entry] = parsePo(block);
		if (entry === undefined) {
			// The header, or an obsolete entry, which parsePo skips.
			return !lines.some(isObsolete);
		}
		return !entry.flags.includes('fuzzy');
	});
	return `${kept.join('\n\n').trimEnd()}\n`;
}
