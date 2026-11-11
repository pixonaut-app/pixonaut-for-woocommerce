#!/usr/bin/env node
// The local merge gate. Runs every step, reports each, and exits 1 if any
// failed. A full pass on a clean tree is recorded for attestation
// (bin/ci-local-attest.mjs).
//
//   ci-local.mjs              every step
//   ci-local.mjs --affected   only the steps the branch's changes touch,
//                             compared with CI_LOCAL_BASE (default
//                             origin/main), and only the e2e specs they
//                             reach (ci-local-e2e.mjs); never attests
//   ci-local.mjs --dry-run    print the steps that would run, run nothing
//
// Steps marked `wpEnv` need wp-env running (pnpm env:start).
//
// With more than one e2e site (bin/e2e-sites.mjs), the e2e step runs on those
// sites in the background, beside the other steps, which keep the checkout's
// own wp-env to themselves; its output is printed when the other steps are
// done. A few specs run on the checkout's tests site after the other steps
// instead, as before: starting the sites would take longer than they do.
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, openSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { affectedSpecs, SPECS_DIR } from './ci-local-e2e.mjs';
import { plannedSites, SITES_DIR } from './e2e-sites.mjs';
import { root, wpEnvOutput } from './env.mjs';

const BASE = process.env.CI_LOCAL_BASE ?? 'origin/main';
const MS_PER_SECOND = 1000;

/** The fake Pixonaut server the e2e suite will run against. */
const FAKE_DIR = join('tests', 'fake-pixonaut');

const PHP = /\.php$/;
const PHP_SOURCE =
	/^(src\/|templates\/|uninstall\.php$|pixonaut-for-woocommerce\.php$)/;
const PHP_TESTS = /^tests\/php\//;
/**
 * What the release zip is built from, where an edit can change whether it
 * builds: headers, readme, translations, the build and release tooling.
 */
const RELEASE_ZIP_SOURCES = [
	/^(pixonaut-for-woocommerce|uninstall)\.php$/,
	/^languages\//,
	/^(LICENSE|CHANGELOG\.md|readme\.src\.txt|webpack\.config\.js)$/,
	/^bin\/(release\/|plugin-check\.mjs$|plugin-header\.mjs$)/,
	// The zip carries the compiled translations.
	/^bin\/i18n(-build\.mjs$|\/(files|po)\.mjs$)/,
];
/**
 * Shipped code whose edits the zip cannot fail on: PHPCS and PHPStan check it
 * against the minimum PHP, and Plugin Check runs on the working tree. Adding,
 * removing or renaming a file still selects the zip, for its allowlist.
 */
