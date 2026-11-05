import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';
import type { E2eOptions } from './tests/e2e/fixtures';

/** Carry the resolved URLs from the runner to its worker processes. */
const BASE_URL_ENV = 'PIXONAUT_E2E_BASE_URL';
const FAKE_BASE_URL_ENV = 'PIXONAUT_E2E_FAKE_BASE_URL';

/** `wp` in the tests site's CLI container, answered on stdout. */
function testsCli(...args: string[]): string {
	return execFileSync(
		'node',
		['bin/wp-env.mjs', 'run', 'tests-cli', 'wp', ...args],
		{
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'ignore'],
		},
	).trim();
}

/**
 * The tests site's URL, asked of the running wp-env rather than derived from
 * WP_ENV_TESTS_PORT, so it is right even when the site was started with other
 * ports than this shell has.
 */
function testsSiteUrl(): string {
	process.env[BASE_URL_ENV] ??= testsCli('option', 'get', 'home');
	return process.env[BASE_URL_ENV];
}

/** The fake Pixonaut's public base, as the tests site's plugin is given it. */
function fakeBaseUrl(): string {
	process.env[FAKE_BASE_URL_ENV] ??= testsCli(
		'config',
		'get',
		'PIXONAUT_WC_API_BASE',
	);
	return process.env[FAKE_BASE_URL_ENV];
}

/** Installed only with WP_ENV_COMPAT: the themes and builders of the Nightly's
 *  compatibility job. */
const COMPATIBILITY_SPEC = /compatibility\.spec\.ts$/;

type Combination = {
	id: string;
	theme: string;
	builder?: string;
};

/** One project per theme and page builder combination. */
function compatibilityProjects() {
	const { combinations }: { combinations: Combination[] } = JSON.parse(
		readFileSync('tests/compat/combinations.json', 'utf8'),
	);
	return combinations.map(({ id, theme, builder }) => ({
		name: `compat-${id}`,
		use: {
			theme,
			combination: id,
			builder: builder ?? '',
			checkout: 'classic' as const,
		},
		testMatch: COMPATIBILITY_SPEC,
	}));
}

export default defineConfig<E2eOptions>({
	testDir: './tests/e2e/specs',
	forbidOnly: !!process.env.CI,
	// One tests site and one fake, both stateful and reset per test: tests run
	// one at a time so a reset never lands in another test's middle.
	fullyParallel: false,
	workers: 1,
	reporter: process.env.CI ? 'list' : [['list'], ['html', { open: 'never' }]],
	use: {
		...devices['Desktop Chrome'],
		baseURL: testsSiteUrl(),
		fakeBaseURL: fakeBaseUrl(),
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'classic',
			use: { theme: 'storefront', checkout: 'classic' },
			testIgnore: COMPATIBILITY_SPEC,
		},
		{
			name: 'block-primary',
			use: { theme: 'twentytwentyfive', checkout: 'block' },
			testIgnore: COMPATIBILITY_SPEC,
		},
		// The product page with the other add-to-cart and gallery blocks:
		// only what those blocks change.
		{
			name: 'block-secondary',
			use: {
				theme: 'twentytwentyfive',
				blocks: 'secondary',
				checkout: 'block',
			},
			testMatch:
				/(inline-personaliser|attribute-swatches|server-layout)\.spec\.ts$/,
		},
		// Another classic theme with its own rules on inputs, buttons and
		// labels: only how the designer is laid out under it.
		{
			name: 'astra',
			use: { theme: 'astra', checkout: 'classic' },
			testMatch: /theme-layout\.spec\.ts$/,
		},
		...(process.env.WP_ENV_COMPAT ? compatibilityProjects() : []),
	],
});
