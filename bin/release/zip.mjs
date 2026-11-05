#!/usr/bin/env node
// Builds the release zip from the allowlist and verifies what it built:
//
//   dist/pixonaut-for-woocommerce-<version>.zip
//   dist/pixonaut-for-woocommerce-<version>.zip.sha256
//
// `--flavor wporg` builds the wordpress.org flavor instead (flavor.mjs), as
// dist/pixonaut-for-woocommerce-<version>-wporg.zip.
//
// The zip holds the built assets, Composer's production autoloader (with
// the update checker prefixed by Strauss) and a generated readme.txt.
// Verification lists the zip against the allowlist, then lints the unpacked
// files with the plugin's minimum PHP and runs Plugin Check on them. Needs
// Docker, the checkout's Composer dev install (for Strauss), and wp-env
// running for Plugin Check.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
	cpSync,
	mkdirSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { unzipSync } from 'fflate';
import { composerInDocker, root, SLUG } from '../env.mjs';
import { header } from '../plugin-header.mjs';
import {
	NOT_SHIPPED,
	ROOT_DIR,
	SOURCES,
	zipFileProblems,
} from './allowlist.mjs';
import { makeZip } from './archive.mjs';
import {
	DEFAULT_FLAVOR,
	flavorFromArgs,
	stripUpdateUri,
	UPDATER_SOURCES,
	wporgComposerJson,
	zipName,
} from './flavor.mjs';
import { buildReadme } from './readme.mjs';

const DIST = join(root, 'dist');
/** Working directories, removed once the zip passes; kept for a look when it fails. */
const STAGING = join(DIST, 'stage');
const UNPACKED = join(DIST, 'unpacked');
const STAGE = join(STAGING, SLUG);
const MAIN_FILE = `${SLUG}.php`;
const COMPOSER_FILES = ['composer.json', 'composer.lock'];
/** Where the checkout is mounted while Strauss, from its dev install, runs in the staging directory. */
const CHECKOUT_MOUNT = '/checkout';
/** Written by Strauss for development only. */
const STRAUSS_ALIASES = 'vendor/composer/autoload_aliases.php';

/** @param {string} name */
function step(name) {
	console.log(`\n=== ${name}`);
}

/**
 * @param {string} message
 * @returns {never}
 */
function fail(message) {
	console.error(`release:zip: ${message}`);
	process.exit(1);
}

/**
 * @param {string} command
 * @param {string[]} args
 */
function mustRun(command, args) {
	const { status, error } = spawnSync(command, args, {
		cwd: root,
		stdio: 'inherit',
	});
	if (error !== undefined || status !== 0) {
		fail(`${command} ${args.join(' ')} failed${error ? `: ${error}` : ''}`);
	}
}

/**
 * Every file under `dir`, as paths relative to it, sorted.
 *
 * @param {string} dir
 */
function filesUnder(dir) {
	return readdirSync(dir, { recursive: true, withFileTypes: true })
		.filter((entry) => entry.isFile())
		.map((entry) => relative(dir, join(entry.parentPath, entry.name)))
		.sort();
}

const plugin = readFileSync(join(root, MAIN_FILE), 'utf8');
const version = header(plugin, 'Version');
const minimumPhp = header(plugin, 'Requires PHP');
if (version === undefined || minimumPhp === undefined) {
	fail(`${MAIN_FILE} has no Version or Requires PHP header.`);
}
const flavor = flavorFromArgs(process.argv.slice(2));
const zipFile = zipName(version, flavor);
const zipPath = join(DIST, zipFile);

step('Build assets');
mustRun('pnpm', ['build']);

step('Compile translations');
mustRun('node', ['bin/i18n-build.mjs']);

step(`Stage ${relative(root, STAGE)}`);
rmSync(DIST, { recursive: true, force: true });
mkdirSync(STAGE, { recursive: true });
for (const source of [...SOURCES, ...COMPOSER_FILES]) {
	cpSync(join(root, source), join(STAGE, source), {
		recursive: true,
		filter: (path) => !NOT_SHIPPED.includes(relative(root, path)),
	});
}

