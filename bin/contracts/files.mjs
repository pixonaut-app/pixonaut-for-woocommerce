/**
 * The pinned files under `contracts/pixonaut/`, and how their locks are
 * computed and written.
 */
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export const CONTRACTS_DIR = path.join(
	import.meta.dirname,
	'..',
	'..',
	'contracts',
	'pixonaut',
);
export const SPEC_FILE = path.join(CONTRACTS_DIR, 'openapi.json');
export const VECTORS_FILE = path.join(
	CONTRACTS_DIR,
	'webhook-signature-vectors.json',
);
export const SPEC_LOCK_FILE = path.join(CONTRACTS_DIR, 'spec.lock.json');
export const SDK_LOCK_FILE = path.join(CONTRACTS_DIR, 'sdk.lock.json');
/** The SDK's vendored type declarations. */
export const SDK_TYPES_DIR = path.join(CONTRACTS_DIR, 'sdk');

/**
 * @typedef {{
 *   version: string,
 *   baseUrl: string,
 *   sha256: string,
 *   webhookSignatureVectors: { sha256: string },
 * }} SpecLock
 * @typedef {{
 *   version: string,
 *   release: string,
 *   released: string,
 *   baseUrl: string,
 *   script: string,
 *   integrity: string,
 *   types: Record<string, string>,
 * }} SdkLock
 */

/** @param {Uint8Array} bytes */
export function sha256(bytes) {
	return createHash('sha256').update(bytes).digest('hex');
}

/**
 * Subresource Integrity form, as the SDK release publishes it.
 *
 * @param {Uint8Array} bytes
 */
export function sri384(bytes) {
	return `sha384-${createHash('sha384').update(bytes).digest('base64')}`;
}

/**
 * @param {string} file
 * @returns {any}
 */
export function readJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/**
 * Written the way every lock in `contracts/pixonaut/` is: tab-indented, with
 * a final newline, so a re-pin of the same release changes no byte.
 *
 * @param {string} file
 * @param {unknown} value
 */
export function writeJson(file, value) {
	fs.writeFileSync(file, `${JSON.stringify(value, null, '\t')}\n`);
}

/** @returns {SpecLock} */
export function readSpecLock() {
	return readJson(SPEC_LOCK_FILE);
}

/** @returns {SdkLock} */
export function readSdkLock() {
	return readJson(SDK_LOCK_FILE);
}
