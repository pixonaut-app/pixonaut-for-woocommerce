import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { isCompiled, LANGUAGES_DIR, poFiles } from '../../bin/i18n/files.mjs';
import {
	differingEntries,
	REGIONAL_BASES,
	regionalBase,
} from '../../bin/i18n/locales.mjs';
import {
	parsePo,
	placeholders,
	pluralCount,
	poProblems,
	templateDifferences,
	withoutFuzzy,
} from '../../bin/i18n/po.mjs';
import { runScript, tempDir, writeFiles } from './support/scripts';

const HEADER = [
	'msgid ""',
	'msgstr ""',
	'"Language: sv_SE\\n"',
	'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
	'',
].join('\n');

/** A PO file holding `entries`, each a block of lines. */
function po(...entries: string[][]): string {
	return [HEADER, ...entries.map((lines) => `${lines.join('\n')}\n`)].join(
		'\n',
	);
}

const SIDE = ['msgctxt "product side"', 'msgid "Side"', 'msgstr "Sida"'];
const PLURAL = [
	'#, php-format',
	'msgid "%d order"',
	'msgid_plural "%d orders"',
	'msgstr[0] "%d beställning"',
	'msgstr[1] "%d beställningar"',
];
/** PLURAL without its last plural form, for a test to supply its own. */
const PLURAL_HEAD = PLURAL.slice(0, -1);

describe('parsePo', () => {
	it('reads context, plurals and flags, and skips the header', () => {
		expect(parsePo(po(SIDE, PLURAL))).toEqual([
			{ context: 'product side', msgid: 'Side', msgstr: ['Sida'], flags: [] },
			{
				msgid: '%d order',
				msgidPlural: '%d orders',
				msgstr: ['%d beställning', '%d beställningar'],
				flags: ['php-format'],
			},
		]);
	});

	it('joins continuation lines and unescapes', () => {
		const [entry] = parsePo(
			po(['msgid ""', '"Say \\"hi\\"\\n"', '"twice"', 'msgstr "Säg\\thej"']),
		);
		expect(entry?.msgid).toBe('Say "hi"\ntwice');
		expect(entry?.msgstr).toEqual(['Säg\thej']);
	});

	it('separates entries without blank lines between them', () => {
		const text = [...SIDE, '#, fuzzy', 'msgid "Print"', 'msgstr "Skriv ut"'];
		expect(
			parsePo(text.join('\n')).map(({ msgid, flags }) => [msgid, flags]),
		).toEqual([
			['Side', []],
			['Print', ['fuzzy']],
		]);
	});

	it('leaves out obsolete entries', () => {
		expect(parsePo(po(['#~ msgid "Gone"', '#~ msgstr "Borta"']))).toEqual([]);
	});
});

describe('placeholders', () => {
	it('numbers unnumbered placeholders in order', () => {
		expect(placeholders('%2$d of %1$s')).toEqual(placeholders('%s: %d'));
	});

	it('ignores a literal percent sign', () => {
		expect(placeholders('100%% of %s')).toEqual(['1$s']);
	});

	it('reads flags, width and precision', () => {
		expect(placeholders('%05.2f and %-3d')).toEqual(['1$f', '2$d']);
	});
});

describe('pluralCount', () => {
	it('reads nplurals from the header', () => {
		expect(pluralCount(HEADER)).toBe(2);
		expect(pluralCount(HEADER.replace('nplurals=2', 'nplurals=3'))).toBe(3);
	});

	it('is undefined without Plural-Forms', () => {
		expect(pluralCount('msgid ""\nmsgstr ""\n')).toBeUndefined();
	});
});

