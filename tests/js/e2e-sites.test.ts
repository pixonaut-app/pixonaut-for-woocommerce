// The extra e2e sites: their ports, and the wp-env config each one gets.
import { execFileSync } from 'node:child_process';
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
	partialClone,
	plannedSites,
	removePartialClones,
	siteConfig,
	sitesWithContainers,
} from '../../bin/e2e-sites.mjs';

const BASE = JSON.parse(
	readFileSync(join(process.cwd(), '.wp-env.json'), 'utf8'),
);

describe('plannedSites', () => {
	it('gives each site a port, its fake the next and its database the one after', () => {
		const sites = plannedSites({
			E2E_SITES_PORT_BASE: '18900',
			PIXONAUT_E2E_SITES: '3',
		});

		expect(
			sites.map(({ number, port, fakePort, mysqlPort }) => [
				number,
				port,
				fakePort,
				mysqlPort,
			]),
		).toEqual([
			[1, 18900, 18901, 18902],
			[2, 18903, 18904, 18905],
			[3, 18906, 18907, 18908],
		]);
	});

	it('runs as many sites as the ports dev gave hold, without a count', () => {
		const sites = plannedSites({
			E2E_SITES_PORT_BASE: '18900',
			E2E_SITES_COUNT: '8',
		});

		expect(sites).toHaveLength(2);
		expect(sites.at(-1)?.mysqlPort).toBe(18905);
	});

	it('runs four sites from port 8900 without settings', () => {
		const sites = plannedSites({});

		expect(sites).toHaveLength(4);
		expect(sites[0]?.port).toBe(8900);
	});

	it('names each site after the checkout, under .wp-env-e2e', () => {
		const [site] = plannedSites({ PIXONAUT_E2E_SITES: '1' });

		expect(site?.dir).toMatch(/\/\.wp-env-e2e\/[^/]+-e2e-1$/);
	});

	it('refuses more sites than the ports dev gave', () => {
		expect(() =>
			plannedSites({ E2E_SITES_COUNT: '8', PIXONAUT_E2E_SITES: '3' }),
		).toThrow('3 e2e sites need 9 ports; E2E_SITES_COUNT has 8.');
	});

	it('refuses no sites', () => {
		expect(() => plannedSites({ PIXONAUT_E2E_SITES: '0' })).toThrow(
			'at least one site',
		);
	});
});

describe('siteConfig', () => {
	const [site] = plannedSites({
		E2E_SITES_PORT_BASE: '18900',
		PIXONAUT_E2E_SITES: '1',
	});
	if (site === undefined) {
		throw new Error('No site planned.');
	}
	const config = siteConfig(BASE, site);

	it("is the tests site alone, on the site's ports", () => {
		expect(config.testsEnvironment).toBe(false);
		expect(config.port).toBe(18900);
		expect(config.mysqlPort).toBe(18902);
		expect(config.mappings).toEqual(BASE.env.tests.mappings);
		expect(config.plugins).toEqual(BASE.plugins);
		expect(config.themes).toEqual(BASE.themes);
	});

	it("has the tests site's constants, pointed at the site's own fake", () => {
		expect(config.config).toMatchObject({
			...BASE.config,
			...BASE.env.tests.config,
			PIXONAUT_WC_API_BASE: 'http://localhost:18901',
			PIXONAUT_WC_SERVER_API_BASE: 'http://fake-pixonaut:18901',
		});
	});
});

/** Runs git in `dir`, quietly, as a fixed author. */
function git(dir: string, ...args: string[]) {
	execFileSync(
		'git',
		[
			'-C',
			dir,
			'-c',
			'user.name=Test',
			'-c',
			'user.email=test@example.com',
			...args,
		],
		{ stdio: 'ignore' },
	);
}

describe('partial WordPress clones', () => {
	let dir: string;
	let clone: string;

	beforeEach(() => {
		dir = mkdtempSync(join(tmpdir(), 'e2e-sites-'));
		clone = join(dir, 'home', 'wp-env-site', 'WordPress');
		mkdirSync(clone, { recursive: true });
	});

	afterEach(() => {
		rmSync(dir, { recursive: true, force: true });
	});

	/** A clone whose checkout finished, with the files WordPress adds. */
	function completeClone() {
		git(clone, 'init', '--quiet');
		writeFileSync(join(clone, 'index.php'), '<?php\n');
		git(clone, 'add', 'index.php');
		git(clone, 'commit', '--quiet', '--message', 'core');
		writeFileSync(join(clone, 'wp-config.php'), '<?php\n');
	}

	const site = () => ({
		number: 1,
		port: 18900,
		fakePort: 18901,
		mysqlPort: 18902,
		dir,
	});

	it('keeps a complete clone, with the files WordPress adds', () => {
		completeClone();

		expect(partialClone(clone)).toBe(false);
		expect(removePartialClones(site())).toEqual([]);
		expect(existsSync(clone)).toBe(true);
	});

	it('removes a clone whose checkout is missing files', () => {
		completeClone();
		unlinkSync(join(clone, 'index.php'));

		expect(removePartialClones(site())).toEqual([clone]);
		expect(existsSync(clone)).toBe(false);
	});

	it('counts a clone with no commit checked out as partial', () => {
		git(clone, 'init', '--quiet');
		writeFileSync(join(clone, 'index.php'), '<?php\n');

		expect(partialClone(clone)).toBe(true);
	});

	it('counts files in no repository of their own as partial', () => {
		writeFileSync(join(clone, 'index.php'), '<?php\n');

		expect(partialClone(clone)).toBe(true);
	});

	it('has nothing to remove for a site that never started', () => {
		rmSync(join(dir, 'home'), { recursive: true });

		expect(removePartialClones(site())).toEqual([]);
	});
});

describe('sitesWithContainers', () => {
	const dir = mkdtempSync(join(tmpdir(), 'e2e-sites-'));
	const [up, failed, neverStarted] = plannedSites({
		E2E_SITES_PORT_BASE: '18900',
		PIXONAUT_E2E_SITES: '3',
	}).map((site) => ({ ...site, dir: join(dir, `site-${site.number}`) }));
	if (up === undefined || failed === undefined || neverStarted === undefined) {
		throw new Error('No sites planned.');
	}
	mkdirSync(join(up.dir, 'home', 'wp-env-up'), { recursive: true });
	mkdirSync(join(failed.dir, 'home', 'wp-env-failed'), { recursive: true });

	afterAll(() => {
		rmSync(dir, { recursive: true, force: true });
	});

	it('leaves out a site whose start made no container, or no project', () => {
		const containers: Record<string, string[]> = { 'wp-env-up': ['abc123'] };

		expect(
			sitesWithContainers(
				[up, failed, neverStarted],
				(project: string) => containers[project] ?? [],
			),
		).toEqual([up]);
	});
});
