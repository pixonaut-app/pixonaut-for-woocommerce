// The release zip comes in two flavors:
//
//   github  (default) updates itself from the repository's GitHub Releases
//           through plugin-update-checker, prefixed by Strauss.
//   wporg   for wordpress.org, which updates plugins itself: no update
//           checker, no `Update URI` header.
import { SLUG } from '../env.mjs';

export const FLAVORS = ['github', 'wporg'];

export const DEFAULT_FLAVOR = 'github';

/** The update checker library, prefixed in place under vendor/ by Strauss. */
export const UPDATER_PACKAGE = 'yahnis-elsts/plugin-update-checker';

/** Plugin sources only the github flavor ships: they drive the update checker. */
export const UPDATER_SOURCES = [
	'src/Updates/GitHubUpdater.php',
	'src/Updates/GitHubReleases.php',
];

/** A file the github flavor's zip must hold: the update checker's entry point. */
const UPDATER_ENTRY = `vendor/${UPDATER_PACKAGE}/plugin-update-checker.php`;

const UPDATE_URI_LINE = /^[ \t]*\*[ \t]*Update URI:.*\r?\n/m;

/**
 * The flavor named by `--flavor <name>` or `--flavor=<name>`, else the default.
 *
 * @param {string[]} args Command-line arguments.
 * @returns {string}
 */
export function flavorFromArgs(args) {
	const index = args.findIndex(
		(arg) => arg === '--flavor' || arg.startsWith('--flavor='),
	);
	if (index === -1) {
		return DEFAULT_FLAVOR;
	}
	const flavor = args[index].includes('=')
		? args[index].slice('--flavor='.length)
		: args[index + 1];
	if (!FLAVORS.includes(flavor)) {
		throw new Error(
			`Unknown flavor "${flavor}": use one of ${FLAVORS.join(', ')}.`,
		);
	}
	return flavor;
}

/**
 * The zip's file name. Only the github flavor's matches the asset pattern
 * the update checker downloads (`GitHubUpdater::ASSET_PATTERN`).
 *
 * @param {string} version
 * @param {string} flavor
 */
export function zipName(version, flavor) {
	return flavor === DEFAULT_FLAVOR
		? `${SLUG}-${version}.zip`
		: `${SLUG}-${version}-${flavor}.zip`;
}

/**
 * The main plugin file without its `Update URI` header line.
 *
 * @param {string} plugin The main plugin file's source.
 */
export function stripUpdateUri(plugin) {
	if (!UPDATE_URI_LINE.test(plugin)) {
		throw new Error('The plugin header has no Update URI line to strip.');
	}
	return plugin.replace(UPDATE_URI_LINE, '');
}

/**
 * composer.json for the wporg flavor: without the update checker and the
 * autoload entries into it, and without development packages, so with no
 * lock file Composer has nothing to resolve. (The build installs with
 * `--no-scripts`, so Strauss does not run.)
 *
 * @param {Record<string, any>} composer The parsed composer.json.
 * @returns {Record<string, any>}
 */
export function wporgComposerJson(composer) {
	const { 'require-dev': _dev, ...production } = composer;
	const { [UPDATER_PACKAGE]: _updater, ...require } = composer.require ?? {};
	const { classmap = [], ...autoload } = composer.autoload ?? {};
	const kept = classmap.filter(
		(/** @type {string} */ path) =>
			!path.startsWith(`vendor/${UPDATER_PACKAGE}/`),
	);
	return {
		...production,
		require,
		autoload: kept.length > 0 ? { ...autoload, classmap: kept } : autoload,
	};
}

/**
 * The problems with a zip's file list for a flavor, one line each: the
 * github flavor must ship the update checker, the wporg flavor must not.
 *
 * @param {Set<string>} files Paths relative to the plugin directory.
 * @param {string} flavor
 * @param {string} rootDir The plugin directory's prefix in the zip.
 * @returns {string[]}
 */
export function flavorProblems(files, flavor, rootDir) {
	const updaterFiles = [UPDATER_ENTRY, ...UPDATER_SOURCES];
	if (flavor === DEFAULT_FLAVOR) {
		return updaterFiles
			.filter((path) => !files.has(path))
			.map((path) => `${rootDir}${path}: missing`);
	}
	return [...files]
		.filter(
			(path) =>
				path.startsWith(`vendor/${UPDATER_PACKAGE}/`) ||
				UPDATER_SOURCES.includes(path),
		)
		.map((path) => `${rootDir}${path}: update checker in the ${flavor} flavor`);
}