describe('poProblems', () => {
	it('finds nothing in a complete, correct file', () => {
		expect(poProblems(po(SIDE, PLURAL))).toEqual({
			total: 2,
			untranslated: [],
			fuzzy: [],
			placeholders: [],
		});
	});

	it('reports untranslated strings, a missing plural form included', () => {
		const problems = poProblems(
			po(['msgid "Proof"', 'msgstr ""'], [...PLURAL_HEAD, 'msgstr[1] ""']),
		);
		expect(problems.untranslated).toEqual(['"Proof"', '"%d order"']);
	});

	it('reports fuzzy strings with their context', () => {
		const problems = poProblems(po(['#, fuzzy', ...SIDE]));
		expect(problems.fuzzy).toEqual(['"Side" (product side)']);
	});

	it('accepts reordered placeholders', () => {
		const problems = poProblems(
			po(['#, php-format', 'msgid "%1$s for %2$s"', 'msgstr "%2$s: %1$s"']),
		);
		expect(problems.placeholders).toEqual([]);
	});

	it('reports a translation whose placeholders differ', () => {
		const problems = poProblems(
			po(['#, php-format', 'msgid "Order %s"', 'msgstr "Beställning %d"']),
		);
		expect(problems.placeholders).toEqual(['"Order %s": "Beställning %d"']);
	});

	it('names the plural form whose placeholders differ', () => {
		const problems = poProblems(
			po([...PLURAL_HEAD, 'msgstr[1] "beställningar"']),
		);
		expect(problems.placeholders).toEqual([
			'"%d order" msgstr[1]: "beställningar"',
		]);
	});

	it('checks the translated forms of a partly translated plural', () => {
		const problems = poProblems(
			po([
				'#, php-format',
				'msgid "%d order"',
				'msgid_plural "%d orders"',
				'msgstr[0] "%s sak"',
				'msgstr[1] ""',
			]),
		);
		expect(problems.untranslated).toEqual(['"%d order"']);
		expect(problems.placeholders).toEqual(['"%d order" msgstr[0]: "%s sak"']);
	});

	it('reports a percent sign that starts no valid placeholder', () => {
		const problems = poProblems(
			po(['#, php-format', 'msgid "Order %s"', 'msgstr "Order %s %q"']),
		);
		expect(problems.placeholders).toEqual([
			'"Order %s": "Order %s %q" ("%q" is not a placeholder; write %% for a percent sign)',
		]);
	});

	it('reports a trailing percent sign', () => {
		const problems = poProblems(
			po(['#, php-format', 'msgid "%d%% off"', 'msgstr "%d %"']),
		);
		expect(problems.placeholders).toEqual([
			'"%d%% off": "%d %" ("%" is not a placeholder; write %% for a percent sign)',
		]);
	});

	it('accepts a literal percent sign and a custom padding character', () => {
		const problems = poProblems(
			po(['#, php-format', 'msgid "%d%% off"', 'msgstr "%\'*3d%% rabatt"']),
		);
		expect(problems.placeholders).toEqual([]);
	});

	it('reports a plural with fewer forms than the language has', () => {
		const problems = poProblems(po(PLURAL_HEAD));
		expect(problems.untranslated).toEqual(['"%d order"']);
		expect(problems.placeholders).toEqual([]);
	});

	it('reports WooCommerce placeholders that differ, in any string', () => {
		const problems = poProblems(
			po([
				'msgid "Order {order_number} from {site_title}"',
				'msgstr "Beställning {ordernummer} från {site_title}"',
			]),
		);
		expect(problems.placeholders).toEqual([
			'"Order {order_number} from {site_title}": "Beställning {ordernummer} från {site_title}" (its {placeholders} differ from the source\'s; keep them as written)',
		]);
	});

	it('accepts reordered WooCommerce placeholders', () => {
		const problems = poProblems(
			po([
				'msgid "[{site_title}]: order {order_number}"',
				'msgstr "Beställning {order_number} [{site_title}]"',
			]),
		);
		expect(problems.placeholders).toEqual([]);
	});

	it('checks placeholders only in format strings', () => {
		const problems = poProblems(po(['msgid "100%"', 'msgstr "100 %"']));
		expect(problems.placeholders).toEqual([]);
	});
});

/** `entry` as the template holds it: the same source, no translation. */
function untranslated(entry: string[]): string[] {
	return entry.map((line) =>
		line.replace(/^(msgstr(?:\[\d+\])?) ".*"$/, '$1 ""'),
	);
}

