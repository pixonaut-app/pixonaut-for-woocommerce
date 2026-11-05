/**
 * The pinned Pixonaut SDK release.
 *
 * Pixonaut publishes each SDK version, immutably, under
 * `https://cdn.pixonaut.app/sdk/<version>/`, with `integrity.json` beside the
 * files listing each one's SRI.
 *
 * `sdk.lock.json` is the plugin's one record of the pin: the version, the
 * script's SRI, and the SRI of each vendored type file.
 */

export const SDK_CDN_BASE = 'https://cdn.pixonaut.app/sdk/';
/** The record's name on the CDN, beside the files it lists. */
export const SDK_RECORD_FILE = 'integrity.json';
/** The build the storefront loads. */
export const SDK_SCRIPT = 'pixonaut.iife.js';
/** The files vendored into `contracts/pixonaut/sdk/` for type checks. */
export const SDK_TYPE_FILES = ['pixonaut.d.ts', 'schema.d.ts', 'LICENSE-MIT'];
/** The git tag a release carries in Pixonaut. */
const RELEASE_TAG_PREFIX = 'sdk-v';

/**
 * @typedef {{ version: string, released: string, files: Record<string, string> }} SdkRecord
 */

/** @param {string} version */
export function sdkBaseUrl(version) {
	return `${SDK_CDN_BASE}${version}/`;
}

/**
 * The lock for the release `record` describes.
 *
 * @param {SdkRecord} record
 * @returns {import('./files.mjs').SdkLock}
 */
export function sdkLockFrom(record) {
	/** @type {Record<string, string>} */
	const types = {};
	for (const file of [SDK_SCRIPT, ...SDK_TYPE_FILES]) {
		if (record.files[file] === undefined) {
			throw new Error(`SDK ${record.version}: its record lists no ${file}`);
		}
	}
	for (const file of SDK_TYPE_FILES) {
		types[file] = record.files[file];
	}
	return {
		version: record.version,
		release: `${RELEASE_TAG_PREFIX}${record.version}`,
		released: record.released,
		baseUrl: sdkBaseUrl(record.version),
		script: SDK_SCRIPT,
		integrity: record.files[SDK_SCRIPT],
		types,
	};
}

/**
 * Where `lock` and the release record of its version disagree. A published
 * version is immutable, so any difference means the pin is wrong or the
 * release was replaced.
 *
 * @param {import('./files.mjs').SdkLock} lock
 * @param {SdkRecord} record
 */
export function sdkDrift(lock, record) {
	const expected = sdkLockFrom(record);
	/** @type {string[]} */
	const problems = [];
	if (expected.integrity !== lock.integrity) {
		problems.push(
			`${lock.script}: the release says ${expected.integrity}, sdk.lock.json says ${lock.integrity}`,
		);
	}
	for (const [file, integrity] of Object.entries(expected.types)) {
		if (lock.types[file] !== integrity) {
			problems.push(
				`${file}: the release says ${integrity}, sdk.lock.json says ${lock.types[file]}`,
			);
		}
	}
	return problems;
}
