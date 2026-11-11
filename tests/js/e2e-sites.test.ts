// The extra e2e sites: their ports, and the wp-env config each one gets.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { plannedSites, siteConfig } from '../../bin/e2e-sites.mjs';

const BASE = JSON.parse(
	readFileSync(join(process.cwd(), '.wp-env.json'), 'utf8'),
);

describe('plannedSites', () => {
	it('gives each site a port and its fake the next, from the first port', () => {
		const sites = plannedSites({
			E2E_SITES_PORT_BASE: '18900',
			PIXONAUT_E2E_SITES: '3',
		});

		expect(
			sites.map(({ number, port, fakePort }) => [number, port, fakePort]),
		).toEqual([
			[1, 18900, 18901],
			[2, 18902, 18903],
			[3, 18904, 18905],
		]);
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
			plannedSites({ E2E_SITES_COUNT: '6', PIXONAUT_E2E_SITES: '4' }),
		).toThrow('4 e2e sites need 8 ports; E2E_SITES_COUNT has 6.');
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

	it("is the tests site alone, on the site's port", () => {
		expect(config.testsEnvironment).toBe(false);
		expect(config.port).toBe(18900);
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
