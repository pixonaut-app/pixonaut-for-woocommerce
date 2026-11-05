#!/usr/bin/env node
// Runs Plugin Check (the plugin-check plugin pinned in .wp-env.json) on the
// working tree in wp-env's tests site. Files in .distignore are skipped, so
// it checks what the release zip ships. Exits 1 on any error; warnings are
// printed and do not fail.
//
//   plugin-check.mjs               the working tree
//   plugin-check.mjs --dir <path>  an unpacked release instead, every file
//   ... --flavor wporg             the release is the wordpress.org flavor,
//                                  which must hold no update checker
import {
	copyFileSync,
	cpSync,
	existsSync,
	readdirSync,
	readFileSync,
	rmSync,
	statSync,
} from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { root, SLUG, wpEnvOutput } from './env.mjs';
import { DEFAULT_FLAVOR, flavorFromArgs } from './release/flavor.mjs';

/**
 * Runtime checks install a second WordPress inside the tests site, which
 * fails in wp-env. They cover enqueued asset size and scope, which the e2e
 * suite exercises on real pages instead.
 */
const RUNTIME_CHECKS = [
	'enqueued_scripts_scope',
	'enqueued_styles_scope',
	'enqueued_scripts_size',
	'enqueued_styles_size',
	'non_blocking_scripts',
];

/**
 * Result codes that are expected for this plugin.
 * - WordPress.Security.EscapeOutput.ExceptionNotEscaped: exception messages
 *   are not output; whoever prints one escapes it (as in phpcs.xml.dist).
 */
const IGNORED_CODES = ['WordPress.Security.EscapeOutput.ExceptionNotEscaped'];

/**
 * The github flavor updates from GitHub: its `Update URI` header and the
 * update checker are expected. The wordpress.org flavor drops both, and
 * this check proves it.
 */
const UPDATER_CODE = 'plugin_updater_detected';

/**
 * readme.txt is generated into the release zip only, so the working tree
 * has none.
 */
const MISSING_README_CODE = 'no_plugin_readme';

/**
 * The tests site's plugin directory an unpacked release is copied to; the
 * working tree is mounted under the checkout's own directory name.
 */
const RELEASE_PLUGIN_DIR = `${SLUG}-release`;

function distIgnored() {
	return readFileSync(join(root, '.distignore'), 'utf8')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '' && !line.startsWith('#'));
}

function isDirectory(path) {
	return (
		existsSync(join(root, path)) && statSync(join(root, path)).isDirectory()
	);
}

/**
 * wp-env's working directory for this checkout: the one under WP_ENV_HOME
 * whose docker-compose.yml mounts this checkout. (`wp-env install-path`
 * prints nothing when its output is not a terminal.)
 */
function wpEnvInstallPath() {
	const home = resolve(root, process.env.WP_ENV_HOME ?? './.wp-env-home');
	const match = readdirSync(home)
		.map((entry) => join(home, entry))
		.find((dir) => {
			const compose = join(dir, 'docker-compose.yml');
			return (
				existsSync(compose) && readFileSync(compose, 'utf8').includes(root)
			);
		});
	if (match === undefined) {
		throw new Error(
			`No wp-env install for ${root} in ${home}: run pnpm env:start.`,
		);
	}
	return match;
}

/**
 * Plugin Check copies an object-cache.php drop-in into wp-content before it
 * runs. With rootless Docker the container user cannot write there but the
 * host can, so the drop-in is placed from here and removed afterwards.
 */
function placeDropIn(installPath) {
	const target = join(
		installPath,
		'tests-WordPress',
		'wp-content',
		'object-cache.php',
	);
	if (existsSync(target)) {
		return () => {};
	}
	copyFileSync(
		join(installPath, 'plugin-check', 'drop-ins', 'object-cache.copy.php'),
		target,
	);
	return () => rmSync(target, { force: true });
}

/**
 * Places an unpacked release in the tests site from the host, which can
 * write there, and returns the function that removes it again.
 */
function placeRelease(installPath, dir) {
	const target = join(
		installPath,
		'tests-WordPress',
		'wp-content',
		'plugins',
		RELEASE_PLUGIN_DIR,
	);
	rmSync(target, { recursive: true, force: true });
	cpSync(dir, target, { recursive: true });
	return () => rmSync(target, { recursive: true, force: true });
}

/** The plugin directory to check and the arguments that scope the check. */
function target(installPath, releaseDir, flavor) {
	const flavorCodes =
		flavor === DEFAULT_FLAVOR
			? [...IGNORED_CODES, UPDATER_CODE]
			: IGNORED_CODES;
	if (releaseDir !== undefined) {
		return {
			plugin: RELEASE_PLUGIN_DIR,
			args: [`--ignore-codes=${flavorCodes.join(',')}`],
			remove: placeRelease(installPath, releaseDir),
		};
	}
	const ignored = distIgnored();
	const ignoredCodes = existsSync(join(root, 'readme.txt'))
		? flavorCodes
		: [...flavorCodes, MISSING_README_CODE];
	return {
		plugin: basename(root),
		args: [
			`--ignore-codes=${ignoredCodes.join(',')}`,
			`--exclude-directories=${ignored.filter(isDirectory).join(',')}`,
			`--exclude-files=${ignored.filter((path) => !isDirectory(path)).join(',')}`,
		],
		remove: () => {},
	};
}

const dirFlag = process.argv.indexOf('--dir');
const releaseDir =
	dirFlag === -1 ? undefined : resolve(process.argv[dirFlag + 1] ?? '');

const installPath = wpEnvInstallPath();
const { plugin, args, remove } = target(
	installPath,
	releaseDir,
	flavorFromArgs(process.argv.slice(2)),
);
const removeDropIn = placeDropIn(installPath);
let result;
try {
	result = wpEnvOutput([
		'run',
		'tests-cli',
		'wp',
		'plugin',
		'check',
		plugin,
		`--slug=${SLUG}`,
		`--exclude-checks=${RUNTIME_CHECKS.join(',')}`,
		...args,
		'--format=json',
	]);
} finally {
	removeDropIn();
	remove();
}
if (result.status !== 0) {
	console.error('Plugin Check did not run.');
	process.exit(result.status || 1);
}

// The JSON format prints a `FILE: <path>` line, then that file's findings as
// one JSON array.
const findings = [];
let file = '';
for (const line of result.stdout.split('\n')) {
	if (line.startsWith('FILE: ')) {
		file = line.slice('FILE: '.length);
	} else if (line.startsWith('[')) {
		for (const finding of JSON.parse(line)) {
			findings.push({ file, ...finding });
		}
	}
}

for (const { type, file: path, line, code, message } of findings) {
	console.log(
		`${type.padEnd('WARNING'.length)}  ${path}:${line}  ${code}  ${message}`,
	);
}
const errors = findings.filter(({ type }) => type === 'ERROR').length;
console.log(
	`Plugin Check: ${errors} errors, ${findings.length - errors} warnings.`,
);
process.exit(errors > 0 ? 1 : 0);
