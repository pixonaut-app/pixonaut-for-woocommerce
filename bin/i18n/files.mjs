// The plugin's translation files, and the tools run on them in the Composer
// container: WP-CLI's i18n commands and gettext's msgmerge. wp-env's tests-cli
// cannot write to the checkout.
import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import {
	COMPOSER_IMAGE,
	composerInDocker,
	dockerBin,
	hostUser,
	root,
	SLUG,
	spawn,
} from '../env.mjs';

export const LANGUAGES_DIR = 'languages';
export const POT_FILE = join(LANGUAGES_DIR, `${SLUG}.pot`);
const PO_FILE = new RegExp(`^${SLUG}-(.+)\\.po$`);
/** What `i18n:build` writes per locale: the `.mo` and the `.l10n.php` file. */
const COMPILED_FILE = new RegExp(`^${SLUG}-.+\\.(?:mo|l10n\\.php)$`);

/**
 * The file names `i18n:build` compiles a PO file named `name` to, or an empty
 * list when `name` is not one of the plugin's PO files.
 *
 * @param {string} name
 */
export function compiledNames(name) {
	const locale = name.match(PO_FILE)?.[1];
	return locale === undefined
		? []
		: [`${SLUG}-${locale}.mo`, `${SLUG}-${locale}.l10n.php`];
}

/**
 * Whether `name` is a translation file `i18n:build` compiles, so a build can
 * remove those of locales whose PO file is gone.
 *
 * @param {string} name
 */
export function isCompiled(name) {
	return COMPILED_FILE.test(name);
}

/**
 * The compiled translation files in `dir`.
 *
 * @param {string} dir
 */
export function compiledFiles(dir) {
	return readdirSync(dir)
		.filter(isCompiled)
		.map((name) => join(dir, name));
}

/**
 * The locale and path of each PO file in `dir`.
 *
 * @param {string} dir
 */
export function poFiles(dir) {
	return readdirSync(dir)
		.map((name) => ({ name, match: name.match(PO_FILE) }))
		.filter(({ match }) => match !== null)
		.map(({ name, match }) => ({
			locale: /** @type {RegExpMatchArray} */ (match)[1],
			path: join(dir, name),
		}))
		.sort((a, b) => a.locale.localeCompare(b.locale));
}

/**
 * Runs `wp i18n <args>` from the checkout's Composer dev install, as root in
 * the container (Docker here is rootless, so files keep the host user).
 * Deprecation notices from WP-CLI's dependencies on the image's newer PHP are
 * silenced.
 *
 * @param {string[]} args
 */
export function wpI18n(args) {
	return spawn(dockerBin, [
		...composerInDocker(root),
		'php',
		'-d',
		'error_reporting=E_ALL & ~E_DEPRECATED',
		// WP-CLI's PHP entry point; `vendor/bin/wp` is a shell script.
		'vendor/wp-cli/wp-cli/php/boot-fs.php',
		'--allow-root',
		'i18n',
		...args,
	]);
}

/**
 * Merges the template into each PO file with GNU gettext's `msgmerge`, which,
 * unlike `wp i18n update-po`, keeps the translation of a string whose English
 * changed, marked fuzzy, with the previous English beside it. The Composer
 * image has no gettext, so the container installs it first, which needs the
 * network. It runs as root to install it; on rootful Docker it then gives the
 * merged files back to the host user.
 *
 * @param {string} pot The template, relative to the checkout.
 * @param {string[]} pos The PO files, relative to the checkout.
 */
export function msgmerge(pot, pos) {
	const owner = hostUser();
	const script = [
		'set -e',
		'apk add --quiet --no-cache gettext',
		'pot=$1',
		'shift',
		'for po in "$@"; do',
		'  msgmerge --quiet --update --backup=none --previous "$po" "$pot"',
		'  if [ -n "$HOST_USER" ]; then chown "$HOST_USER" "$po"; fi',
		'done',
	].join('\n');
	return spawn(dockerBin, [
		'run',
		'--rm',
		'-v',
		`${root}:/app`,
		'-w',
		'/app',
		'-e',
		`HOST_USER=${owner ?? ''}`,
		COMPOSER_IMAGE,
		// The image's entrypoint runs `sh` as given, not through Composer.
		'sh',
		'-c',
		script,
		'sh',
		pot,
		...pos,
	]);
}

/** Exits with a note when there is nothing to translate yet. */
export function requirePoFiles() {
	if (poFiles(join(root, LANGUAGES_DIR)).length === 0) {
		console.log(`No ${SLUG}-<locale>.po files in ${LANGUAGES_DIR}/.`);
		process.exit(0);
	}
}
