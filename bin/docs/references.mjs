// Which page holds which generated section, and how a page's sections are
// replaced. A section sits between
//
//   <!-- BEGIN GENERATED <name> ... -->
//   <!-- END GENERATED <name> -->
//
// and everything between the two lines is rewritten; the rest of the page is
// written by hand.
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
	renderDeprecatedHooks,
	renderEmails,
	renderHooks,
	renderLabelKeys,
	renderRestRoutes,
	renderSettings,
	renderTemplates,
} from './render.mjs';

/** @typedef {import('./render.mjs').ReferenceData} ReferenceData */

/**
 * Page => section name => renderer.
 *
 * @type {Record<string, Record<string, (data: ReferenceData) => string>>}
 */
export const PAGES = {
	'docs/developer/hooks.md': {
		hooks: renderHooks,
		'deprecated-hooks': renderDeprecatedHooks,
	},
	'docs/developer/label-keys.md': { 'label-keys': renderLabelKeys },
	'docs/developer/settings.md': { settings: renderSettings },
	'docs/developer/emails.md': { emails: renderEmails },
	'docs/developer/rest-api.md': { 'rest-routes': renderRestRoutes },
	'docs/developer/templates.md': { templates: renderTemplates },
};

/** @param {string} text */
function escapeRegExp(text) {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * The page with one section's content replaced.
 *
 * @param {string} page
 * @param {string} name
 * @param {string} content
 * @returns {string}
 */
export function replaceSection(page, name, content) {
	const section = new RegExp(
		`(<!-- BEGIN GENERATED ${escapeRegExp(name)}\\b[^\\n]*-->\\n)[\\s\\S]*?(<!-- END GENERATED ${escapeRegExp(name)} -->)`,
	);
	if (!section.test(page)) {
		throw new Error(`No GENERATED ${name} markers.`);
	}
	return page.replace(
		section,
		(_, begin, end) => `${begin}\n${content}\n\n${end}`,
	);
}

/**
 * Regenerates every page under `root`. With `check`, writes nothing.
 *
 * @param {string} root
 * @param {ReferenceData} data
 * @param {{ check?: boolean }} [options]
 * @returns {string[]} The pages whose content changed, or would change.
 */
export function updatePages(root, data, { check = false } = {}) {
	const stale = [];
	for (const [file, sections] of Object.entries(PAGES)) {
		const path = join(root, file);
		const before = readFileSync(path, 'utf8');
		let after = before;
		for (const [name, render] of Object.entries(sections)) {
			try {
				after = replaceSection(after, name, render(data));
			} catch (error) {
				throw new Error(`${file}: ${/** @type {Error} */ (error).message}`);
			}
		}
		if (after !== before) {
			stale.push(file);
			if (!check) {
				writeFileSync(path, after);
			}
		}
	}
	return stale;
}

/**
 * Every hook that breaks the docblock rules, with what it lacks. The PHP unit
 * suite enforces the same rules; this keeps a page from being generated with
 * holes in it.
 *
 * @param {ReferenceData} data
 * @returns {string[]}
 */
export function undocumentedHooks(data) {
	return data.hooks
		.filter(
			(hook) => !hook.documented || hook.summary === '' || hook.since === '',
		)
		.map((hook) => `${hook.name} (${hook.file}:${hook.line})`);
}
