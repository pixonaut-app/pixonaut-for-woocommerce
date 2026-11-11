#!/usr/bin/env node
// Extra tests sites, each with its own fake Pixonaut, so the e2e suite can
// run in parallel: every site and fake is stateful and reset per test, so
// two tests can only run at once on two of them.
//
// Each site is a wp-env environment of its own, from a config this script
// writes under .wp-env-e2e/ (the tests site of .wp-env.json, as the only
// site), with its own wp-env home, so the checkout's own wp-env is left
// alone. Ports come from E2E_SITES_PORT_BASE: site n listens on that port plus
// 3(n - 1), its fake on the next and its database on the one after.
// E2E_SITES_COUNT, when set, is how many ports there are; PIXONAUT_E2E_SITES
// how many sites to run, as many as those ports hold when it is not set.
//
//   e2e-sites.mjs start           start the sites and their fakes
//   e2e-sites.mjs stop            stop them
//   e2e-sites.mjs test [args...]  start them, run `playwright test [args]`
//                                 split across them (--shard), and stop the
//                                 ones it started
import { spawn as spawnAsync, spawnSync } from 'node:child_process';
import {
	existsSync,
	mkdirSync,
	openSync,
	readdirSync,
	readFileSync,
	realpathSync,
	rmSync,
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

/** A site's WordPress port, its fake's and its database's. A database
 *  without a port of its own gets one Docker picks, which rootless Docker
 *  can pick while the host already holds it. */
const PORTS_PER_SITE = 3;

/** Where a site's fake and database ports sit in its block of ports. */
const FAKE_PORT_OFFSET = 1;
const MYSQL_PORT_OFFSET = 2;

/** The first port without E2E_SITES_PORT_BASE: past wp-env's defaults (8888,
 *  8889) and the fake's (8890). */
const DEFAULT_FIRST_PORT = 8900;

/** How many sites run without PIXONAUT_E2E_SITES, unless E2E_SITES_COUNT
 *  holds fewer. Each takes about 1 GB with its browser; four bring a full run
 *  of the suite to about a quarter. */
const DEFAULT_SITES = 4;

/** A site's only WordPress container. */
const WORDPRESS_SERVICE = 'wordpress';

/** The clones of WordPress core wp-env makes in a site's project. */
const CORE_CLONES = ['WordPress', 'tests-WordPress'];

/** Where a site's fake reaches it, on the site's Docker network. */
export const SITE_ON_NETWORK = `http://${WORDPRESS_SERVICE}`;

const MS_PER_SECOND = 1000;

/**
 * @typedef {{ number: number, port: number, fakePort: number, mysqlPort: number, dir: string }} Site
 */

/**
 * The sites to run, from the environment.
 *
 * @param {NodeJS.ProcessEnv} env
 * @returns {Site[]}
 */
export function plannedSites(env = process.env) {
	const firstPort = Number(env.E2E_SITES_PORT_BASE ?? DEFAULT_FIRST_PORT);
	const fitting =
		env.E2E_SITES_COUNT === undefined
			? DEFAULT_SITES
			: Math.min(
					DEFAULT_SITES,
					Math.floor(Number(env.E2E_SITES_COUNT) / PORTS_PER_SITE),
				);
	const wanted = Number(env.PIXONAUT_E2E_SITES ?? fitting);
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
		fakePort: firstPort + index * PORTS_PER_SITE + FAKE_PORT_OFFSET,
		mysqlPort: firstPort + index * PORTS_PER_SITE + MYSQL_PORT_OFFSET,
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
		mysqlPort: site.mysqlPort,
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
 * Whether the clone of WordPress core in `dir` is one an interrupted start
 * left behind: not a repository, or with files of its checkout missing or
 * changed. Running WordPress only adds untracked files (wp-config.php,
 * uploads), which a complete clone may have. wp-env reuses whatever
 * repository it finds there, and a partial one fails its checkout.
 *
 * @param {string} dir
 */
export function partialClone(dir) {
	const top = gitIn(dir, ['rev-parse', '--show-toplevel']);
	return (
		top.status !== 0 ||
		realpathOrSelf(top.stdout.trim()) !== realpathOrSelf(dir) ||
		gitIn(dir, ['rev-parse', '--verify', '--quiet', 'HEAD']).status !== 0 ||
		gitIn(dir, [
			'status',
			'--porcelain',
			'--untracked-files=no',
		]).stdout.trim() !== ''
	);
}

/**
 * Runs git in `dir` and captures its output.
 *
 * @param {string} dir
 * @param {string[]} args
 */
function gitIn(dir, args) {
	const result = spawnSync('git', ['-C', dir, ...args], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'ignore'],
	});
	if (result.error) {
		throw result.error;
	}
	return result;
}

