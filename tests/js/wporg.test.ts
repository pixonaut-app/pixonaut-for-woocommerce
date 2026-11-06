import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { ROOT_DIR, zipProblems } from '../../bin/release/allowlist.mjs';
import {
	leftOut,
	WPORG_UPLOAD_LIMIT_BYTES,
	zipSizeProblems,
} from '../../bin/release/flavor.mjs';
import {
	LISTING_ASSETS,
	LISTING_ASSETS_DIR,
	listingProblems,
	pngSize,
} from '../../bin/release/listing-assets.mjs';
import { buildReadme, readmeProblems } from '../../bin/release/readme.mjs';

const CHECKOUT = join(__dirname, '..', '..');

const read = (file: string) => readFileSync(join(CHECKOUT, file), 'utf8');

/** The readme.txt the release zip holds, built from the checkout. */
function checkoutReadme(): string {
	return buildReadme({
		source: read('readme.src.txt'),
		plugin: read('pixonaut-for-woocommerce.php'),
		changelog: read('CHANGELOG.md'),
	});
}

const VALID = `=== Example for WooCommerce ===
Contributors: example
Tags: woocommerce, personalization
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short description.

== Description ==

Long description.
`;

describe('readme.txt for wordpress.org', () => {
	it('accepts a readme with every header and a short description', () => {
		expect(readmeProblems(VALID)).toEqual([]);
	});

	it.each([
		'Contributors',
		'Tags',
		'Requires at least',
		'Tested up to',
		'Requires PHP',
		'Stable tag',
		'License',
		'License URI',
	])('names a missing %s header', (name) => {
		const readme = VALID.replace(new RegExp(`^${name}: .*\\n`, 'm'), '');

		expect(readmeProblems(readme)).toEqual([`missing the ${name} header`]);
	});

	it('refuses more than five tags, which wordpress.org cuts', () => {
		const readme = VALID.replace(/^Tags: .*$/m, 'Tags: a, b, c, d, e, f');

		expect(readmeProblems(readme)).toEqual(['6 tags: at most 5 are shown']);
	});

	it('refuses a short description longer than wordpress.org shows', () => {
		const readme = VALID.replace('Short description.', 'x'.repeat(151));

		expect(readmeProblems(readme)).toEqual([
			'the short description is 151 characters: at most 150 are shown',
		]);
	});

	it('refuses a missing short description', () => {
		const readme = VALID.replace('Short description.\n\n', '');

		expect(readmeProblems(readme)).toEqual(['no short description']);
	});

	it('refuses a contributor that is not a wordpress.org username', () => {
		const readme = VALID.replace(
			'Contributors: example',
			'Contributors: example, Some Body',
		);

		expect(readmeProblems(readme)).toEqual([
			'"Some Body" is not a wordpress.org username',
		]);
	});

	it('refuses a Tested up to that is not a WordPress version', () => {
		const readme = VALID.replace('Tested up to: 7.1', 'Tested up to: latest');

		expect(readmeProblems(readme)).toEqual([
			'Tested up to is "latest", not a WordPress version',
		]);
	});

	it('refuses a readme without a Description section', () => {
		const readme = VALID.replace('== Description ==', '== About ==');

		expect(readmeProblems(readme)).toEqual(['no Description section']);
	});

	it('passes for the readme the release builds', () => {
		expect(readmeProblems(checkoutReadme())).toEqual([]);
	});

	it('names where the source of the built scripts is', () => {
		const description = checkoutReadme().split('== Description ==')[1];

		expect(description).toContain(
			'https://github.com/pixonaut-app/pixonaut-for-woocommerce',
		);
		expect(description).toMatch(/assets\/src/);
	});

	it('says the designer may show a "Powered by Pixonaut" link, and who decides', () => {
		const services = checkoutReadme().split('== External services ==')[1];

		expect(services).toMatch(/Powered by Pixonaut/);
	});
});

describe('wordpress.org listing assets', () => {
	const dir = join(CHECKOUT, LISTING_ASSETS_DIR);

	it.each(Object.entries(LISTING_ASSETS))(
		'holds %s at its size',
		(name, [width, height]) => {
			expect(pngSize(readFileSync(join(dir, name)))).toEqual([width, height]);
		},
	);

	it('holds a screenshot for every caption in the readme, and no other', () => {
		const files = readdirSync(dir).filter((name) =>
			name.startsWith('screenshot-'),
		);

		expect(files.length).toBeGreaterThan(0);
		expect(listingProblems(readdirSync(dir), checkoutReadme())).toEqual([]);
	});

	it('names a screenshot without a caption, and a caption without one', () => {
		const readme = `${VALID}\n== Screenshots ==\n\n1. First.\n2. Second.\n`;
		const files = [
			...Object.keys(LISTING_ASSETS),
			'screenshot-1.png',
			'screenshot-3.png',
		];

		expect(listingProblems(files, readme)).toEqual([
			'screenshot-2.png: missing for caption 2',
			'screenshot-3.png: no caption 3 in the readme',
		]);
	});

	it('names a missing banner or icon', () => {
		const files = Object.keys(LISTING_ASSETS).filter(
			(name) => name !== 'icon-256x256.png',
		);

		expect(listingProblems(files, VALID)).toEqual([
			'icon-256x256.png: missing',
		]);
	});

	it('reads the width and height of a PNG', () => {
		const header = Buffer.alloc(24);
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]).copy(header);
		header.write('IHDR', 12, 'ascii');
		header.writeUInt32BE(772, 16);
		header.writeUInt32BE(250, 20);

		expect(pngSize(header)).toEqual([772, 250]);
		expect(() => pngSize(Buffer.from('GIF89a'))).toThrow('not a PNG');
	});
});

describe('wordpress.org flavor zip', () => {
	const po = 'languages/pixonaut-for-woocommerce-sv_SE.po';

	it('leaves the translations sources out: WordPress loads the compiled files', () => {
		expect(leftOut(po, 'wporg')).toBe(true);
		expect(
			leftOut('languages/pixonaut-for-woocommerce-sv_SE.mo', 'wporg'),
		).toBe(false);
		expect(leftOut('languages/pixonaut-for-woocommerce.pot', 'wporg')).toBe(
			false,
		);
		expect(leftOut(po, 'github')).toBe(false);
	});

	it('fails a wporg zip holding a translation source', () => {
		const entries = [
			ROOT_DIR,
			...[
				'pixonaut-for-woocommerce.php',
				'uninstall.php',
				'readme.txt',
				'LICENSE',
				'CHANGELOG.md',
				'composer.json',
				'vendor/autoload.php',
				po,
				'languages/pixonaut-for-woocommerce-sv_SE.mo',
				'languages/pixonaut-for-woocommerce-sv_SE.l10n.php',
			].map((path) => `${ROOT_DIR}${path}`),
		];

		expect(zipProblems(entries, [], 'wporg')).toEqual([
			`${ROOT_DIR}${po}: translation source in the wporg flavor`,
		]);
	});

	it('fails a wporg zip over the upload limit of the submission form', () => {
		expect(zipSizeProblems(WPORG_UPLOAD_LIMIT_BYTES, 'wporg')).toEqual([]);
		expect(zipSizeProblems(WPORG_UPLOAD_LIMIT_BYTES + 1, 'wporg')).toEqual([
			`the zip is ${WPORG_UPLOAD_LIMIT_BYTES + 1} bytes: wordpress.org takes at most ${WPORG_UPLOAD_LIMIT_BYTES}`,
		]);
		expect(zipSizeProblems(WPORG_UPLOAD_LIMIT_BYTES + 1, 'github')).toEqual([]);
	});
});
