// What `env:start` writes to .wp-env.override.json.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	COMPAT_MEMORY_LIMIT,
	overrideConfig,
} from '../../bin/wp-env-override.mjs';
import { BIN } from './support/scripts';

/** What `fakeConstants` returns, as far as the override is concerned. */
const CONSTANTS = { PIXONAUT_WC_API_BASE: 'http://localhost:8864' };
const PINNED = 'https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip';

const { plugins } = JSON.parse(
	readFileSync(join(BIN, '..', '.wp-env.json'), 'utf8'),
);

describe('overrideConfig', () => {
	it('points the plugin at the fake and leaves the plugins alone by default', () => {
		expect(overrideConfig(CONSTANTS, '', '')).toEqual({
			config: CONSTANTS,
		});
	});

	it('installs the given WooCommerce in place of the latest, keeping the other plugins', () => {
		const override = overrideConfig(CONSTANTS, PINNED, '');

		expect(override.config).toEqual(CONSTANTS);
		expect(override.plugins).toEqual(
			plugins.map((source: string) =>
				source.includes('/woocommerce.') ? PINNED : source,
			),
		);
		expect(override.plugins).toContain(PINNED);
	});

	it("adds the compatibility tests' themes and builders on top of the lists", () => {
		const override = overrideConfig(CONSTANTS, '', '1');
		expect(override.plugins).toEqual(expect.arrayContaining(plugins));
		expect(override.plugins).toContain(
			'https://downloads.wordpress.org/plugin/elementor.zip',
		);
		expect(override.themes).toContain(
			'https://downloads.wordpress.org/theme/kadence.zip',
		);
		expect(new Set(override.themes).size).toBe(override.themes?.length);
	});

	it('raises the memory limit with the builders installed, and only then', () => {
		expect(COMPAT_MEMORY_LIMIT).toMatch(/^\d+M$/);
		expect(overrideConfig(CONSTANTS, '', '1').config).toStrictEqual({
			...CONSTANTS,
			WP_MEMORY_LIMIT: COMPAT_MEMORY_LIMIT,
		});
		expect(overrideConfig(CONSTANTS, PINNED, '').config).toStrictEqual(
			CONSTANTS,
		);
	});
});