if (flavor !== DEFAULT_FLAVOR) {
	step(`Strip the update checker (${flavor})`);
	for (const source of UPDATER_SOURCES) {
		rmSync(join(STAGE, source));
	}
	writeFileSync(join(STAGE, MAIN_FILE), stripUpdateUri(plugin));
	const composer = JSON.parse(
		readFileSync(join(STAGE, 'composer.json'), 'utf8'),
	);
	writeFileSync(
		join(STAGE, 'composer.json'),
		`${JSON.stringify(wporgComposerJson(composer), null, '\t')}\n`,
	);
	// Without the update checker nothing is left to lock: Composer resolves
	// the PHP requirement alone.
	rmSync(join(STAGE, 'composer.lock'));
}

step('Composer production install');
// No autoloader yet: Strauss loads the staging directory's autoloader after
// the checkout's, and both carry the same class suffix (derived from the same
// composer.lock). The dump after Strauss writes it.
mustRun('docker', [
	...composerInDocker(STAGE),
	'install',
	'--no-dev',
	'--no-scripts',
	'--no-autoloader',
	'--no-interaction',
	'--no-progress',
]);

if (flavor === DEFAULT_FLAVOR) {
	step('Prefix the update checker (Strauss)');
	// The staging directory's vendor/ holds production packages only, so
	// Strauss runs from the checkout's dev install, through the same wrapper
	// Composer runs after every install.
	mustRun('docker', [
		...composerInDocker(STAGE, { [CHECKOUT_MOUNT]: root }),
		'php',
		`${CHECKOUT_MOUNT}/bin/strauss.php`,
	]);
	rmSync(join(STAGE, STRAUSS_ALIASES), { force: true });
}

step('Composer production autoloader');
mustRun('docker', [
	...composerInDocker(STAGE),
	'dump-autoload',
	'--no-dev',
	'--classmap-authoritative',
	'--no-interaction',
]);
for (const file of COMPOSER_FILES) {
	rmSync(join(STAGE, file), { force: true });
}

step('readme.txt');
writeFileSync(
	join(STAGE, 'readme.txt'),
	buildReadme({
		source: readFileSync(join(root, 'readme.src.txt'), 'utf8'),
		plugin,
		changelog: readFileSync(join(root, 'CHANGELOG.md'), 'utf8'),
	}),
);

step(`Zip ${relative(root, zipPath)}`);
const zip = makeZip(
	Object.fromEntries(
		filesUnder(STAGE).map((file) => [
			`${ROOT_DIR}${file}`,
			readFileSync(join(STAGE, file)),
		]),
	),
);
writeFileSync(zipPath, zip);
const sha256 = createHash('sha256').update(zip).digest('hex');
writeFileSync(`${zipPath}.sha256`, `${sha256}  ${zipFile}\n`);
console.log(`sha256 ${sha256}`);

step('Allowlist');
const problems = zipFileProblems(zipPath, join(root, 'composer.lock'), flavor);
if (problems.length > 0) {
	fail(`the zip holds files it must not:\n${problems.join('\n')}`);
}
console.log('Only allowlisted files.');

step('Unpack');
for (const [name, data] of Object.entries(unzipSync(zip))) {
	if (!name.endsWith('/')) {
		mkdirSync(dirname(join(UNPACKED, name)), { recursive: true });
		writeFileSync(join(UNPACKED, name), data);
	}
}
const unpackedPlugin = join(UNPACKED, SLUG);

step(`PHP ${minimumPhp} lint`);
mustRun('docker', [
	'run',
	'--rm',
	'-v',
	`${unpackedPlugin}:/app:ro`,
	'-w',
	'/app',
	`php:${minimumPhp}-cli`,
	'sh',
	'-c',
	// `php -l` prints a line per file; only failures are worth showing.
	'status=0; for f in $(find . -name "*.php"); do php -l "$f" > /dev/null || status=1; done; exit $status',
]);
console.log('No syntax errors.');

step('Plugin Check');
mustRun('node', [
	'bin/plugin-check.mjs',
	'--dir',
	unpackedPlugin,
	'--flavor',
	flavor,
]);

for (const dir of [STAGING, UNPACKED]) {
	rmSync(dir, { recursive: true, force: true });
}

console.log(`\nrelease:zip: ${relative(root, zipPath)} built and verified.`);
