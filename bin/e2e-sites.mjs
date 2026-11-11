#!/usr/bin/env node
// Extra tests sites, each with its own fake Pixonaut, so the e2e suite can
// run in parallel: every site and fake is stateful and reset per test, so
// two tests can only run at once on two of them.
//
// Each site is a wp-env environment of its own, from a config this script
// writes under .wp-env-e2e/ (the tests site of .wp-env.json, as the only
// site), with its own wp-env home, so the checkout's own wp-env is left
// alone. Ports come from E2E_SITES_PORT_BASE: site n listens on that port plus
// 2(n - 1), its fake on the next. E2E_SITES_COUNT, when set, is how many
// ports there are; PIXONAUT_E2E_SITES how many sites to run.
//
//   e2e-sites.mjs start           start the sites and their fakes
//   e2e-sites.mjs stop            stop them
//   e2e-sites.mjs test [args...]  start them, run `playwright test [args]`
//                                 split across them (--shard), and stop the
//                                 ones it started
import { spawn as spawnAsync } from 'node:child_process';
import {
	existsSync,
	mkdirSync,
	openSync,
	readdirSync,
	readFileSync,
	writeFileSync,
} from 'node:fs';
import { basename, join } from 'node:path';
import { dockerBin, makeUploadsWritable, root, wpEnvBin } from './env.mjs';
import {
	buildFake,
	fakeConstants,
	fakeImage,
	removeNetwork,
	runFake,
	stopFake,
} from './fake-pixonaut.mjs';
import { overrideConfig } from './wp-env-override.mjs';

/** Where the sites' configs, wp-env homes and logs live. */
export const SITES_DIR = join(root, '.wp-env-e2e');

/** A site's WordPress port and its fake's. */
const PORTS_PER_SITE = 2;

/** The first port without E2E_SITES_PORT_BASE: past wp-env's defaults (8888,
 *  8889) and the fake's (8890). */
const DEFAULT_FIRST_PORT = 8900;

/** How many sites run without PIXONAUT_E2E_SITES. Each takes about 1 GB with
 *  its browser; four bring a full run of the suite to about a quarter. */
const DEFAULT_SITES = 4;

/** A site's only WordPress container. */
const WORDPRESS_SERVICE = 'wordpress';

/** Where a site's fake reaches it, on the site's Docker network. */
export const SITE_ON_NETWORK = `http://${WORDPRESS_SERVICE}`;

const MS_PER_SECOND = 1000;

/**
 * @typedef {{ number: number, port: number, fakePort: number, dir: string }} Site
 */

/**
 * The sites to run, from the environment.
 *
 * @param {NodeJS.ProcessEnv} env
 * @returns {Site[]}
 */
export function plannedSites(env = process.env) {
	const firstPort = Number(env.E2E_SITES_PORT_BASE ?? DEFAULT_FIRST_PORT);
	const wanted = Number(env.PIXONAUT_E2E_SITES ?? DEFAULT_SITES);
	if (!Number.isInteger(firstPort) || !Number.isInteger(wanted) || wanted < 1) {
		throw new Error(
			'E2E_SITES_PORT_BASE and PIXONAUT_E2E_SITES must be whole numbers, and at least one site.',
		);
	}
	if (
		env.E2E_SITES_COUNT !== undefined &&
		wanted * PORTS_PER_SITE > Number(env.E2E_SITES_COUNT)
	) {
		throw new Error(
			`${wanted} e2e sites need ${wanted * PORTS_PER_SITE} ports; E2E_SITES_COUNT has ${env.E2E_SITES_COUNT}.`,
		);
	}
	return Array.from({ length: wanted }, (_, index) => ({
		number: index + 1,
		port: firstPort + index * PORTS_PER_SITE,
		fakePort: firstPort + index * PORTS_PER_SITE + 1,
		// Named after the checkout: wp-env names the site's containers after
		// this directory.
		dir: join(SITES_DIR, `${basename(root)}-e2e-${index + 1}`),
	}));
}

/**
 * The wp-env config of `site`: .wp-env.json's tests site as its only one,
 * pointed at the site's own fake.
 *
 * @param {Record<string, any>} base .wp-env.json
 * @param {Site} site
 */
export function siteConfig(base, site) {
	const { config, plugins, themes } = overrideConfig(
		fakeConstants(site.fakePort),
	);
	const tests = base.env?.tests ?? {};
	return {
		core: base.core,
		phpVersion: base.phpVersion,
		plugins: plugins ?? base.plugins,
		themes: themes ?? base.themes,
		port: site.port,
		testsEnvironment: false,
		config: { ...base.config, ...tests.config, ...config },
		mappings: tests.mappings ?? {},
	};
}