/** @param {string} path */
function realpathOrSelf(path) {
	try {
		return realpathSync(path);
	} catch {
		return path;
	}
}

/**
 * Removes the partial clones of WordPress core in a site's project, so its
 * next start clones afresh. Answers the clones it removed.
 *
 * @param {Site} site
 * @returns {string[]}
 */
export function removePartialClones(site) {
	const project = projectOf(site);
	if (project === undefined) {
		return [];
	}
	return CORE_CLONES.map((name) => join(siteHome(site), project, name))
		.filter((dir) => existsSync(dir) && partialClone(dir))
		.map((dir) => {
			rmSync(dir, { recursive: true, force: true });
			return dir;
		});
}

/** The children running now, each with the promise of its exit, so a
 *  cancelled run can stop them and wait for them before it cleans up. */
const children = new Map();

/** Set once the run is cancelled: no further site, fake or shard starts. */
let cancelled = false;

/** Throws when the run was cancelled, between one start and the next. */
function unlessCancelled() {
	if (cancelled) {
		throw new Error('cancelled.');
	}
}

/**
 * Cancels the run: stops every running child and waits until each exited.
 */
async function cancel() {
	cancelled = true;
	for (const child of children.keys()) {
		child.kill('SIGTERM');
	}
	await Promise.all(children.values());
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
		children.set(
			child,
			new Promise((exited) => {
				const done = (/** @type {number} */ status) => {
					children.delete(child);
					exited(undefined);
					resolve({
						status,
						seconds: Math.round((Date.now() - started) / MS_PER_SECOND),
					});
				};
				child.on('error', () => done(1));
				child.on('close', (code) => done(code ?? 1));
			}),
		);
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
		WP_ENV_MYSQL_PORT: String(site.mysqlPort),
	};
	// The checkout's own: the site has no tests environment.
	delete env.WP_ENV_TESTS_PORT;
	delete env.WP_ENV_TESTS_MYSQL_PORT;
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
		reportRemovedClones(site, removePartialClones(site));
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
	unlessCancelled();
	const projects = sites.map((site, index) => {
		const { status, seconds } = started[index] ?? { status: 1, seconds: 0 };
		const project = projectOf(site);
		if (status !== 0 || project === undefined) {
			for (const failed of sites.filter(
				(_, other) => (started[other]?.status ?? 1) !== 0,
			)) {
				reportRemovedClones(failed, removePartialClones(failed));
			}
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
		unlessCancelled();
		const project = projects[index] ?? '';
		if (!makeUploadsWritable(project, [WORDPRESS_SERVICE])) {
			throw new Error(`could not make site ${site.number}'s uploads writable.`);
		}
		await runFake(site.fakePort, { project, image, label: fakeLabel(site) });
	}
}

/**
 * @param {Site} site
 * @param {string[]} removed
 */
function reportRemovedClones(site, removed) {
	for (const dir of removed) {
		console.log(
			`Site ${site.number}: removed the partial WordPress clone in ${dir}.`,
		);
	}
}

/**
 * The containers of a wp-env project, in any state.
 *
 * @param {string} project
 * @returns {string[]}
 */
function projectContainers(project) {
	const result = spawnSync(
		dockerBin,
		[
			'ps',
			'--all',
			'--quiet',
			'--filter',
			`label=com.docker.compose.project=${project}`,
		],
		{ encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] },
	);
	return (result.stdout ?? '').split('\n').filter(Boolean);
}

