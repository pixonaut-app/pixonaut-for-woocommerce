#!/usr/bin/env node
// The fake Pixonaut for this checkout: a container on this checkout's wp-env
// network. WordPress reaches it as http://fake-pixonaut:<port>, the browser
// and the test runner as http://localhost:<port>, the port being
// FAKE_PIXONAUT_PORT. The port is published on loopback only, so nothing
// else on the network can reach the fake or its control API.
//
// The container is not part of wp-env's compose project, so `docker compose
// down` neither removes it nor, while it is attached, the network. `stop`
// removes both, and `start` replaces whatever an earlier run left behind.
//
//   node bin/fake-pixonaut.mjs start | stop | logs
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { dockerBin, root, wpEnvHome } from './env.mjs';

/** wp-env's own defaults are 8888 and 8889; the fake takes the next port
 *  when nothing sets FAKE_PIXONAUT_PORT. */
const DEFAULT_FAKE_PIXONAUT_PORT = 8890;

/** The name WordPress's containers know the fake by. */
export const FAKE_HOST = 'fake-pixonaut';

/** Marks every fake container started for this checkout, so `stop` finds
 *  them even after the wp-env project they were attached to is gone. */
const CHECKOUT_LABEL = 'pixonaut-wc.checkout';

/** The host address the fake's port is published on. */
const LOOPBACK = '127.0.0.1';

/** How long `start` waits for the fake to answer before giving up. */
const READY_TIMEOUT_MS = 30_000;
const READY_POLL_MS = 250;

/** How long one request for the fake's clock may take. */
const READY_ATTEMPT_MS = 5_000;

/** Written by `wp-env.mjs start`: the constants WordPress was given. */
export const OVERRIDE_FILE = join(root, '.wp-env.override.json');

export function fakePort() {
	return Number(process.env.FAKE_PIXONAUT_PORT ?? DEFAULT_FAKE_PIXONAUT_PORT);
}

/** The port WordPress was pointed at when its sites were started, so a fake
 *  restarted on its own listens where WordPress looks; else `fakePort()`. */
function configuredPort() {
	if (!existsSync(OVERRIDE_FILE)) {
		return fakePort();
	}
	const { config } = JSON.parse(readFileSync(OVERRIDE_FILE, 'utf8'));
	return Number(new URL(config.PIXONAUT_WC_API_BASE).port);
}

/** The constants that point the plugin at the fake, for wp-env's `config`. */
export function fakeConstants(port) {
	const browser = `http://localhost:${port}`;
	return {
		PIXONAUT_WC_API_BASE: browser,
		PIXONAUT_WC_SERVER_API_BASE: `http://${FAKE_HOST}:${port}`,
		PIXONAUT_WC_STUDIO_BASE: browser,
	};
}

/** The network wp-env's compose project runs on, and the fake joins. */
function networkOf(project) {
	return `${project}_default`;
}

/** wp-env's compose project for this checkout: the one directory in its
 *  home, named after the checkout and a hash of its path. */
export function composeProject() {
	// As wp-env reads it: relative to the checkout, or absolute.
	const home = resolve(root, wpEnvHome());
	const projects = readdirSync(home, { withFileTypes: true })
		.filter((entry) => entry.isDirectory())
		.map((entry) => entry.name);
	if (projects.length !== 1) {
		throw new Error(
			`Expected one wp-env project in ${home}, found ${projects.length}. Run pnpm env:start first.`,
		);
	}
	return projects[0];
}

function docker(args, options = {}) {
	const result = spawnSync(dockerBin, args, {
		cwd: root,
		encoding: 'utf8',
		stdio: options.capture
			? ['ignore', 'pipe', 'pipe']
			: options.quiet
				? 'ignore'
				: 'inherit',
	});
	if (result.error) {
		throw result.error;
	}
	if (result.status !== 0 && !options.allowFailure) {
		throw new Error(`docker ${args[0]} exited with ${result.status}`);
	}
	// Captured: the whole result, so a caller can read Docker's message.
	return options.capture ? result : result.status;
}

/**
 * Resolves once the fake answers. A request may never settle while nothing
 * else keeps the event loop running, and Node then exits with 13 on the
 * unsettled top-level await: so each attempt is timed out, on a timer that
 * does not hold the loop itself, and an interval holds it for the whole wait.
 */
