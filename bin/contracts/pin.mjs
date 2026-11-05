#!/usr/bin/env node
/**
 * Pins a published Pixonaut API release and an SDK release, and reports what
 * the new pin changes for the plugin.
 *
 *   pnpm contracts:pin [<api-version>] [--sdk <version>] [--cdn <origin>]
 *
 * Reads them from Pixonaut's public CDN, so it needs no token and no access
 * to Pixonaut's source. Each defaults to the version already pinned. Writes
 * `openapi.json`, `webhook-signature-vectors.json`, `spec.lock.json`,
 * `sdk.lock.json` and `sdk/*` under `contracts/pixonaut/`, then regenerates
 * the fake's types. Pinning the release already pinned changes no byte.
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { readJson } from './files.mjs';
import { runPin } from './pinning.mjs';

const PACKAGE_JSON = path.join(import.meta.dirname, '..', '..', 'package.json');
/** The fake's type generator, run after a pin when the project has one. */
const FAKE_TYPES_SCRIPT = 'fake:types';

const { values, positionals } = parseArgs({
	allowPositionals: true,
	options: {
		sdk: { type: 'string' },
		cdn: { type: 'string' },
	},
});
if (positionals.length > 1) {
	throw new Error(
		'Usage: pnpm contracts:pin [<api-version>] [--sdk <version>] [--cdn <origin>]',
	);
}

await runPin({
	version: positionals[0],
	sdkVersion: values.sdk,
	cdn: values.cdn,
});

console.log('');
const scripts = readJson(PACKAGE_JSON).scripts ?? {};
if (scripts[FAKE_TYPES_SCRIPT] === undefined) {
	console.log(
		`No "${FAKE_TYPES_SCRIPT}" script yet: the fake's types were not regenerated.`,
	);
} else {
	execFileSync('pnpm', ['run', '--silent', FAKE_TYPES_SCRIPT], {
		stdio: 'inherit',
	});
}