describe('templateDifferences', () => {
	const POT = po(untranslated(SIDE), untranslated(PLURAL));

	it('finds nothing in a file merged with the template', () => {
		expect(templateDifferences(POT, po(PLURAL, SIDE))).toEqual({
			missing: [],
			extra: [],
		});
	});

	it('names the template strings a file lacks', () => {
		expect(templateDifferences(POT, po(SIDE)).missing).toEqual(['"%d order"']);
	});

	it('names the strings the template no longer has, obsolete ones aside', () => {
		const text = po(
			SIDE,
			PLURAL,
			['msgid "Print"', 'msgstr "Skriv ut"'],
			['#~ msgid "Gone"', '#~ msgstr "Borta"'],
		);
		expect(templateDifferences(POT, text).extra).toEqual(['"Print"']);
	});

	it('tells strings apart by context and plural source', () => {
		const text = po(
			['msgctxt "page side"', 'msgid "Side"', 'msgstr "Sida"'],
			[
				...PLURAL_HEAD.slice(0, 2),
				'msgid_plural "%d sales"',
				'msgstr[0] "a"',
				'msgstr[1] "b"',
			],
		);
		expect(templateDifferences(POT, text)).toEqual({
			missing: ['"Side" (product side)', '"%d order"'],
			extra: ['"Side" (page side)', '"%d order"'],
		});
	});
});

describe('regional locales', () => {
	it('names the locale a regional one was copied from', () => {
		expect(regionalBase('de_CH')).toBe('de_DE_formal');
		expect(regionalBase('es_AR')).toBe('es_MX');
		expect(regionalBase('de_DE')).toBeUndefined();
		expect(regionalBase('toString')).toBeUndefined();
	});

	it('maps only locales whose PO file ships', () => {
		const shipped = poFiles(join(__dirname, '..', '..', LANGUAGES_DIR)).map(
			({ locale }) => locale,
		);
		for (const [regional, base] of Object.entries(REGIONAL_BASES)) {
			expect(shipped).toContain(regional);
			expect(shipped).toContain(base);
		}
	});

	it('counts the entries translated differently from the base', () => {
		const base = po(SIDE, PLURAL, ['msgid "Proof"', 'msgstr "Korrektur"']);
		const regional = po(
			SIDE,
			[...PLURAL_HEAD, 'msgstr[1] "%d ordrar"'],
			['msgid "Print"', 'msgstr "Skriv ut"'],
		);
		expect(differingEntries(base, base)).toBe(0);
		expect(differingEntries(regional, base)).toBe(2);
	});
});

describe('withoutFuzzy', () => {
	const PRINT = ['#, fuzzy', 'msgid "Print"', 'msgstr "Skriv ut"'];
	const OBSOLETE = ['#~ msgid "Gone"', '#~ msgstr "Borta"'];

	it('drops fuzzy and obsolete entries and keeps the header and the rest', () => {
		const kept = withoutFuzzy(po(SIDE, PRINT, PLURAL, OBSOLETE));
		expect(kept).toBe(po(SIDE, PLURAL));
		expect(parsePo(kept).map(({ msgid }) => msgid)).toEqual([
			'Side',
			'%d order',
		]);
	});

	it('keeps a header flagged fuzzy', () => {
		const header = `#, fuzzy\n${HEADER}`;
		expect(withoutFuzzy(header)).toBe(header);
	});

	it('drops a fuzzy entry flagged with others', () => {
		expect(
			withoutFuzzy(po(['#, fuzzy, php-format', 'msgid "%s"', 'msgstr "%s"'])),
		).toBe(HEADER);
	});
});

describe('isCompiled', () => {
	it.each([
		'pixonaut-for-woocommerce-sv_SE.mo',
		'pixonaut-for-woocommerce-de_DE_formal.l10n.php',
	])('counts %s as compiled', (name) => {
		expect(isCompiled(name)).toBe(true);
	});

	it.each([
		'pixonaut-for-woocommerce-sv_SE.po',
		'pixonaut-for-woocommerce.pot',
		'glossary.md',
		'other-sv_SE.mo',
	])('leaves %s alone', (name) => {
		expect(isCompiled(name)).toBe(false);
	});
});

