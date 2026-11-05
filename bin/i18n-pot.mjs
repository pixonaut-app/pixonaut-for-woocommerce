#!/usr/bin/env node
// Writes languages/pixonaut-for-woocommerce.pot with `wp i18n make-pot` in
// wp-env. The container cannot write to the mounted checkout, so the file is
// made inside it and streamed back.
//
// `--check` writes nothing and exits 1 when the committed file is stale.
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pluginDir, root, SLUG, wpEnvOutput } from './env.mjs';

// Playwright's output directories hold the trace viewer's minified bundles
// after a failed e2e run; parsing them exhausts WP-CLI's memory limit.
const EXCLUDE = [
	'node_modules',
	'vendor',
	'tests',
	'assets/build',
	'docs',
	'contracts',
	'bin',
	'dist',
	'playwright-report',
	'test-results',
	'blob-report',
].join(',');
const HEADERS = JSON.stringify({
	'Report-Msgid-Bugs-To':
		'https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues',
});
const CONTAINER_POT = `/tmp/${SLUG}.pot`;
const POT_FILE = join(root, 'languages', `${SLUG}.pot`);
/** Changes on every run, so a freshness check ignores it. */
const CREATION_DATE_LINE = /^"POT-Creation-Date: .*\n/m;

const makePot = [
	'wp i18n make-pot .',
	CONTAINER_POT,
	`--slug=${SLUG}`,
	`--exclude=${EXCLUDE}`,
	`--headers='${HEADERS}'`,
	'--skip-audit',
].join(' ');

const result = wpEnvOutput([
	'run',
	'tests-cli',
	`--env-cwd=${pluginDir}`,
	'sh',
	'-c',
	`${makePot} >&2 && cat ${CONTAINER_POT}`,
]);
if (result.status !== 0 || !result.stdout.startsWith('#')) {
	console.error('make-pot failed');
	process.exit(result.status || 1);
}

if (process.argv.includes('--check')) {
	const withoutDate = (pot) => pot.replace(CREATION_DATE_LINE, '');
	if (
		withoutDate(readFileSync(POT_FILE, 'utf8')) !== withoutDate(result.stdout)
	) {
		console.error(
			`languages/${SLUG}.pot is out of date: run pnpm i18n:pot and commit it.`,
		);
		process.exit(1);
	}
	console.log(`languages/${SLUG}.pot is up to date.`);
} else {
	writeFileSync(POT_FILE, result.stdout);
}