/**
 * Marks a site's fake, so stopping the checkout's own fake leaves it.
 *
 * @param {Site} site
 */
function fakeLabel(site) {
	return `${root}#e2e-${site.number}`;
}

/** @param {Site} site */
function siteHome(site) {
	return join(site.dir, 'home');
}

/** @param {Site} site */
function siteConfigPath(site) {
	return join(site.dir, '.wp-env.json');
}

/**
 * The site's wp-env project: the one directory in its home, once started.
 *
 * @param {Site} site
 * @returns {string | undefined}
 */
function projectOf(site) {
	const home = siteHome(site);
	const [project] = existsSync(home)
		? readdirSync(home, { withFileTypes: true })
				.filter((entry) => entry.isDirectory())
				.map((entry) => entry.name)
		: [];
	return project;
}

/**
 * Runs `command` without blocking, its output appended to `log`; resolves
 * with its exit status and how long it took.
 *
 * @param {string} command
 * @param {string[]} args
 * @param {{ log: string, env?: NodeJS.ProcessEnv }} options
 * @returns {Promise<{ status: number, seconds: number }>}
 */
function runLogged(command, args, { log, env = process.env }) {
	const started = Date.now();
	const out = openSync(log, 'a');
	return new Promise((resolve) => {
		const child = spawnAsync(command, args, {
			cwd: root,
			env,
			stdio: ['ignore', out, out],
		});
		const done = (/** @type {number} */ status) =>
			resolve({
				status,
				seconds: Math.round((Date.now() - started) / MS_PER_SECOND),
			});
		child.on('error', () => done(1));
		child.on('close', (code) => done(code ?? 1));
	});
}

/**
 * wp-env for `site`, with its home and port, whatever this shell sets.
 *
 * @param {Site} site
 * @param {string[]} args
 */
function siteWpEnv(site, args) {
	/** @type {NodeJS.ProcessEnv} */
	const env = {
		...process.env,
		WP_ENV_HOME: siteHome(site),
		WP_ENV_PORT: String(site.port),
	};
	delete env.WP_ENV_TESTS_PORT;
	return runLogged(wpEnvBin, [...args, '--config', siteConfigPath(site)], {
		log: join(site.dir, 'wp-env.log'),
		env,
	});
}

/**
 * Whether the site's WordPress container is running.
 *
 * @param {Site} site
 * @returns {Promise<boolean>}
 */
function running(site) {
	const project = projectOf(site);
	if (project === undefined) {
		return Promise.resolve(false);
	}
	return new Promise((resolve) => {
		const child = spawnAsync(
			dockerBin,
			[
				'inspect',
				'--format',
				'{{.State.Running}}',
				`${project}-${WORDPRESS_SERVICE}-1`,
			],
			{ stdio: ['ignore', 'pipe', 'ignore'] },
		);
		let out = '';
		child.stdout.on('data', (chunk) => {
			out += chunk;
		});
		child.on('error', () => resolve(false));
		child.on('close', () => resolve(out.trim() === 'true'));
	});
}

/**
 * The sites whose WordPress is not running.
 *
 * @param {Site[]} sites
 */
export async function stoppedSites(sites) {
	const up = await Promise.all(sites.map(running));
	return sites.filter((_, index) => !up[index]);
}

/**
 * Starts the sites and their fakes; throws when one does not start.
 *
 * @param {Site[]} sites
 */
export async function startSites(sites) {
	const base = JSON.parse(readFileSync(join(root, '.wp-env.json'), 'utf8'));
	for (const site of sites) {
		mkdirSync(site.dir, { recursive: true });
		writeFileSync(
			siteConfigPath(site),
			`${JSON.stringify(siteConfig(base, site), null, '\t')}\n`,
		);
	}
	console.log(
		`Starting ${sites.length} e2e sites on ports ${sites.map(({ port }) => port).join(', ')}.`,
	);
	const started = await Promise.all(
		sites.map((site) => siteWpEnv(site, ['start'])),
	);
	const projects = sites.map((site, index) => {
		const { status, seconds } = started[index] ?? { status: 1, seconds: 0 };
		const project = projectOf(site);
		if (status !== 0 || project === undefined) {
			throw new Error(
				`site ${site.number} did not start; see ${join(site.dir, 'wp-env.log')}.`,
			);
		}
		console.log(`Site ${site.number} up in ${seconds}s.`);
		return project;
	});
	// One image for every site's fake, bundled once.
	const image = fakeImage(projects[0] ?? '');
	buildFake(image);
	for (const [index, site] of sites.entries()) {
		const project = projects[index] ?? '';
		if (!makeUploadsWritable(project, [WORDPRESS_SERVICE])) {
			throw new Error(`could not make site ${site.number}'s uploads writable.`);
		}
		await runFake(site.fakePort, { project, image, label: fakeLabel(site) });
	}
}

