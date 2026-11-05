// Writes the release zip's bytes. The same files always give the same bytes,
// so a release's SHA-256 can be reproduced from its tag.
import { zipSync } from 'fflate';

/** fflate's highest DEFLATE level. */
const COMPRESSION_LEVEL = 9;

/**
 * Every entry's modification time. A zip stores local date and time fields,
 * so the date is built from local fields too: it encodes the same in every
 * time zone.
 */
const ENTRY_MTIME = new Date(2000, 0, 1);

/**
 * A zip of `files`, keyed by their path in the zip, in sorted path order.
 *
 * @param {Record<string, Uint8Array>} files
 * @returns {Uint8Array}
 */
export function makeZip(files) {
	const sorted = Object.keys(files)
		.sort()
		.map((path) => [path, files[path]]);
	return zipSync(Object.fromEntries(sorted), {
		level: COMPRESSION_LEVEL,
		mtime: ENTRY_MTIME,
	});
}
