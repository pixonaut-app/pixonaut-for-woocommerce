#!/usr/bin/env node
// The local merge gate. Runs every step, reports each, and exits 1 if any
// failed. A full pass on a clean tree is recorded for attestation
// (bin/ci-local-attest.mjs).
//
//   ci-local.mjs              every step
//   ci-local.mjs --affected   only the steps the branch's changes touch,
//                             compared with CI_LOCAL_BASE (default
//                             origin/main); never attests
//   ci-local.mjs --dry-run    print the steps that would run, run nothing
//
// Steps marked `wpEnv` need wp-env running (pnpm env:start).
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { root, wpEnvOutput } from './env.mjs';

const BASE = process.env.CI_LOCAL_BASE ?? 'origin/main';
const MS_PER_SECOND = 1000;

/** The fake Pixonaut server the e2e suite will run against. */
const FAKE_DIR = join('tests', 'fake-pixonaut');

const PHP = /\.php$/;
const PHP_SOURCE =
	/^(src\/|templates\/|uninstall\.php$|pixonaut-for-woocommerce\.php$)/;
const PHP_TESTS = /^tests\/php\//;
const COMPOSER = /^composer\.(json|lock)$/;
/** The pinned Pixonaut contract and `consumed.json`. */
const CONTRACT = /^contracts\/pixonaut\//;

/**
 * A change to any of these can affect every step: dependencies, the wp-env
 * setup, and the wrappers most steps run through.
 */
const GLOBAL =
	/^(package\.json|pnpm-lock\.yaml|composer\.(json|lock)|\.wp-env\.json|bin\/(env|php|wp-env|wp-env-override|ci-local)\.mjs)$/;

const STEPS = [
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
		wpEnv: true,
		affectedBy: [
			PHP_SOURCE,
			/^assets\//,
			/^languages\//,
			/^(LICENSE|CHANGELOG\.md|readme\.src\.txt|webpack\.config\.js)$/,
			/^bin\/(release\/|plugin-check\.mjs$|plugin-header\.mjs$)/,
			// The zip carries the compiled translations.
			/^bin\/i18n(-build\.mjs$|\/(files|po)\.mjs$)/,
		],
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
		name: 'e2e (classic, block-primary, astra)',
		command: ['pnpm', 'test:e2e'],
		wpEnv: true,
		notice: () =>
			existsSync(join(root, FAKE_DIR))
				? undefined
				: `No fake Pixonaut yet (${FAKE_DIR}): the e2e suite runs without it.`,
		affectedBy: [
			PHP_SOURCE,
			/^assets\/src\//,
			/^tests\/e2e\//,
			/^playwright\.config\.ts$/,
		],
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

/** Files changed since the merge base with BASE, committed or not. */
function changedFiles() {
	const [mergeBase] = git('merge-base', BASE, 'HEAD');
	return [
		...git('diff', '--name-only', mergeBase),
		...git('ls-files', '--others', '--exclude-standard'),
	];
}

function selectSteps(affected) {
	if (!affected) {
		return STEPS;
	}
	const files = changedFiles();
	if (files.some((file) => GLOBAL.test(file))) {
		return STEPS;
	}
	return STEPS.filter(({ affectedBy }) =>
		files.some((file) => affectedBy.some((pattern) => pattern.test(file))),
	);
}

function wpEnvRunning() {
	return wpEnvOutput(['run', 'tests-cli', 'true']).status === 0;
}

const affected = process.argv.includes('--affected');
const steps = selectSteps(affected);

if (process.argv.includes('--dry-run')) {
	for (const { name } of steps) {
		console.log(name);
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

const results = [];
for (const { name, command, notice } of steps) {
	console.log(`\n=== ${name}: ${command.join(' ')}`);
	const message = notice?.();
	if (message !== undefined) {
		console.log(message);
	}
	const started = Date.now();
	const [bin, ...args] = command;
	const { status } = spawnSync(bin, args, { cwd: root, stdio: 'inherit' });
	results.push({
		name,
		passed: status === 0,
		seconds: Math.round((Date.now() - started) / MS_PER_SECOND),
	});
}

console.log(`\n=== ci:local${affected ? ' --affected' : ''}`);
for (const { name, passed, seconds } of results) {
	console.log(`${passed ? 'pass' : 'FAIL'}  ${name} (${seconds}s)`);
}
if (results.some(({ passed }) => !passed)) {
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
