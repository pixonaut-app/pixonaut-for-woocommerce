#!/usr/bin/env node
// Writes the `operations`, `webhooks` and `keys` of contracts/pixonaut/consumed.json
// from what the code calls and handles: the client's operation registry
// (`Operations::plugin()`), the SDK's (`Sdk::OPERATIONS`) and the webhook
// event types (`Endpoint::EVENT_TYPES`, plus `webhook.ping`). `pending` is
// kept, but for the entries the code now uses and the spec overlay does not
// stand in for.
//
// `--check` writes nothing and exits 1 when the file is out of date.
// Needs wp-env running: the registries are read by PHP in tests-cli.
import fs from 'node:fs';
import { pluginDir, wpEnvOutput } from '../env.mjs';
import {
	awaitedByOverlay,
	buildConsumed,
	CONSUMED_FILE,
	formatConsumed,
	readConsumed,
} from './consumed.mjs';
import { readJson, SPEC_FILE } from './files.mjs';
import { readOverlay } from './overlay.mjs';

const SCRIPT = 'bin/contracts/consumed-data.php';

const result = wpEnvOutput([
	'run',
	'tests-cli',
	`--env-cwd=${pluginDir}`,
	'php',
	SCRIPT,
]);
if (result.status !== 0) {
	console.error('Reading the operation registries in wp-env failed.');
	process.exit(result.status || 1);
}

const next = formatConsumed(
	buildConsumed(
		JSON.parse(result.stdout),
		readConsumed(),
		awaitedByOverlay(readJson(SPEC_FILE), readOverlay()),
	),
);
const current = fs.readFileSync(CONSUMED_FILE, 'utf8');

if (process.argv.includes('--check')) {
	if (next !== current) {
		console.error(
			'contracts/pixonaut/consumed.json is out of date. Run pnpm contracts:consumed and commit the result.',
		);
		process.exit(1);
	}
	console.log('consumed.json is up to date.');
} else if (next === current) {
	console.log('consumed.json was already up to date.');
} else {
	fs.writeFileSync(CONSUMED_FILE, next);
	console.log('Updated contracts/pixonaut/consumed.json.');
}
