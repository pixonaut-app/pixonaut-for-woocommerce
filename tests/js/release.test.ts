import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { zipSync } from 'fflate';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	devPackagePrefixes,
	REQUIRED,
	ROOT_DIR,
	zipProblems,
} from '../../bin/release/allowlist.mjs';
import { makeZip } from '../../bin/release/archive.mjs';
import {
	flavorFromArgs,
	stripUpdateUri,
	UPDATER_PACKAGE,
	UPDATER_SOURCES,
	wporgComposerJson,
	zipName,
} from '../../bin/release/flavor.mjs';
import { buildReadme, parseChangelog } from '../../bin/release/readme.mjs';
import { runScript, tempDir } from './support/scripts';

const REQUIRED_ENTRIES = REQUIRED.map((path) => `${ROOT_DIR}${path}`);

const UPDATER_ENTRIES = [
	...UPDATER_SOURCES,
	`vendor/${UPDATER_PACKAGE}/plugin-update-checker.php`,
	`vendor/${UPDATER_PACKAGE}/Puc/v5p7/Vcs/GitHubApi.php`,
].map((path) => `${ROOT_DIR}${path}`);

/** A github flavor listing that ships everything it must, plus `extra`. */
function listing(...extra: string[]): string[] {
	return wporgListing(...UPDATER_ENTRIES, ...extra);
}

/** A wordpress.org flavor listing that ships everything it must, plus `extra`. */
function wporgListing(...extra: string[]): string[] {
	return [
		`${ROOT_DIR}`,
		...REQUIRED_ENTRIES,
		`${ROOT_DIR}src/Plugin.php`,
		`${ROOT_DIR}templates/admin/notice.php`,
		`${ROOT_DIR}assets/build/storefront.js`,
		`${ROOT_DIR}assets/build/storefront.asset.php`,
		`${ROOT_DIR}languages/pixonaut-for-woocommerce.pot`,
		`${ROOT_DIR}vendor/composer/autoload_static.php`,
		...extra,
	];
}

const DEV_PACKAGES = devPackagePrefixes({
	'packages-dev': [{ name: 'phpunit/phpunit' }],
});

