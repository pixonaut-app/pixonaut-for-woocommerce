#!/usr/bin/env node
// `wp-env` with this checkout's WP_ENV_HOME, and the fake Pixonaut started and
// stopped with it. `start` first writes .wp-env.override.json, which points
// the plugin's API constants at this checkout's fake and can pin WooCommerce
// (bin/wp-env-override.mjs), and once started makes each site's uploads
// directory writable. `stop` and `destroy`
// also remove the fake's containers and then wp-env's networks, which the
// fake would otherwise keep alive.
import { writeFileSync } from 'node:fs';
import { makeUploadsWritable, spawn, wpEnvBin } from './env.mjs';
import {
	composeProject,
	fakeConstants,
	fakePort,
	OVERRIDE_FILE,
	removeNetwork,
	startFake,
	stopFake,
} from './fake-pixonaut.mjs';
import { overrideConfig } from './wp-env-override.mjs';

/** The WordPress containers, whose media library takes the products'
 *  pictures. */
const WORDPRESS_SERVICES = ['wordpress', 'tests-wordpress'];

const args = process.argv.slice(2);
const [command] = args;

const stopping = command === 'stop' || command === 'destroy';
// Before wp-env runs: the networks are found through the wp-env project's
// directory, which `destroy` deletes.
const networks = stopping ? stopFake() : [];
if (command === 'start') {
	writeFileSync(
		OVERRIDE_FILE,
		`${JSON.stringify(overrideConfig(fakeConstants(fakePort())), null, '\t')}\n`,
	);
}

let status = spawn(wpEnvBin, args);
// Every network is tried, even after one fails.
const removed = networks.map(removeNetwork);
if (status === 0 && removed.includes(false)) {
	status = 1;
}
if (status === 0 && command === 'start') {
	if (!makeUploadsWritable(composeProject(), WORDPRESS_SERVICES)) {
		status = 1;
	}
	await startFake(fakePort());
}
process.exit(status);