/**
 * Stops the sites and their fakes; answers whether every one stopped.
 *
 * @param {Site[]} sites
 */
export async function stopSites(sites) {
	// The fakes first: attached, they keep the sites' networks alive.
	const networks = sites.map((site) =>
		stopFake(projectOf(site) ?? null, fakeLabel(site)),
	);
	const stopped = await Promise.all(
		sites
			.filter((site) => projectOf(site) !== undefined)
			.map((site) => siteWpEnv(site, ['stop'])),
	);
	const removed = networks.flat().map(removeNetwork);
	return (
		stopped.every(({ status }) => status === 0) && !removed.includes(false)
	);
}

/**
 * Runs `playwright test args` split across the sites, one shard each, and
 * prints each shard's output as it finishes. Resolves true when all passed.
 *
 * @param {Site[]} sites
 * @param {string[]} args
 */
export async function testOnSites(sites, args) {
	const playwright = join(root, 'node_modules', '.bin', 'playwright');
	const shards = sites.map(async (site) => {
		const shard = `${site.number}/${sites.length}`;
		const log = join(site.dir, 'playwright.log');
		writeFileSync(log, '');
		const result = await runLogged(
			playwright,
			[
				'test',
				`--shard=${shard}`,
				`--output=test-results/e2e-${site.number}`,
				'--pass-with-no-tests',
				...args,
			],
			{
				log,
				env: {
					...process.env,
					PIXONAUT_E2E_BASE_URL: `http://localhost:${site.port}`,
					PIXONAUT_E2E_FAKE_BASE_URL: fakeConstants(site.fakePort)
						.PIXONAUT_WC_API_BASE,
					PIXONAUT_E2E_SITE_ON_NETWORK: SITE_ON_NETWORK,
					PLAYWRIGHT_HTML_OUTPUT_DIR: `playwright-report/e2e-${site.number}`,
				},
			},
		);
		console.log(
			`\n--- e2e shard ${shard} (site ${site.number}, ${result.seconds}s)`,
		);
		console.log(readFileSync(log, 'utf8'));
		return result;
	});
	const results = await Promise.all(shards);
	console.log('\n--- e2e shards');
	results.forEach(({ status, seconds }, index) => {
		console.log(
			`${status === 0 ? 'pass' : 'FAIL'}  shard ${index + 1}/${sites.length} (${seconds}s)`,
		);
	});
	return results.every(({ status }) => status === 0);
}

if (import.meta.url === `file://${process.argv[1]}`) {
	const [command, ...args] = process.argv.slice(2);
	const sites = plannedSites();
	// Sites that were running before `test` stay up for the next run.
	const toStop = command === 'test' ? await stoppedSites(sites) : sites;
	if (command === 'test') {
		// Interrupted (Ctrl-C reaches Playwright too), it still stops the sites
		// it started, which would otherwise count as already running next time.
		for (const signal of /** @type {const} */ (['SIGINT', 'SIGTERM'])) {
			process.once(signal, async () => {
				await stopSites(toStop);
				process.exit(1);
			});
		}
	}
	let passed = false;
	try {
		if (command === 'start' || command === 'test') {
			await startSites(sites);
		}
		if (command === 'start') {
			passed = true;
		} else if (command === 'test') {
			passed = await testOnSites(sites, args);
		} else if (command !== 'stop') {
			console.error(
				'Usage: e2e-sites.mjs start | stop | test [playwright args]',
			);
		}
	} catch (error) {
		console.error(
			`e2e-sites: ${error instanceof Error ? error.message : error}`,
		);
	} finally {
		if (command === 'stop' || command === 'test') {
			const stopped = await stopSites(toStop);
			passed = command === 'stop' ? stopped : passed;
			if (!stopped) {
				console.error('e2e-sites: could not stop every site.');
			}
		}
	}
	process.exit(passed ? 0 : 1);
}