describe('release zip allowlist', () => {
	it('passes a zip holding only runtime files', () => {
		expect(zipProblems(listing(), DEV_PACKAGES)).toEqual([]);
	});

	it.each([
		['a stray file', 'notes.md', 'not in the allowlist'],
		['the Pixonaut SDK', 'assets/build/pixonaut.iife.js', 'Pixonaut SDK'],
		['the SDK wasm', 'assets/build/pixonaut_wasm_bg.wasm', 'Pixonaut SDK'],
		['the SDK wasm glue', 'assets/build/pixonaut_wasm.js', 'Pixonaut SDK'],
		[
			'an SDK type file',
			'contracts/pixonaut/sdk/pixonaut.d.ts',
			'source or declaration file',
		],
		[
			'a declaration file',
			'assets/build/storefront.d.ts',
			'source or declaration file',
		],
		[
			'a TypeScript source',
			'assets/src/storefront/index.ts',
			'source or declaration file',
		],
		['a test', 'tests/php/Unit/PluginTest.php', 'tests'],
		['the fake', 'tests/fake-pixonaut/server.mjs', 'tests'],
		['contracts', 'contracts/pixonaut/openapi.json', 'not in the allowlist'],
		['tooling config', 'phpcs.xml.dist', 'not in the allowlist'],
		['a lockfile', 'composer.lock', 'not in the allowlist'],
		['a dotfile', '.wp-env.json', 'dotfile'],
		[
			'a source map',
			'assets/build/storefront.js.map',
			'source or declaration file',
		],
		[
			'a development package',
			'vendor/phpunit/phpunit/src/Framework/TestCase.php',
			'development-only Composer package',
		],
		['a Composer binary', 'vendor/bin/phpunit', 'Composer binary'],
		[
			"Strauss's development aliases",
			'vendor/composer/autoload_aliases.php',
			'Strauss development aliases',
		],
	])('fails on %s', (_name, path, reason) => {
		expect(zipProblems(listing(`${ROOT_DIR}${path}`), DEV_PACKAGES)).toEqual([
			`${ROOT_DIR}${path}: ${reason}`,
		]);
	});

	it('fails on a path outside the plugin directory', () => {
		expect(zipProblems(listing('readme.txt'))).toEqual([
			`readme.txt: outside ${ROOT_DIR}`,
		]);
	});

	it('fails on a path that climbs out of the plugin directory', () => {
		const path = `${ROOT_DIR}../evil.php`;

		expect(zipProblems(listing(path))).toEqual([
			`${path}: outside ${ROOT_DIR}`,
		]);
	});

	it('fails when a required file is missing', () => {
		const entries = listing().filter(
			(path) => path !== `${ROOT_DIR}vendor/autoload.php`,
		);

		expect(zipProblems(entries)).toEqual([
			`${ROOT_DIR}vendor/autoload.php: missing`,
		]);
	});

	it('passes a translation shipped with its compiled files', () => {
		const translation = ['po', 'mo', 'l10n.php'].map(
			(extension) =>
				`${ROOT_DIR}languages/pixonaut-for-woocommerce-sv_SE.${extension}`,
		);

		expect(zipProblems(listing(...translation), DEV_PACKAGES)).toEqual([]);
	});

	it('fails a translation without its compiled files', () => {
		const po = `${ROOT_DIR}languages/pixonaut-for-woocommerce-sv_SE.po`;

		expect(zipProblems(listing(po), DEV_PACKAGES)).toEqual([
			`${ROOT_DIR}languages/pixonaut-for-woocommerce-sv_SE.mo: missing`,
			`${ROOT_DIR}languages/pixonaut-for-woocommerce-sv_SE.l10n.php: missing`,
		]);
	});

	it('fails a github flavor zip without the update checker', () => {
		const entries = listing().filter(
			(path) => path !== `${ROOT_DIR}${UPDATER_SOURCES[0]}`,
		);

		expect(zipProblems(entries)).toEqual([
			`${ROOT_DIR}${UPDATER_SOURCES[0]}: missing`,
		]);
	});

	it('passes a wporg flavor zip without the update checker', () => {
		expect(zipProblems(wporgListing(), DEV_PACKAGES, 'wporg')).toEqual([]);
	});

	it('fails a wporg flavor zip holding the update checker', () => {
		expect(zipProblems(listing(), DEV_PACKAGES, 'wporg')).toEqual(
			UPDATER_ENTRIES.map(
				(path) => `${path}: update checker in the wporg flavor`,
			),
		);
	});

	it('lists a zip file and names the stray file in it', () => {
		const dir = tempDir('zip');
		const zip = join(dir, 'release.zip');
		const files = Object.fromEntries(
			listing(`${ROOT_DIR}tests/bootstrap.php`)
				.filter((path) => !path.endsWith('/'))
				.map((path) => [path, new Uint8Array()]),
		);
		writeFileSync(zip, zipSync(files));
		writeFileSync(join(dir, 'composer.lock'), '{"packages-dev": []}');

		const result = runScript(
			'release/check-zip.mjs',
			[zip, join(dir, 'composer.lock')],
			{ cwd: dir },
		);

		expect(result.status).toBe(1);
		expect(result.stderr.trim()).toBe(`${ROOT_DIR}tests/bootstrap.php: tests`);
	});
});

