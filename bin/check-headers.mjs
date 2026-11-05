#!/usr/bin/env node
// Checks that the minimum versions the plugin states agree everywhere they
// are stated: the plugin header, readme.txt (when there is one), composer.json,
// Requirements::MIN_PHP and Requirements::MIN_WOOCOMMERCE. Exits 1 and names each disagreement.
//
// Usage: check-headers.mjs [plugin-root]   (default: this checkout)
import { existsSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { root as checkoutRoot } from './env.mjs';
import { header, README_HEADERS } from './plugin-header.mjs';

const MAIN_FILE = 'pixonaut-for-woocommerce.php';
const README = 'readme.txt';
const REQUIREMENTS = join('src', 'Requirements.php');

const root = resolve(process.argv[2] ?? checkoutRoot);
const read = (file) => readFileSync(join(root, file), 'utf8');

/** `8.1` from `>=8.1`, `^8.1` or `8.1.0`. */
function majorMinor(constraint) {
	return constraint?.match(/(\d+\.\d+)/)?.[1];
}

const problems = [];
function expectSame(what, expected, actual, where) {
	if (actual !== expected) {
		problems.push(
			`${where}: ${what} is ${actual ?? 'missing'}, the plugin header says ${expected}`,
		);
	}
}

const plugin = read(MAIN_FILE);
const requiresPhp = header(plugin, 'Requires PHP');
if (requiresPhp === undefined) {
	problems.push(`${MAIN_FILE}: no Requires PHP header`);
}

const composer = JSON.parse(read('composer.json'));
expectSame(
	'require.php',
	requiresPhp,
	majorMinor(composer.require?.php),
	'composer.json',
);
expectSame(
	'config.platform.php',
	requiresPhp,
	majorMinor(composer.config?.platform?.php),
	'composer.json',
);
expectSame(
	'MIN_PHP',
	requiresPhp,
	read(REQUIREMENTS).match(/const MIN_PHP = '([^']+)'/)?.[1],
	REQUIREMENTS,
);

expectSame(
	'MIN_WOOCOMMERCE',
	header(plugin, 'WC requires at least'),
	read(REQUIREMENTS).match(/const MIN_WOOCOMMERCE = '([^']+)'/)?.[1],
	REQUIREMENTS,
);

if (existsSync(join(root, README))) {
	const readme = read(README);
	for (const name of README_HEADERS) {
		expectSame(name, header(plugin, name), header(readme, name), README);
	}
	expectSame(
		'Stable tag',
		header(plugin, 'Version'),
		header(readme, 'Stable tag'),
		README,
	);
}

if (problems.length > 0) {
	console.error(problems.join('\n'));
	process.exit(1);
}
console.log('Plugin header minimums agree.');
