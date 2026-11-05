// The themes and page builders of the compatibility tests, as wp-env sources.
// The Nightly's compatibility job and the preview site both install them.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { root } from './env.mjs';

const THEME_ZIP = 'https://downloads.wordpress.org/theme';
const PLUGIN_ZIP = 'https://downloads.wordpress.org/plugin';

/**
 * @returns {{ builders: Record<string, string>, combinations: { id: string, label: string, theme: string, builder?: string }[] }}
 */
export function compatData() {
	return JSON.parse(
		readFileSync(join(root, 'tests', 'compat', 'combinations.json'), 'utf8'),
	);
}

/** wp-env sources of every builder. */
export function builderSources() {
	return Object.keys(compatData().builders).map(
		(builder) => `${PLUGIN_ZIP}/${builder}.zip`,
	);
}

/**
 * wp-env sources of every theme a combination uses, and of `extra` slugs.
 *
 * @param {string[]} [extra]
 */
export function themeSources(extra = []) {
	const themes = compatData().combinations.map(({ theme }) => theme);
	return [...new Set([...extra, ...themes])].map(
		(theme) => `${THEME_ZIP}/${theme}.zip`,
	);
}
