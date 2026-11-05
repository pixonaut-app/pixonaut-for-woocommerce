#!/usr/bin/env node
// `wp-env` with this checkout's WP_ENV_HOME, and the fake Pixonaut started and
// stopped with it. `start` first writes .wp-env.override.json, which points
// the plugin's API constants at this checkout's fake and can pin WooCommerce
// (bin/wp-env-override.mjs), and once started makes each site's uploads
// directory writable. `stop` and `destroy`
// also remove the fake's containers and then wp-env's networks, which the
// fake would otherwise keep alive.
import { writeFileSync } from 'node:fs';
import { dockerBin, spawn, wpEnvBin } from './env.mjs';
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

/** World-writable: Apache runs as the host's user, and `wp-content` is
 *  root's, inside a container that is thrown away. */
const UPLOADS_MODE = '0777';

/** Makes each site's `wp-content/uploads`, which WordPress cannot create in
 *  a `wp-content` it does not own. */
function makeUploadsWritable() {
	const project = composeProject();
	return WORDPRESS_SERVICES.map((service) =>
		spawn(dockerBin, [
			'exec',
			'--user',
			'root',
			`${project}-${service}-1`,
			'sh',
			'-c',
			`mkdir -p wp-content/uploads && chmod ${UPLOADS_MODE} wp-content/uploads`,
		]),
	).every((exit) => exit === 0);
}

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
	if (!makeUploadsWritable()) {
		status = 1;
	}
	await startFake(fakePort());
}
process.exit(status);
