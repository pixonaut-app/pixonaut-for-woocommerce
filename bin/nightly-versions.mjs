#!/usr/bin/env node
// The WordPress and WooCommerce releases the nightly workflow tests against:
// the newest patch of each of the two newest branches (`latest` and
// `latest-1`), leaving out branches below the plugin header's minimums, and
// one more pairing at those minimums (the newest patch of each minimum
// branch), so the oldest versions the plugin claims to run on are tested.
//
//   nightly-versions.mjs   prints the matrix as JSON; under GitHub Actions
//                          also writes `matrix` and `key` to GITHUB_OUTPUT
import { appendFileSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { root, SLUG } from './env.mjs';
import { header } from './plugin-header.mjs';

/** `latest` and `latest-1`. */
export const BRANCH_COUNT = 2;

/** Every WordPress release, mapped to `latest`, `outdated` or `insecure`. */
const WORDPRESS_RELEASES = 'https://api.wordpress.org/core/stable-check/1.0/';

/** WooCommerce's plugin information, with every release and its zip. */
const WOOCOMMERCE_RELEASES =
	'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=woocommerce&fields%5Bversions%5D=1';

/** A stable release: `7.1` or `7.1.3`, no `-beta.1`, `-rc.1` or `trunk`. */
const STABLE = /^\d+\.\d+(\.\d+)?$/;

/** @param {string} version */
function parts(version) {
	const [major, minor, patch = 0] = version.split('.').map(Number);
	return [major, minor, patch];
}

/**
 * @param {string} a
 * @param {string} b
 */
function compare(a, b) {
	const left = parts(a);
	const right = parts(b);
	for (const [index, value] of left.entries()) {
		if (value !== right[index]) {
			return value - right[index];
		}
	}
	return 0;
}

/**
 * The newest patch of each of the `count` newest `major.minor` branches,
 * newest first, among the stable `versions` at or above `minimum`.
 *
 * @param {string[]} versions
 * @param {string} minimum A `major.minor` branch, from the plugin header.
 * @param {number} [count]
 * @returns {string[]}
 */
export function newestBranches(versions, minimum, count = BRANCH_COUNT) {
	const branchOf = (/** @type {string} */ version) =>
		parts(version).slice(0, 2).join('.');
	const newest = new Map();
	for (const version of versions
		.filter((candidate) => STABLE.test(candidate))
		.filter((candidate) => compare(branchOf(candidate), minimum) >= 0)
		.sort(compare)) {
		newest.set(branchOf(version), version);
	}
	return [...newest.values()].reverse().slice(0, count);
}

/**
 * The newest stable patch of the `branch` (`major.minor`) among `versions`;
 * undefined when it has none.
 *
 * @param {string[]} versions
 * @param {string} branch
 * @returns {string | undefined}
 */
export function newestPatch(versions, branch) {
	return newestBranches(
		versions.filter(
			(version) => parts(version).slice(0, 2).join('.') === branch,
		),
		branch,
		1,
	)[0];
}

/** @param {string} url */
async function json(url) {
	const response = await fetch(url);
	if (!response.ok) {
		throw new Error(`${url} answered ${response.status}.`);
	}
	return response.json();
}

/**
 * One matrix cell per pairing of a WordPress and a WooCommerce release, as
 * wp-env sources: `WP_ENV_CORE` and `WP_ENV_WOOCOMMERCE` (bin/wp-env.mjs).
 *
 * @param {string[]} wordpress
 * @param {string[]} woocommerce
 * @param {Record<string, string>} zips WooCommerce's zip URL by version.
 */
export function matrix(wordpress, woocommerce, zips) {
	return wordpress.flatMap((wp) =>
		woocommerce.map((wc) => ({
			wp,
			wc,
			core: `https://wordpress.org/wordpress-${wp}.zip`,
			woocommerce: zips[wc],
		})),
	);
}

/**
 * The cells, with a cell pairing the minimum WordPress and WooCommerce
 * releases appended when no cell pairs them already.
 *
 * @param {ReturnType<typeof matrix>} cells
 * @param {string} wp The newest patch of WordPress's minimum branch.
 * @param {string} wc The newest patch of WooCommerce's minimum branch.
 * @param {Record<string, string>} zips WooCommerce's zip URL by version.
 */
export function withMinimums(cells, wp, wc, zips) {
	return cells.some((cell) => cell.wp === wp && cell.wc === wc)
		? cells
		: [...cells, ...matrix([wp], [wc], zips)];
}

/**
 * @param {string | undefined} release
 * @param {string} what
 * @returns {string}
 */
function required(release, what) {
	if (release === undefined) {
		throw new Error(`No stable ${what} release on the minimum branch.`);
	}
	return release;
}

/**
 * @param {string} plugin The main plugin file's text.
 * @param {string} field
 */
function minimum(plugin, field) {
	const value = header(plugin, field);
	if (value === undefined) {
		throw new Error(`The plugin header has no ${field}.`);
	}
	return value;
}

async function main() {
	const plugin = readFileSync(join(root, `${SLUG}.php`), 'utf8');
	const wordpressReleases = Object.keys(await json(WORDPRESS_RELEASES));
	const wordpressMinimum = minimum(plugin, 'Requires at least');
	const wordpress = newestBranches(wordpressReleases, wordpressMinimum);
	const { versions: zips } = await json(WOOCOMMERCE_RELEASES);
	const woocommerceMinimum = minimum(plugin, 'WC requires at least');
	const woocommerce = newestBranches(Object.keys(zips), woocommerceMinimum);
	const oldestWordpress = required(
		newestPatch(wordpressReleases, wordpressMinimum),
		'WordPress',
	);
	const oldestWoocommerce = required(
		newestPatch(Object.keys(zips), woocommerceMinimum),
		'WooCommerce',
	);
	const cells = withMinimums(
		matrix(wordpress, woocommerce, zips),
		oldestWordpress,
		oldestWoocommerce,
		zips,
	);
	const key = `wp-${wordpress.join('-')}-${oldestWordpress}-wc-${woocommerce.join('-')}-${oldestWoocommerce}`;
	console.log(JSON.stringify(cells, null, '\t'));
	if (process.env.GITHUB_OUTPUT) {
		appendFileSync(
			process.env.GITHUB_OUTPUT,
			`matrix=${JSON.stringify(cells)}\nkey=${key}\n`,
		);
	}
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	await main();
}