const RELEASE_ZIP_SHAPE = [/^src\//, /^templates\//, /^assets\//];
const COMPOSER = /^composer\.(json|lock)$/;
/** The pinned Pixonaut contract and `consumed.json`. */
const CONTRACT = /^contracts\/pixonaut\//;

/**
 * A change to any of these can affect every step: dependencies, the wp-env
 * setup, and the wrappers most steps run through.
 */
const GLOBAL =
	/^(package\.json|pnpm-lock\.yaml|composer\.(json|lock)|\.wp-env\.json|bin\/(env|php|wp-env|wp-env-override|ci-local)\.mjs)$/;

const E2E_STEP = 'e2e (classic, block-primary, astra)';
const BUILD_STEP = 'Build assets and translations';

/** Fewer spec files than this run on the checkout's own tests site: the e2e
 *  sites take a minute or two to start. */
const MIN_SPEC_FILES_FOR_SITES = 8;

/** Where the e2e step's output goes while it runs beside the other steps. */
const E2E_LOG = join(SITES_DIR, 'ci-local-e2e.log');

/** What the e2e step runs on; ci-local-e2e.mjs then picks its specs. */
const E2E_AFFECTED_BY = [
	PHP_SOURCE,
	/^assets\/src\//,
	/^tests\/e2e\//,
	/^tests\/fake-pixonaut\/src\/fixture-ids\.ts$/,
	/^playwright\.config\.ts$/,
	/^contracts\/pixonaut\/sdk\.lock\.json$/,
	/^bin\/(e2e-sites|fake-pixonaut)\.mjs$/,
];

const STEPS = [
	{
		// The tests sites serve assets/build/. Built first, so the e2e step
		// never runs on a stale build, and once: the release zips take it as
		// it is (--prebuilt), since a rebuild would clear it under the e2e
		// step running beside them.
		name: BUILD_STEP,
		command: ['sh', '-c', 'pnpm build && node bin/i18n-build.mjs'],
		affectedBy: E2E_AFFECTED_BY,
	},
	{
		name: 'Conflict markers',
		command: ['node', 'bin/check-conflict-markers.mjs'],
		// Any tracked file can hold a marker left by a merge.
		affectedBy: [/./],
	},
	{
		name: 'Biome',
		command: ['pnpm', 'lint:js'],
		affectedBy: [/\.(m|c)?[jt]sx?$/, /\.jsonc?$/, /\.css$/, /^biome\.json$/],
	},
	{
		name: 'TypeScript',
		command: ['pnpm', 'typecheck'],
		affectedBy: [/\.tsx?$/, /^tsconfig\.json$/, /^contracts\/pixonaut\/sdk\//],
	},
	{
		name: 'Workflow lint',
		command: ['pnpm', 'lint:workflows'],
		affectedBy: [/^\.github\//, /^bin\/actionlint\.mjs$/],
	},
	{
		name: 'Header minimums',
		command: ['node', 'bin/check-headers.mjs'],
		affectedBy: [
			/^pixonaut-for-woocommerce\.php$/,
			/^readme\.txt$/,
			/^src\/Requirements\.php$/,
			COMPOSER,
			/^bin\/(check-headers|plugin-header)\.mjs$/,
		],
	},
	{
		name: 'Docs links',
		command: ['pnpm', 'check:docs-links'],
		affectedBy: [/^docs\//, /^README\.md$/, /^bin\/docs\/links\.mjs$/],
	},
	{
		name: 'PHPCS',
		command: ['pnpm', 'lint:php'],
		wpEnv: true,
		affectedBy: [PHP, /^phpcs\.xml\.dist$/],
	},
	{
		name: 'PHPStan',
		command: ['pnpm', 'phpstan'],
		wpEnv: true,
		affectedBy: [PHP, /^phpstan\.neon\.dist$/],
	},
	{
		name: 'PHP unit',
		command: ['pnpm', 'test:unit'],
		wpEnv: true,
		// The suite holds every exchange to the pinned contract.
		affectedBy: [PHP_SOURCE, PHP_TESTS, CONTRACT, /^phpunit\.xml\.dist$/],
	},
	{
		name: 'JS unit',
		command: ['pnpm', 'test:js'],
		affectedBy: [
			/^assets\/src\//,
			/^tests\/js\//,
			/^tests\/e2e\/reach\.mjs$/,
			/^tests\/fake-pixonaut\/src\//,
			/^bin\//,
			/^contracts\//,
			/^vitest\.config\.ts$/,
		],
	},
	{
		name: '.pot freshness',
		command: ['node', 'bin/i18n-pot.mjs', '--check'],
		wpEnv: true,
		affectedBy: [
			PHP_SOURCE,
			/^assets\/src\//,
			/^languages\//,
			/^bin\/i18n-pot\.mjs$/,
		],
	},
	{
		name: 'Translations',
		command: ['node', 'bin/i18n-check.mjs'],
		affectedBy: [/^languages\//, /^bin\/i18n(-check\.mjs$|\/)/],
	},
	{
		name: 'consumed.json freshness',
		command: ['node', 'bin/contracts/consumed-generate.mjs', '--check'],
		wpEnv: true,
		affectedBy: [
			PHP_SOURCE,
			/^contracts\/pixonaut\/consumed\.json$/,
			/^bin\/contracts\/consumed/,
		],
	},
	{
		name: 'Generated docs',
		command: ['node', 'bin/docs-generate.mjs', '--check'],
		wpEnv: true,
		affectedBy: [
			PHP_SOURCE,
			/^docs\/developer\//,
			/^bin\/docs/,
			/^tests\/php\/Support\/(Hooks\/|ReferenceData\.php$)/,
		],
	},
	{
		name: 'Plugin Check',
		command: ['node', 'bin/plugin-check.mjs'],
		wpEnv: true,
		affectedBy: [
			PHP_SOURCE,
			/^assets\//,
			/^languages\//,
			/^readme\.txt$/,
			/^\.distignore$/,
			/^bin\/plugin-check\.mjs$/,
		],
	},
	{
		name: 'Release zip',
		command: ['pnpm', 'release:zip'],
		takesBuild: true,
		wpEnv: true,
		affectedBy: RELEASE_ZIP_SOURCES,
		affectedByShape: RELEASE_ZIP_SHAPE,
	},
	{
		// Also checks the wordpress.org listing assets against its readme.
		name: 'Release zip (wordpress.org)',
		command: ['pnpm', 'release:zip', '--flavor', 'wporg'],
		takesBuild: true,
		wpEnv: true,
		affectedBy: [...RELEASE_ZIP_SOURCES, /^\.wordpress-org\//],
		affectedByShape: RELEASE_ZIP_SHAPE,
	},
	{
		name: 'PHP integration (HPOS off, on)',
		command: ['pnpm', 'test:integration'],
		wpEnv: true,
		affectedBy: [
			PHP_SOURCE,
			PHP_TESTS,
			CONTRACT,
			/^phpunit-integration\.xml\.dist$/,
		],
	},
	{
		name: E2E_STEP,
		command: ['pnpm', 'test:e2e'],
		// On the e2e sites, beside the other steps.
		parallelCommand: ['pnpm', 'test:e2e:parallel'],
		wpEnv: true,
		notice: () =>
			existsSync(join(root, FAKE_DIR))
				? undefined
				: `No fake Pixonaut yet (${FAKE_DIR}): the e2e suite runs without it.`,
		affectedBy: E2E_AFFECTED_BY,
	},
];

function git(...args) {
	const result = spawnSync('git', args, { encoding: 'utf8' });
	if (result.status !== 0) {
		console.error(`git ${args.join(' ')} failed: ${result.stderr}`);
		process.exit(1);
	}
	return result.stdout.split('\n').filter((line) => line !== '');
}

/**
 * Files changed since the merge base with BASE, committed or not, and which
 * of them were added or removed (a rename is both).
 */
function changedFiles() {
	const [mergeBase] = git('merge-base', BASE, 'HEAD');
	const untracked = git('ls-files', '--others', '--exclude-standard');
	const changes = git('diff', '--name-status', '--no-renames', mergeBase).map(
		(line) => line.split('\t'),
	);
	return {
		files: [...changes.map(([, file]) => file), ...untracked],
		addedOrRemoved: [
			...changes
				.filter(([status]) => status === 'A' || status === 'D')
				.map(([, file]) => file),
			...untracked,
		],
	};
}

/** @param {RegExp[]} patterns */
function anyMatch(files, patterns) {
	return files.some((file) => patterns.some((pattern) => pattern.test(file)));
}

async function selectSteps(affected) {
	if (!affected) {
		return STEPS;
	}
	const { files, addedOrRemoved } = changedFiles();
	if (files.some((file) => GLOBAL.test(file))) {
		return STEPS;
	}
	const steps = STEPS.filter(
		({ affectedBy, affectedByShape = [] }) =>
			anyMatch(files, affectedBy) || anyMatch(addedOrRemoved, affectedByShape),
	);
	const e2e = steps.find(({ name }) => name === E2E_STEP);
	if (e2e === undefined) {
		return steps;
	}
	const { specs, everything } = await affectedSpecs(files);
	if (everything !== undefined) {
		return steps.map((step) =>
			step === e2e
				? { ...e2e, notice: () => `Every spec runs: ${everything}.` }
				: step,
		);
	}
	if (specs.length === 0) {
		return steps.filter((step) => step !== e2e && step.name !== BUILD_STEP);
	}
	return steps.map((step) => (step === e2e ? { ...e2e, specs } : step));
}

/**
 * The commands the chosen steps run: the e2e step's on the e2e sites in the
 * background, right after the build, when there are several and enough
 * specs to share, and limited to its specs when only some were chosen; the
 * release zips on the build the build step made, when it runs.
 */
function arrange(steps, sites = plannedSites().length) {
	const built = steps.some(({ name }) => name === BUILD_STEP);
	const arranged = steps.map((step) => {
		if (step.takesBuild && built) {
			return { ...step, command: [...step.command, '--prebuilt'] };
		}
		if (step.name !== E2E_STEP) {
			return step;
		}
		const background =
			sites > 1 &&
			(step.specs === undefined ||
				step.specs.length >= MIN_SPEC_FILES_FOR_SITES);
		return {
			...step,
			background,
			command: [
				...(background ? step.parallelCommand : step.command),
				...(step.specs ?? []).map((spec) => `${SPECS_DIR}/${spec}`),
			],
		};
	});
	// Started in the background as soon as the build is done, so it runs
	// beside every other step rather than after them.
	const e2e = arranged.find(({ background }) => background);
	if (e2e === undefined) {
		return arranged;
	}
	const rest = arranged.filter((step) => step !== e2e);
	const build = rest.findIndex(({ name }) => name === BUILD_STEP);
	return [...rest.slice(0, build + 1), e2e, ...rest.slice(build + 1)];
}

/**
 * Runs `command` and resolves with whether it passed and how long it took.
 * Without `log` it writes to this terminal.
 */
function run(command, log) {
	const started = Date.now();
	const [bin, ...args] = command;
	const stdio = log === undefined ? 'inherit' : ['ignore', log, log];
	return new Promise((resolve) => {
		const child = spawn(bin, args, { cwd: root, stdio });
		const done = (passed) =>
			resolve({
				passed,
				seconds: Math.round((Date.now() - started) / MS_PER_SECOND),
			});
		child.on('error', () => done(false));
		child.on('close', (code) => done(code === 0));
	});
}

function wpEnvRunning() {
	return wpEnvOutput(['run', 'tests-cli', 'true']).status === 0;
}

const affected = process.argv.includes('--affected');
const steps = arrange(await selectSteps(affected));

if (process.argv.includes('--dry-run')) {
	for (const { name, specs = [], background } of steps) {
		console.log(
			background
				? `${name} (on ${plannedSites().length} e2e sites, beside the other steps)`
				: name,
		);
		for (const spec of specs) {
			console.log(`  ${spec}`);
		}
	}
	process.exit(0);
}
if (steps.length === 0) {
	console.log(`ci:local: nothing to run, no changes since ${BASE}.`);
	process.exit(0);
}
if (steps.some(({ wpEnv }) => wpEnv) && !wpEnvRunning()) {
	console.error(
		'ci:local: wp-env is not running. Start it with pnpm env:start.',
	);
	process.exit(1);
}

// Attestation covers the tree every step ran on, so it needs a clean tree
// at the start; `record` checks that HEAD still has it at the end.
const startTree =
	git('status', '--porcelain').length === 0
		? git('rev-parse', 'HEAD^{tree}')[0]
		: undefined;

const gateStarted = Date.now();
/** @type {Map<string, { passed: boolean, seconds: number }>} */
const results = new Map();
let background;
for (const { name, command, notice, background: inBackground } of steps) {
	console.log(`\n=== ${name}: ${command.join(' ')}`);
	const message = notice?.();
	if (message !== undefined) {
		console.log(message);
	}
	if (results.get(BUILD_STEP)?.passed === false && name === E2E_STEP) {
		console.log('Not run: the build failed.');
		results.set(name, { passed: false, seconds: 0 });
	} else if (inBackground) {
		mkdirSync(SITES_DIR, { recursive: true });
		console.log(
			`Runs beside the next steps; its output follows them (${E2E_LOG}).`,
		);
		background = { name, done: run(command, openSync(E2E_LOG, 'w')) };
	} else {
		results.set(name, await run(command));
	}
}
if (background !== undefined) {
	const result = await background.done;
	console.log(`\n=== ${background.name}: output`);
	console.log(readFileSync(E2E_LOG, 'utf8'));
	results.set(background.name, result);
}

console.log(`\n=== ci:local${affected ? ' --affected' : ''}`);
for (const [name, { passed, seconds }] of results) {
	console.log(`${passed ? 'pass' : 'FAIL'}  ${name} (${seconds}s)`);
}
console.log(
	`Wall time: ${Math.round((Date.now() - gateStarted) / MS_PER_SECOND)}s`,
);
if ([...results.values()].some(({ passed }) => !passed)) {
	process.exit(1);
}
if (affected) {
	console.log(
		'Affected steps only: not attested. Run pnpm ci:local to attest.',
	);
} else if (startTree === undefined) {
	console.log(
		'Not attested: the working tree had uncommitted changes when the gate started. Commit and run the gate again to attest.',
	);
} else {
	const record = spawnSync(
		'node',
		['bin/ci-local-attest.mjs', 'record', startTree],
		{ cwd: root, stdio: 'inherit' },
	);
	process.exit(record.status ?? 1);
}