/**
 * The sites wp-env has anything to stop for: a project with containers. A
 * site whose start failed before any container was made has none, and wp-env
 * fails to stop it.
 *
 * @param {Site[]} sites
 * @param {(project: string) => string[]} containersOf
 */
export function sitesWithContainers(sites, containersOf = projectContainers) {
	return sites.filter((site) => {
		const project = projectOf(site);
		return project !== undefined && containersOf(project).length > 0;
	});
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
		sitesWithContainers(sites).map((site) => siteWpEnv(site, ['stop'])),
	);
	const removed = networks.flat().map(removeNetwork);
	return (
		stopped.every(({ status }) => status === 0) && !removed.includes(false)
	);
}

/** Where Playwright finds a site and its fake; set, its config asks no
 *  wp-env for them. @param {Site} site */
function testEnv(site) {
	return {
		...process.env,
		PIXONAUT_E2E_BASE_URL: `http://localhost:${site.port}`,
		PIXONAUT_E2E_FAKE_BASE_URL: fakeConstants(site.fakePort)
			.PIXONAUT_WC_API_BASE,
		PIXONAUT_E2E_SITE_ON_NETWORK: SITE_ON_NETWORK,
	};
}

/**
 * Whether `playwright test args` finds any test. Each shard may find none,
 * so the shards cannot tell a selection that matches nothing.
 *
 * @param {Site} site Any of the sites, for the config to read.
 * @param {string[]} args
 */
export async function selectsTests(site, args) {
	mkdirSync(SITES_DIR, { recursive: true });
	const log = join(SITES_DIR, 'list.log');
	writeFileSync(log, '');
	const { status } = await runLogged(
		join(root, 'node_modules', '.bin', 'playwright'),
		['test', '--list', ...args],
		{ log, env: testEnv(site) },
	);
	return status === 0;
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
		unlessCancelled();
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
					...testEnv(site),
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

/**
 * `start`, `stop` or `test`, as the header says. A failed or interrupted
 * `start` or `test` stops the sites it started; sites that were running
 * before stay up.
 *
 * @param {string | undefined} command
 * @param {string[]} args
 * @returns {Promise<boolean>} Whether it succeeded, cleanup included.
 */
async function main(command, args) {
	const sites = plannedSites();
	if (command === 'stop') {
		return stopSites(sites);
	}
	if (command !== 'start' && command !== 'test') {
		console.error('Usage: e2e-sites.mjs start | stop | test [playwright args]');
		return false;
	}
	const [first] = sites;
	if (command === 'test' && first && !(await selectsTests(first, args))) {
		console.error(
			`e2e-sites: no tests match; see ${join(SITES_DIR, 'list.log')}.`,
		);
		return false;
	}
	const started = await stoppedSites(sites);
	/** @type {Promise<boolean> | undefined} */
	let cleanup;
	// Cancelled children first, then one cleanup, whoever asks for it.
	const stopStarted = () => {
		cleanup ??= cancel().then(() => stopSites(started));
		return cleanup;
	};
	for (const signal of /** @type {const} */ (['SIGINT', 'SIGTERM'])) {
		process.once(signal, async () => {
			await stopStarted();
			process.exit(1);
		});
	}
	let passed = false;
	try {
		await startSites(sites);
		passed = command === 'start' || (await testOnSites(sites, args));
	} catch (error) {
		console.error(
			`e2e-sites: ${error instanceof Error ? error.message : error}`,
		);
	}
	if (command === 'start' && passed) {
		return true;
	}
	const stopped = await stopStarted();
	if (!stopped) {
		console.error('e2e-sites: could not stop every site.');
	}
	return passed && stopped;
}

if (import.meta.url === `file://${process.argv[1]}`) {
	const [command, ...args] = process.argv.slice(2);
	process.exit((await main(command, args)) ? 0 : 1);
}