export async function waitUntilReady(
	port,
	{
		timeoutMs = READY_TIMEOUT_MS,
		pollMs = READY_POLL_MS,
		attemptMs = READY_ATTEMPT_MS,
	} = {},
) {
	const holdEventLoop = setInterval(() => {}, pollMs);
	try {
		const deadline = Date.now() + timeoutMs;
		while (Date.now() < deadline) {
			try {
				const response = await fetch(
					`http://${LOOPBACK}:${port}/__fake/clock`,
					{ signal: AbortSignal.timeout(attemptMs) },
				);
				if (response.ok) {
					return;
				}
			} catch {
				// Not listening yet, or no answer in time.
			}
			await new Promise((resolve) => setTimeout(resolve, pollMs));
		}
		throw new Error(`The fake did not answer on port ${port}.`);
	} finally {
		clearInterval(holdEventLoop);
	}
}

export async function startFake(port = configuredPort()) {
	const project = composeProject();
	const container = `${project}-${FAKE_HOST}`;
	const image = `${project}-${FAKE_HOST}`;
	const build = spawnSync(
		process.execPath,
		[join(root, 'tests', 'fake-pixonaut', 'scripts', 'build.mjs')],
		{ cwd: root, stdio: 'inherit' },
	);
	if (build.status !== 0) {
		throw new Error('Building the fake failed.');
	}
	docker(['build', '--quiet', '-t', image, 'tests/fake-pixonaut/dist'], {
		quiet: true,
	});
	removeFakes(container);
	docker(
		[
			'run',
			'--detach',
			'--name',
			container,
			'--label',
			`${CHECKOUT_LABEL}=${root}`,
			'--network',
			networkOf(project),
			'--network-alias',
			FAKE_HOST,
			'--publish',
			`${LOOPBACK}:${port}:${port}`,
			'--env',
			`FAKE_PIXONAUT_PORT=${port}`,
			'--env',
			`FAKE_PIXONAUT_PUBLIC_URL=${fakeConstants(port).PIXONAUT_WC_API_BASE}`,
			image,
		],
		{ quiet: true },
	);
	await waitUntilReady(port);
	console.log(`Fake Pixonaut started at http://localhost:${port}`);
}

/** Matches Docker's message for a network that does not exist. */
const NO_SUCH_NETWORK = /no such network|not found/i;

/** Lines of a captured Docker command's stdout. */
function lines(args) {
	return docker(args, { capture: true, allowFailure: true })
		.stdout.split('\n')
		.map((line) => line.trim())
		.filter(Boolean);
}

/**
 * Removes this checkout's fake containers: the one named `named`, if any,
 * and every one carrying this checkout's label. Returns the wp-env networks
 * the labelled ones were attached to, which may belong to a project that is
 * gone and so would otherwise never be removed.
 */
function removeFakes(named) {
	const labelled = lines([
		'ps',
		'--all',
		'--quiet',
		'--filter',
		`label=${CHECKOUT_LABEL}=${root}`,
	]);
	const networks =
		labelled.length > 0
			? lines([
					'inspect',
					'--format',
					'{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}',
					...labelled,
				]).filter((network) => network.endsWith('_default'))
			: [];
	const containers = [...(named ? [named] : []), ...labelled];
	if (containers.length > 0) {
		docker(['rm', '--force', ...containers], {
			quiet: true,
			allowFailure: true,
		});
	}
	return [...new Set(networks)];
}

/** This checkout's wp-env project, or null when there is none to find. */
function projectOrNull() {
	try {
		return composeProject();
	} catch {
		return null;
	}
}

/** Removes this checkout's fakes; returns the networks to remove once wp-env
 *  has stopped, the project's own included. */
export function stopFake(project = projectOrNull()) {
	const networks = removeFakes(project ? `${project}-${FAKE_HOST}` : null);
	return [...new Set([...(project ? [networkOf(project)] : []), ...networks])];
}

/**
 * Removes a network that `docker compose down` left behind. A network
 * already gone is fine; any other failure is reported on stderr.
 *
 * @returns {boolean} Whether the network is gone.
 */
export function removeNetwork(network) {
	const result = docker(['network', 'rm', network], {
		capture: true,
		allowFailure: true,
	});
	if (result.status === 0 || NO_SUCH_NETWORK.test(result.stderr)) {
		return true;
	}
	console.error(
		`Could not remove the Docker network ${network}: ${result.stderr.trim()}`,
	);
	return false;
}

if (import.meta.url === `file://${process.argv[1]}`) {
	const command = process.argv[2];
	if (command === 'start') {
		await startFake();
	} else if (command === 'stop') {
		stopFake();
	} else if (command === 'logs') {
		docker(['logs', '--follow', `${composeProject()}-${FAKE_HOST}`]);
	} else {
		console.error('Usage: fake-pixonaut.mjs start | stop | logs');
		process.exit(1);
	}
}
