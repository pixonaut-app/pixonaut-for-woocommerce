#!/usr/bin/env node
// Reports, per locale, the strings that are untranslated or fuzzy, and fails
// when a translation's placeholders do not match its source's, or when a PO
// file's strings are not the template's (it was not merged with the current
// template). Untranslated and fuzzy strings do not fail: WordPress shows the
// English text for them. For a regional locale it names the locale its file
// was copied from and how many entries differ from that one's (informational),
// and fails when that file is missing.
//
// Usage: i18n-check.mjs [dir]   (default: languages/ in this checkout)
import { existsSync, readFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { root, SLUG } from './env.mjs';
import { LANGUAGES_DIR, POT_FILE, poFiles } from './i18n/files.mjs';
import { differingEntries, regionalBase } from './i18n/locales.mjs';
import { poProblems, templateDifferences } from './i18n/po.mjs';

const dir = resolve(process.argv[2] ?? join(root, LANGUAGES_DIR));
const files = poFiles(dir);
if (files.length === 0) {
	console.log(`No ${SLUG}-<locale>.po files to check.`);
	process.exit(0);
}
const potPath = join(dir, basename(POT_FILE));
if (!existsSync(potPath)) {
	console.error(
		`No template ${basename(POT_FILE)} to check the PO files against.`,
	);
	process.exit(1);
}
const pot = readFileSync(potPath, 'utf8');

/** @param {string} heading @param {string[]} items */
function list(heading, items) {
	if (items.length > 0) {
		console.log(`  ${heading}:`);
		for (const item of items) {
			console.log(`    ${item}`);
		}
	}
}

const paths = new Map(files.map(({ locale, path }) => [locale, path]));

let failed = false;
let unmerged = false;
let baseless = false;
for (const { locale, path } of files) {
	const text = readFileSync(path, 'utf8');
	const problems = poProblems(text);
	const differences = templateDifferences(pot, text);
	console.log(
		`${locale}: ${problems.total} strings, ${problems.untranslated.length} untranslated, ${problems.fuzzy.length} fuzzy, ${problems.placeholders.length} with mismatched placeholders`,
	);
	const base = regionalBase(locale);
	if (base !== undefined) {
		const basePath = paths.get(base);
		if (basePath === undefined) {
			console.log(`  Copied from ${base}, which has no PO file`);
			baseless = true;
		} else {
			const differing = differingEntries(text, readFileSync(basePath, 'utf8'));
			console.log(
				`  Copied from ${base}: ${differing} of ${problems.total} entries differ`,
			);
		}
	}
	list('Untranslated', problems.untranslated);
	list('Fuzzy', problems.fuzzy);
	list('Placeholders differ from the source', problems.placeholders);
	list('In the template, missing here', differences.missing);
	list('Here, not in the template', differences.extra);
	failed ||= problems.placeholders.length > 0;
	unmerged ||= differences.missing.length + differences.extra.length > 0;
}
if (failed) {
	console.error(
		'A translation must use the same placeholders as its source; fix those listed above.',
	);
}
if (unmerged) {
	console.error(
		'A PO file holds other strings than the template; run pnpm i18n:update.',
	);
}
if (baseless) {
	console.error(
		'A regional locale needs the PO file of the locale it was copied from (bin/i18n/locales.mjs).',
	);
}
if (failed || unmerged || baseless) {
	process.exit(1);
}
