#!/usr/bin/env node
// The plugin's wordpress.org listing assets: banners, icons and screenshots,
// kept in .wordpress-org/ and published to the SVN repository's assets/
// directory, never in the zip. Each screenshot-N.png goes with caption N of
// the readme's Screenshots section.
//
//   listing-assets.mjs <readme.txt>   checks .wordpress-org/ against the
//                                     readme; exits 1 naming each problem
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { root } from '../env.mjs';

export const LISTING_ASSETS_DIR = '.wordpress-org';

/** The banners and icons wordpress.org shows, with their width and height. */
export const LISTING_ASSETS = {
	'banner-772x250.png': [772, 250],
	'banner-1544x500.png': [1544, 500],
	'icon-128x128.png': [128, 128],
	'icon-256x256.png': [256, 256],
};

/** The eight bytes every PNG starts with. */
const PNG_SIGNATURE = Buffer.from([
	0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
]);

/** Where the IHDR chunk, always the first, holds the width, then the height. */
const WIDTH_OFFSET = 16;
const HEIGHT_OFFSET = 20;

const SCREENSHOT = /^screenshot-(\d+)\.(png|jpe?g|gif|webp)$/;

/**
 * A PNG's width and height, from its header.
 *
 * @param {Buffer} png
 * @returns {[number, number]}
 */
export function pngSize(png) {
	if (
		png.length < HEIGHT_OFFSET + 4 ||
		!png.subarray(0, PNG_SIGNATURE.length).equals(PNG_SIGNATURE)
	) {
		throw new Error('not a PNG');
	}
	return [png.readUInt32BE(WIDTH_OFFSET), png.readUInt32BE(HEIGHT_OFFSET)];
}

/**
 * The numbers of the captions in a readme's Screenshots section.
 *
 * @param {string} readme
 * @returns {number[]}
 */
function captions(readme) {
	const start = readme.indexOf('\n== Screenshots ==');
	if (start === -1) {
		return [];
	}
	const next = readme.indexOf('\n== ', start + 1);
	const section = readme.slice(start, next === -1 ? undefined : next);
	return [...section.matchAll(/^(\d+)\. \S/gm)].map(([, number]) =>
		Number(number),
	);
}

/**
 * The problems with a listing assets directory, one line each: a missing
 * banner or icon, a caption without its screenshot, a screenshot without its
 * caption.
 *
 * @param {string[]} files The directory's file names.
 * @param {string} readme The readme.txt the zip ships.
 * @returns {string[]}
 */
export function listingProblems(files, readme) {
	const problems = Object.keys(LISTING_ASSETS)
		.filter((name) => !files.includes(name))
		.map((name) => `${name}: missing`);
	const numbered = new Map(
		files.flatMap((name) => {
			const match = name.match(SCREENSHOT);
			return match === null ? [] : [[Number(match[1]), name]];
		}),
	);
	const captioned = captions(readme);
	for (const number of captioned.filter((n) => !numbered.has(n))) {
		problems.push(`screenshot-${number}.png: missing for caption ${number}`);
	}
	for (const [number, name] of numbered) {
		if (!captioned.includes(number)) {
			problems.push(`${name}: no caption ${number} in the readme`);
		}
	}
	return problems;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	const dir = join(root, LISTING_ASSETS_DIR);
	const readme = readFileSync(process.argv[2] ?? '', 'utf8');
	const problems = listingProblems(readdirSync(dir), readme);
	for (const [name, [width, height]] of Object.entries(LISTING_ASSETS)) {
		if (!existsSync(join(dir, name))) {
			continue; // Named missing above.
		}
		const [w, h] = pngSize(readFileSync(join(dir, name)));
		if (w !== width || h !== height) {
			problems.push(`${name}: ${w}x${h}, not ${width}x${height}`);
		}
	}
	if (problems.length > 0) {
		console.error(problems.join('\n'));
		process.exit(1);
	}
	console.log('Listing assets match the readme.');
}
