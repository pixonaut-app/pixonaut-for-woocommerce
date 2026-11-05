#!/usr/bin/env node
// Runs a PHP tool inside wp-env's tests-cli container, in the plugin's
// directory, so the host needs no PHP. `--hpos=on|off` sets PIXONAUT_WC_HPOS
// for the integration suite.
import { pluginDir, run, wpEnvBin } from './env.mjs';

const args = process.argv.slice(2);
const env = [];
if (args[0]?.startsWith('--hpos=')) {
	const hpos = args.shift().slice('--hpos='.length);
	env.push(`PIXONAUT_WC_HPOS=${hpos === 'on' ? '1' : '0'}`);
}

run(wpEnvBin, [
	'run',
	'tests-cli',
	`--env-cwd=${pluginDir}`,
	'env',
	...env,
	...args,
]);
