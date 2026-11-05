/**
 * Pixonaut's published releases, read from its public CDN.
 *
 * Each API version is served, immutably, under
 * `https://cdn.pixonaut.app/api/<version>/`, and each SDK version under
 * `/sdk/<version>/`, with an `integrity.json` beside the files naming each
 * one's SRI. Nothing here needs a token or access to Pixonaut's source.
 */
import { sri384 } from './files.mjs';
import { SDK_RECORD_FILE, SDK_TYPE_FILES, sdkLockFrom } from './sdk.mjs';

/** The public CDN; `--cdn` names another for a staging one or a test's fake. */
export const CDN_ORIGIN = 'https://cdn.pixonaut.app';
const API_PREFIX = '/api/';
const SDK_PREFIX = '/sdk/';
const SPEC_NAME = 'openapi.json';
const VECTORS_NAME = 'webhook-signature-vectors.json';
const INTEGRITY_NAME = 'integrity.json';
const HTTP_NOT_FOUND = 404;

/** @param {string} version The address a lock records for an API release. */
export function apiBaseUrl(version) {
	return `${CDN_ORIGIN}${API_PREFIX}${version}/`;
}

/** @param {string} url */
async function download(url) {
	const response = await fetch(url);
	if (!response.ok) {
		throw new Error(`${url} answered ${response.status}`);
	}
	return Buffer.from(await response.arrayBuffer());
}

/**
 * The file `name`, held to the SRI its release's record gives it.
 *
 * @param {string} label What the error calls the release.
 * @param {string} base
 * @param {{ files: Record<string, string> }} record
 * @param {string} name
 */
async function verified(label, base, record, name) {
	const bytes = await download(`${base}${name}`);
	if (sri384(bytes) !== record.files[name]) {
		throw new Error(`${label}: ${name} does not match its integrity`);
	}
	return bytes;
}

/**
 * A release's `integrity.json`.
 *
 * @param {string} label
 * @param {string} url
 */
async function readRecord(label, url) {
	const response = await fetch(url);
	if (response.status === HTTP_NOT_FOUND) {
		throw new Error(`${label} is not published: ${url} answered 404`);
	}
	if (!response.ok) {
		throw new Error(`${url} answered ${response.status}`);
	}
	return JSON.parse(await response.text());
}

/**
 * @param {string} version
 * @param {string} [cdn]
 * @returns {Promise<{ spec: Buffer, vectors: Buffer }>}
 */
export async function readApiRelease(version, cdn = CDN_ORIGIN) {
	const label = `API ${version}`;
	const base = `${cdn}${API_PREFIX}${version}/`;
	const record = await readRecord(label, `${base}${INTEGRITY_NAME}`);
	return {
		spec: await verified(label, base, record, SPEC_NAME),
		vectors: await verified(label, base, record, VECTORS_NAME),
	};
}

/**
 * @param {string} version
 * @param {string} [cdn]
 * @returns {Promise<import('./sdk.mjs').SdkRecord>}
 */
export function readSdkRecord(version, cdn = CDN_ORIGIN) {
	return readRecord(
		`SDK ${version}`,
		`${cdn}${SDK_PREFIX}${version}/${SDK_RECORD_FILE}`,
	);
}

/**
 * The lock for an SDK release, and its type files held to their SRI.
 *
 * @param {string} version
 * @param {string} [cdn]
 */
export async function readSdkRelease(version, cdn = CDN_ORIGIN) {
	const record = await readSdkRecord(version, cdn);
	const lock = sdkLockFrom(record);
	const base = `${cdn}${SDK_PREFIX}${version}/`;
	/** @type {Record<string, Buffer>} */
	const typeFiles = {};
	for (const file of SDK_TYPE_FILES) {
		typeFiles[file] = await verified(`SDK ${version}`, base, record, file);
	}
	return { lock, typeFiles };
}