describe('release flavors', () => {
	it('builds the github flavor unless told otherwise', () => {
		expect(flavorFromArgs([])).toBe('github');
		expect(flavorFromArgs(['--flavor', 'wporg'])).toBe('wporg');
		expect(flavorFromArgs(['--flavor=wporg'])).toBe('wporg');
		expect(() => flavorFromArgs(['--flavor', 'nightly'])).toThrow(
			'Unknown flavor "nightly"',
		);
	});

	it('names only the github zip like the asset the update checker downloads', () => {
		expect(zipName('1.2.0-beta.1', 'github')).toBe(
			'pixonaut-for-woocommerce-1.2.0-beta.1.zip',
		);
		expect(zipName('1.2.0', 'wporg')).toBe(
			'pixonaut-for-woocommerce-1.2.0-wporg.zip',
		);
	});

	it('strips the Update URI header and nothing else', () => {
		const plugin = readFileSync(
			join(__dirname, '..', '..', 'pixonaut-for-woocommerce.php'),
			'utf8',
		);

		const stripped = stripUpdateUri(plugin);

		expect(plugin).toMatch(/^ \* Update URI: /m);
		expect(stripped).not.toMatch(/Update URI/);
		expect(stripped.split('\n')).toEqual(
			plugin.split('\n').filter((line) => !line.includes('Update URI:')),
		);
		expect(() => stripUpdateUri(stripped)).toThrow('no Update URI line');
	});

	it('drops the update checker from composer.json', () => {
		const composer = JSON.parse(
			readFileSync(join(__dirname, '..', '..', 'composer.json'), 'utf8'),
		);

		const wporg = wporgComposerJson(composer);

		expect(composer.require[UPDATER_PACKAGE]).toBeDefined();
		expect(wporg.require).toEqual({ php: composer.require.php });
		expect(wporg.autoload).toEqual({ 'psr-4': composer.autoload['psr-4'] });
		expect(wporg['require-dev']).toBeUndefined();
		expect(wporg.name).toBe(composer.name);
	});
});

const PLUGIN = `<?php
/**
 * Plugin Name:          Example
 * x-release-please-start-version
 * Version:              0.3.0
 * x-release-please-end
 * Requires at least:    6.9
 * Tested up to:         7.1
 * Requires PHP:         8.1
 */
`;

const SOURCE = `=== Example ===
Tags: woocommerce
License: GPLv2 or later

Short description.

== Description ==

Long description.
`;

const CHANGELOG = `# Changelog

## [0.3.0](https://github.com/o/r/compare/v0.2.0...v0.3.0) (2026-11-02)


### Features

* **storefront:** inline layout ([#40](https://github.com/o/r/issues/40)) ([abc1234](https://github.com/o/r/commit/abc1234))

## [0.2.0](https://github.com/o/r/compare/v0.1.0...v0.2.0) (2026-10-20)


### ⚠ BREAKING CHANGES

* **api:** the pixonaut_wc_api_request filter receives the operation id
  instead of the path.

### Bug Fixes

* retry a timed-out order ([#31](https://github.com/o/r/issues/31)) ([def5678](https://github.com/o/r/commit/def5678))

## 0.1.0 (2026-10-15)


### Features

* first release
`;

