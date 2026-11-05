/**
 * Moving the pin (`pnpm contracts:pin`) and checking it
 * (`pnpm contract:check`), both from Pixonaut's published releases.
 */
import fs from 'node:fs';
import path from 'node:path';
import { compareSpecs, describeComparison } from './compare.mjs';
import { readConsumed } from './consumed.mjs';
import {
	CONTRACTS_DIR,
	readJson,
	SDK_LOCK_FILE,
	SDK_TYPES_DIR,
	SPEC_FILE,
	SPEC_LOCK_FILE,
	sha256,
	VECTORS_FILE,
	writeJson,
} from './files.mjs';
import {
	apiBaseUrl,
	CDN_ORIGIN,
	readApiRelease,
	readSdkRecord,
	readSdkRelease,
} from './release.mjs';
import { sdkDrift } from './sdk.mjs';

/** Where `file` of the contract directory sits in `dir`. @param {string} dir @param {string} file */
const inDir = (dir, file) => path.join(dir, path.basename(file));

/**
 * @typedef {{
 *   dir?: string,
 *   cdn?: string,
 *   compare?: (pinned: any, next: any, consumed: any) => { breaking: boolean },
 *   describe?: (result: any) => string[],
 *   log?: (line: string) => void,
 * }} Options
 */

/**
 * Pins API release `version` and an SDK release. Everything is read, checked
 * and compared before any pinned file changes, so a failure leaves the
 * previous pin in place and a retry reports the same changes.
 *
 * @param {Options & { version?: string, sdkVersion?: string }} options
 */
export async function runPin({
	dir = CONTRACTS_DIR,
	version,
	sdkVersion,
	cdn = CDN_ORIGIN,
	compare = compareSpecs,
	describe = describeComparison,
	log = console.log,
}) {
	const previous = readJson(inDir(dir, SPEC_LOCK_FILE));
	const previousSdk = readJson(inDir(dir, SDK_LOCK_FILE));
	const target = version ?? previous.version;
	const { spec, vectors } = await readApiRelease(target, cdn);
	const { lock: sdkLock, typeFiles } = await readSdkRelease(
		sdkVersion ?? previousSdk.version,
		cdn,
	);

	const report = [''];
	if (target === previous.version && sha256(spec) === previous.sha256) {
		report.push(`Spec: unchanged at ${target}.`);
	} else {
		report.push(
			`Spec: ${previous.version} -> ${target}. What changes for the plugin:`,
			...describe(
				compare(
					readJson(inDir(dir, SPEC_FILE)),
					JSON.parse(spec.toString()),
					readConsumed(),
				),
			),
		);
	}
	if (target !== previous.version) {
		report.push(
			`Set Api\\Spec::VERSION to ${target}; a unit test holds it to the pin.`,
		);
	}
	report.push('');
	if (
		sdkLock.version === previousSdk.version &&
		sdkLock.integrity === previousSdk.integrity
	) {
		report.push(`SDK: unchanged at ${sdkLock.version}.`);
	} else {
		report.push(
			`SDK: ${previousSdk.version} -> ${sdkLock.version}; ${sdkLock.script} integrity ${sdkLock.integrity} (was ${previousSdk.integrity}).`,
			'Check the SDK changes against the storefront code and the vendored types.',
		);
	}

	fs.writeFileSync(inDir(dir, SPEC_FILE), spec);
	fs.writeFileSync(inDir(dir, VECTORS_FILE), vectors);
	writeJson(inDir(dir, SPEC_LOCK_FILE), {
		version: target,
		baseUrl: apiBaseUrl(target),
		sha256: sha256(spec),
		webhookSignatureVectors: { sha256: sha256(vectors) },
	});
	writeJson(inDir(dir, SDK_LOCK_FILE), sdkLock);
	const typesDir = inDir(dir, SDK_TYPES_DIR);
	fs.mkdirSync(typesDir, { recursive: true });
	for (const [file, bytes] of Object.entries(typeFiles)) {
		fs.writeFileSync(path.join(typesDir, file), bytes);
	}
	for (const line of report) {
		log(line);
	}
}

/**
 * Whether the pin is what Pixonaut published, and, with `api`, whether that
 * newer release breaks what the plugin consumes.
 *
 * @param {Options & { api?: string }} options
 * @returns {Promise<boolean>}
 */
export async function checkPin({
	dir = CONTRACTS_DIR,
	api,
	cdn = CDN_ORIGIN,
	compare = compareSpecs,
	describe = describeComparison,
	log = console.log,
}) {
	const lock = readJson(inDir(dir, SPEC_LOCK_FILE));
	const sdk = readJson(inDir(dir, SDK_LOCK_FILE));
	let failed = false;

	/** @param {unknown} error */
	const refuse = (error) => {
		log(error instanceof Error ? error.message : String(error));
		failed = true;
	};

	try {
		const { spec, vectors } = await readApiRelease(lock.version, cdn);
		if (
			sha256(spec) === lock.sha256 &&
			sha256(vectors) === lock.webhookSignatureVectors.sha256
		) {
			log(`API ${lock.version}: the pinned files are the release.`);
		} else {
			log(
				`API ${lock.version}: the pinned files differ from the release; run pnpm contracts:pin ${lock.version}.`,
			);
			failed = true;
		}
	} catch (error) {
		refuse(error);
	}

	if (api !== undefined && api !== lock.version) {
		try {
			const { spec } = await readApiRelease(api, cdn);
			const result = compare(
				readJson(inDir(dir, SPEC_FILE)),
				JSON.parse(spec.toString()),
				readConsumed(),
			);
			log(
				result.breaking
					? `API ${api}: breaks what the plugin consumes.`
					: `API ${api}: changed; nothing the plugin consumes breaks.`,
			);
			for (const line of describe(result)) {
				log(line);
			}
			failed ||= result.breaking;
		} catch (error) {
			refuse(error);
		}
	}

	try {
		const drift = sdkDrift(sdk, await readSdkRecord(sdk.version, cdn));
		if (drift.length === 0) {
			log(`SDK ${sdk.version}: matches its release record.`);
		} else {
			log(`SDK ${sdk.version}: differs from its release record:`);
			for (const problem of drift) {
				log(`  ${problem}`);
			}
			failed = true;
		}
	} catch (error) {
		refuse(error);
	}

	return !failed;
}