describe('i18n-check.mjs', () => {
	const PREFIX = 'pixonaut-for-woocommerce';
	const NOTHING = `No ${PREFIX}-<locale>.po files to check.`;
	const PROOF = ['msgid "Proof"', 'msgstr "Korrektur"'];
	/** The template the PO files below are merged with. */
	const POT = po(untranslated(PROOF), untranslated(SIDE));

	function check(files: Record<string, string>) {
		const dir = tempDir('languages');
		writeFiles(dir, { [`${PREFIX}.pot`]: POT, ...files });
		return runScript('i18n-check.mjs', [dir], { cwd: dir });
	}

	it('passes when there is nothing to check', () => {
		const result = check({});
		expect(result.status, result.stderr).toBe(0);
		expect(result.stdout).toContain(NOTHING);
	});

	it('reports untranslated and fuzzy strings per locale without failing', () => {
		const result = check({
			[`${PREFIX}-sv_SE.po`]: po(untranslated(PROOF), SIDE),
			[`${PREFIX}-de_DE.po`]: po(PROOF, ['#, fuzzy', ...SIDE]),
		});
		expect(result.status, result.stderr).toBe(0);
		expect(result.stdout).toContain(
			'de_DE: 2 strings, 0 untranslated, 1 fuzzy, 0 with mismatched placeholders',
		);
		expect(result.stdout).toContain(
			'sv_SE: 2 strings, 1 untranslated, 0 fuzzy',
		);
		expect(result.stdout).toMatch(/Untranslated:\n\s+"Proof"/);
	});

	it('fails on mismatched placeholders', () => {
		const order = ['#, php-format', 'msgid "Order %s"', 'msgstr "Beställning"'];
		const dir = tempDir('languages');
		writeFiles(dir, {
			[`${PREFIX}.pot`]: po(untranslated(order)),
			[`${PREFIX}-sv_SE.po`]: po(order),
		});
		const result = runScript('i18n-check.mjs', [dir], { cwd: dir });
		expect(result.status).toBe(1);
		expect(result.stdout).toContain('"Order %s": "Beställning"');
	});

	it('fails on a PO file not merged with the current template', () => {
		const result = check({
			[`${PREFIX}-sv_SE.po`]: po(SIDE, ['msgid "Print"', 'msgstr "Skriv ut"']),
		});
		expect(result.status).toBe(1);
		expect(result.stdout).toMatch(/In the template, missing here:\n\s+"Proof"/);
		expect(result.stdout).toMatch(/Here, not in the template:\n\s+"Print"/);
		expect(result.stderr).toContain('run pnpm i18n:update');
	});

	it('names a regional locale’s base and how many entries differ', () => {
		const result = check({
			[`${PREFIX}-de_DE.po`]: po(PROOF, SIDE),
			[`${PREFIX}-de_AT.po`]: po(PROOF, [
				...SIDE.slice(0, -1),
				'msgstr "Seite"',
			]),
		});
		expect(result.status, result.stderr).toBe(0);
		expect(result.stdout).toContain('Copied from de_DE: 1 of 2 entries differ');
	});

	it('fails when a regional locale’s base is missing', () => {
		const result = check({ [`${PREFIX}-fr_CA.po`]: po(PROOF, SIDE) });
		expect(result.status).toBe(1);
		expect(result.stdout).toContain('Copied from fr_FR, which has no PO file');
	});

	it('fails without a template', () => {
		const dir = tempDir('languages');
		writeFiles(dir, { [`${PREFIX}-sv_SE.po`]: po(SIDE) });
		const result = runScript('i18n-check.mjs', [dir], { cwd: dir });
		expect(result.status).toBe(1);
		expect(result.stderr).toContain(`No template ${PREFIX}.pot`);
	});

	it('ignores files that are not the plugin’s PO files', () => {
		const result = check({ 'other-sv_SE.po': po(['msgid "x"', 'msgstr ""']) });
		expect(result.stdout).toContain(NOTHING);
	});
});