describe('readme.txt', () => {
	const readme = buildReadme({
		source: SOURCE,
		plugin: PLUGIN,
		changelog: CHANGELOG,
	});

	it('repeats the plugin header minimums with the version as stable tag', () => {
		expect(readme).toMatch(
			/^=== Example ===\nRequires at least: 6\.9\nTested up to: 7\.1\nRequires PHP: 8\.1\nStable tag: 0\.3\.0\nTags: woocommerce\n/,
		);
	});

	it('keeps the source body', () => {
		expect(readme).toContain('== Description ==\n\nLong description.\n');
	});

	it('turns the changelog into readme sections without links', () => {
		expect(readme).toContain(
			'== Changelog ==\n\n= 0.3.0 - 2026-11-02 =\n\n**Features**\n\n* storefront: inline layout (#40)\n',
		);
		expect(readme).toContain(
			'**BREAKING CHANGES**\n\n* api: the pixonaut_wc_api_request filter receives the operation id instead of the path.\n',
		);
		expect(readme).toContain(
			'= 0.1.0 - 2026-10-15 =\n\n**Features**\n\n* first release',
		);
	});

	it('writes an upgrade notice for the release and for breaking changes', () => {
		const notices = readme.slice(readme.indexOf('== Upgrade Notice =='));

		expect(notices).toBe(
			[
				'== Upgrade Notice ==',
				'',
				'= 0.3.0 =',
				'',
				'No breaking changes: safe to update.',
				'',
				'= 0.2.0 =',
				'',
				'api: the pixonaut_wc_api_request filter receives the operation id instead of the path.',
				'',
			].join('\n'),
		);
	});

	it('notes the version even before the changelog lists it', () => {
		const result = buildReadme({
			source: SOURCE,
			plugin: PLUGIN,
			changelog: '# Changelog\n',
		});

		expect(result).toContain('== Changelog ==\n\n== Upgrade Notice ==');
		expect(result).toContain('= 0.3.0 =\n\nNo breaking changes');
	});

	it('refuses a source that holds a generated section', () => {
		expect(() =>
			buildReadme({
				source: `${SOURCE}\n== Changelog ==\n`,
				plugin: PLUGIN,
				changelog: CHANGELOG,
			}),
		).toThrow(/must not hold the Changelog/);
	});

	it('parses releases newest first', () => {
		expect(parseChangelog(CHANGELOG).map(({ version }) => version)).toEqual([
			'0.3.0',
			'0.2.0',
			'0.1.0',
		]);
	});

	it('builds from the checkout with an External services section', () => {
		const read = (file: string) => readFileSync(file, 'utf8');
		const result = buildReadme({
			source: read('readme.src.txt'),
			plugin: read('pixonaut-for-woocommerce.php'),
			changelog: read('CHANGELOG.md'),
		});

		expect(result).toContain('== External services ==');
		expect(result).toContain('= Pixonaut API =');
		expect(result).toContain('= Pixonaut SDK from cdn.pixonaut.app =');
	});

	it('names what is sent to Pixonaut, how long it is kept, and the terms', () => {
		const read = (file: string) => readFileSync(file, 'utf8');
		const result = buildReadme({
			source: read('readme.src.txt'),
			plugin: read('pixonaut-for-woocommerce.php'),
			changelog: read('CHANGELOG.md'),
		});
		const start = result.indexOf('== External services ==');
		const services = result.slice(start, result.indexOf('\n== ', start));

		expect(services).toContain('https://pixonaut.app/terms');
		expect(services).toContain('https://pixonaut.app/privacy');
		expect(services).toMatch(/photos the customer uploads/);
		expect(services).toMatch(/text they type/);
		expect(services).toMatch(
			/recipient's name, email address, phone number and shipping address/,
		);
		expect(services).toMatch(/erases them .* after the order is finished/);
	});

	it('names what Pixel sends to AI sub-processors, with their list', () => {
		const read = (file: string) => readFileSync(file, 'utf8');
		const result = buildReadme({
			source: read('readme.src.txt'),
			plugin: read('pixonaut-for-woocommerce.php'),
			changelog: read('CHANGELOG.md'),
		});
		const start = result.indexOf("= Pixel, Pixonaut's AI assistant");
		const pixel = result.slice(start, result.indexOf('\n= ', start + 1));

		expect(start).toBeGreaterThan(result.indexOf('= Pixonaut API ='));
		expect(pixel).toContain('https://pixonaut.app/subprocessors');
		expect(pixel).toMatch(/AI model providers, its sub-processors/);
		expect(pixel).toMatch(/customers' names, .*addresses and order notes/);
		expect(pixel).toMatch(/prompt the customer types into the designer/);
	});
});

describe('release-please', () => {
	const config = JSON.parse(readFileSync('release-please-config.json', 'utf8'));
	const files: string[] = config.packages['.']['extra-files'];

	it.each(files)('marks the version in %s', (file) => {
		expect(readFileSync(file, 'utf8')).toMatch(
			/x-release-please-(start-)?version/,
		);
	});
});

describe('release zip bytes', () => {
	afterEach(() => {
		vi.useRealTimers();
	});

	it('are the same for the same files, whenever they are built', () => {
		const files = {
			[`${ROOT_DIR}b.php`]: new TextEncoder().encode('<?php // b'),
			[`${ROOT_DIR}a.php`]: new TextEncoder().encode('<?php // a'),
		};

		vi.useFakeTimers({ now: new Date('2026-01-01T00:00:00Z') });
		const first = makeZip(files);
		vi.setSystemTime(new Date('2027-06-15T12:34:56Z'));
		const second = makeZip({
			[`${ROOT_DIR}a.php`]: files[`${ROOT_DIR}a.php`],
			[`${ROOT_DIR}b.php`]: files[`${ROOT_DIR}b.php`],
		});

		expect(Buffer.from(second).equals(Buffer.from(first))).toBe(true);
	});
});
