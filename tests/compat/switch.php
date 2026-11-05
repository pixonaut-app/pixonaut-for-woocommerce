<?php
/**
 * Picks a theme and page builder combination for one browser, by cookie, so
 * every combination runs against the same site. Loaded by the mu-plugins of
 * the compatibility tests and of the preview site.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Cookie that names the combination of this browser.
 */
const PIXONAUT_COMPAT_COOKIE = 'pixonaut_compat';

/**
 * Runs after the e2e support's own theme cookie, so a combination wins over it.
 */
const PIXONAUT_COMPAT_PRIORITY = 20;

/**
 * The builders and combinations.
 *
 * @return array{builders: array<string, string>, combinations: list<array{id: string, label: string, theme: string, builder?: string}>}
 */
function pixonaut_compat_data(): array {
	static $data = null;
	if ( null === $data ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file beside this one.
		$data = json_decode( (string) file_get_contents( __DIR__ . '/combinations.json' ), true );
	}
	return $data;
}

/**
 * The combination this browser's cookie names, or null to keep the site's own
 * theme with no builder.
 *
 * @return array{id: string, label: string, theme: string, builder?: string}|null
 */
function pixonaut_compat_current(): ?array {
	$id = isset( $_COOKIE[ PIXONAUT_COMPAT_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ PIXONAUT_COMPAT_COOKIE ] ) ) : '';
	foreach ( pixonaut_compat_data()['combinations'] as $combination ) {
		if ( $combination['id'] === $id ) {
			return $combination;
		}
	}
	return null;
}

foreach ( array( 'pre_option_template', 'pre_option_stylesheet' ) as $pixonaut_compat_hook ) {
	add_filter(
		$pixonaut_compat_hook,
		static function ( $value ) {
			return pixonaut_compat_current()['theme'] ?? $value;
		},
		PIXONAUT_COMPAT_PRIORITY
	);
}

// wp-env activates every builder once, through WP-CLI, which runs its
// activation routine and must see the whole list to add to it. Each browser's
// request then runs only the builder its combination names.
add_filter(
	'option_active_plugins',
	static function ( $active ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $active;
		}
		$data     = pixonaut_compat_data();
		$builder  = pixonaut_compat_current()['builder'] ?? null;
		$excluded = array_diff_key( $data['builders'], null === $builder ? array() : array( $builder => true ) );
		return array_values( array_diff( (array) $active, array_values( $excluded ) ) );
	}
);
