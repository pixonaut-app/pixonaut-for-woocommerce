// What the release zip may contain. The zip is built from this allowlist and
// checked against it again: any path it does not name fails the release.
import { readFileSync } from 'node:fs';
import { posix } from 'node:path';
import { unzipSync } from 'fflate';
import { SLUG } from '../env.mjs';
import { compiledNames, LANGUAGES_DIR } from '../i18n/files.mjs';
import { DEFAULT_FLAVOR, flavorProblems } from './flavor.mjs';

/** Every path in the zip sits under this folder, the plugin's directory. */
export const ROOT_DIR = `${SLUG}/`;

/** Sources copied into the zip, relative to the checkout. */
export const SOURCES = [
	`${SLUG}.php`,
	'uninstall.php',
	'src',
	'templates',
	'assets/build',
	'assets/images',
	'languages',
	'LICENSE',
	'CHANGELOG.md',
];

/**
 * Files under SOURCES that stay out of the zip: the translators' glossary is
 * for whoever translates the plugin, not for the site running it.
 */
export const NOT_SHIPPED = ['languages/glossary.md'];

/** Paths the zip must contain, relative to its plugin directory. */
export const REQUIRED = [
	`${SLUG}.php`,
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'CHANGELOG.md',
	'vendor/autoload.php',
];

/** Paths the zip may contain, relative to its plugin directory. */
const ALLOWED = [
	new RegExp(`^${SLUG}\\.php$`),
	/^uninstall\.php$/,
	/^readme\.txt$/,
	/^LICENSE$/,
	/^CHANGELOG\.md$/,
	/^src\/.+\.php$/,
	/^templates\/.+\.php$/,
	/^assets\/build\/.+\.(js|css|asset\.php|svg|png|woff2?)$/,
	/^assets\/images\/.+\.svg$/,
	/^languages\/[^/]+\.(pot|po|mo|json|l10n\.php)$/,
	/^vendor\/.+$/,
];

/**
 * Never shipped, even under an allowed directory: dotfiles, TypeScript and
 * its declarations, source maps, tests, Composer binaries, Strauss's
 * development aliases, and the Pixonaut SDK, which loads from its CDN only.
 */
const FORBIDDEN = [
	{ pattern: /(^|\/)\./, reason: 'dotfile' },
	{ pattern: /\.(d\.ts|tsx?|map)$/, reason: 'source or declaration file' },
	{ pattern: /(^|\/)tests?\//, reason: 'tests' },
	{ pattern: /^vendor\/bin\//, reason: 'Composer binary' },
	{
		// Strauss writes it for development only.
		pattern: /^vendor\/composer\/autoload_aliases\.php$/,
		reason: 'Strauss development aliases',
	},
	{
		// pixonaut.iife.js, its glue pixonaut_wasm.js, and any WebAssembly.
		pattern: /(^|\/)pixonaut[\w.-]*\.m?js$|\.wasm$/,
		reason: 'Pixonaut SDK',
	},
];

/**
 * `vendor/<vendor>/<package>/` of each development-only Composer package.
 *
 * @param {{ 'packages-dev'?: { name: string }[] }} composerLock
 */
export function devPackagePrefixes(composerLock) {
	return (composerLock['packages-dev'] ?? []).map(
		({ name }) => `vendor/${name}/`,
	);
}

/**
 * The problems with a zip's file list, one line each; empty when it holds
 * exactly what may ship. Directory entries (ending in `/`) are ignored.
 *
 * @param {string[]} entries     Paths as stored in the zip.
 * @param {string[]} devPackages Prefixes from devPackagePrefixes().
 * @param {string}   flavor      The zip's flavor (flavor.mjs).
 * @returns {string[]}
 */
export function zipProblems(
	entries,
	devPackages = [],
	flavor = DEFAULT_FLAVOR,
) {
	const problems = [];
	const files = new Set();
	for (const entry of entries.filter((path) => !path.endsWith('/'))) {
		if (!entry.startsWith(ROOT_DIR) || posix.normalize(entry) !== entry) {
			problems.push(`${entry}: outside ${ROOT_DIR}`);
			continue;
		}
		const path = entry.slice(ROOT_DIR.length);
		files.add(path);
		const forbidden = FORBIDDEN.find(({ pattern }) => pattern.test(path));
		if (forbidden !== undefined) {
			problems.push(`${entry}: ${forbidden.reason}`);
		} else if (devPackages.some((prefix) => path.startsWith(prefix))) {
			problems.push(`${entry}: development-only Composer package`);
		} else if (!ALLOWED.some((pattern) => pattern.test(path))) {
			problems.push(`${entry}: not in the allowlist`);
		}
	}
	// Each shipped PO file needs what WordPress loads: its `.mo` and
	// `.l10n.php` file.
	const compiled = [...files]
		.filter((path) => posix.dirname(path) === LANGUAGES_DIR)
		.flatMap((path) => compiledNames(posix.basename(path)))
		.map((name) => `${LANGUAGES_DIR}/${name}`);
	for (const path of [...REQUIRED, ...compiled].filter(
		(required) => !files.has(required),
	)) {
		problems.push(`${ROOT_DIR}${path}: missing`);
	}
	problems.push(...flavorProblems(files, flavor, ROOT_DIR));
	return problems;
}

/**
 * The paths stored in a zip file, without extracting it.
 *
 * @param {string} zipPath
 */
export function zipEntries(zipPath) {
	/** @type {string[]} */
	const entries = [];
	unzipSync(readFileSync(zipPath), {
		filter: ({ name }) => {
			entries.push(name);
			return false;
		},
	});
	return entries;
}

/**
 * zipProblems() for a zip file, with the dev packages of a composer.lock.
 *
 * @param {string} zipPath
 * @param {string} composerLockPath
 * @param {string} [flavor]
 */
export function zipFileProblems(
	zipPath,
	composerLockPath,
	flavor = DEFAULT_FLAVOR,
) {
	const lock = JSON.parse(readFileSync(composerLockPath, 'utf8'));
	return zipProblems(zipEntries(zipPath), devPackagePrefixes(lock), flavor);
}
