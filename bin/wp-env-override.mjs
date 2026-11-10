// What `env:start` writes to .wp-env.override.json: the constants that point
// the plugin at this checkout's fake, and, when WP_ENV_WOOCOMMERCE is set, the
// WooCommerce release to install in place of the latest. WP_ENV_WOOCOMMERCE
// takes a wp-env source, as wp-env's own WP_ENV_CORE does for WordPress; the
// nightly workflow sets both (bin/nightly-versions.mjs). WP_ENV_COMPAT installs
// the compatibility tests' themes and page builders too, and raises the
// memory limit they need.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { builderSources, themeSources } from './compat-sources.mjs';
import { root } from './env.mjs';

/**
 * PHP's memory limit with the page builders installed. WooCommerce, Elementor
 * and Beaver Builder together need about 170 MB in a WP-CLI request, over the
 * containers' 128M; WordPress raises PHP's limit to `WP_MEMORY_LIMIT` before
 * it loads plugins. 256M is what WooCommerce recommends to stores.
 */
export const COMPAT_MEMORY_LIMIT = '256M';

/** WooCommerce's entry in .wp-env.json's `plugins`, at any version. */
const WOOCOMMERCE_PLUGIN = /\/woocommerce(\.[^/]*)?\.zip$/;

/**
 * @param {Record<string, string>} config The constants that point the plugin at the fake.
 * @param {string | undefined} [woocommerce] A wp-env source for WooCommerce.
 * @param {string | undefined} [compat] Set to install the compatibility tests' themes and builders.
 * @returns {{ config: Record<string, string>, plugins?: string[], themes?: string[] }}
 */
export function overrideConfig(
	config,
	woocommerce = process.env.WP_ENV_WOOCOMMERCE,
	compat = process.env.WP_ENV_COMPAT,
) {
	const override = { config };
	if (!woocommerce && !compat) {
		return override;
	}
	// wp-env replaces `plugins` and `themes` as a whole, so the override repeats the lists.
	const { plugins, themes } = JSON.parse(
		readFileSync(join(root, '.wp-env.json'), 'utf8'),
	);
	if (
		woocommerce &&
		!plugins.some((/** @type {string} */ source) =>
			WOOCOMMERCE_PLUGIN.test(source),
		)
	) {
		throw new Error('.wp-env.json installs no WooCommerce to replace.');
	}
	const pinned = woocommerce
		? plugins.map((/** @type {string} */ source) =>
				WOOCOMMERCE_PLUGIN.test(source) ? woocommerce : source,
			)
		: plugins;
	return compat
		? {
				config: { ...config, WP_MEMORY_LIMIT: COMPAT_MEMORY_LIMIT },
				plugins: [...pinned, ...builderSources()],
				themes: [...new Set([...themes, ...themeSources()])],
			}
		: { ...override, plugins: pinned };
}
