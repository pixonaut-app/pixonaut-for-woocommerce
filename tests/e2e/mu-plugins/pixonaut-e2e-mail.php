<?php
/**
 * Plugin Name: Pixonaut for WooCommerce e2e mail
 * Description: Test-only mail catcher: keeps every email the tests site sends instead of sending it, and answers them over REST. Mapped into wp-env's tests environment only.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

// The integration suite, whose bootstrap defines that constant, shares the tests site and catches mail itself.
if ( ! defined( 'ABSPATH' ) || ! defined( 'PIXONAUT_WC_E2E' ) || ! PIXONAUT_WC_E2E || defined( 'PIXONAUT_INTEGRATION_TABLE_PREFIX' ) ) {
	return;
}

/**
 * Prefix of the options that keep one caught email each, so two requests
 * sending at once never overwrite each other.
 */
const PIXONAUT_E2E_MAIL_OPTION_PREFIX = 'pixonaut_e2e_mail_';

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, array $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}
		add_option(
			PIXONAUT_E2E_MAIL_OPTION_PREFIX . str_replace( '.', '', uniqid( '', true ) ),
			array(
				'to'      => (array) $atts['to'],
				'subject' => (string) $atts['subject'],
				'message' => (string) $atts['message'],
			),
			'',
			false
		);
		return true;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function () {
		// Every email caught, oldest first.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/mails',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					global $wpdb;
					$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only lookup of the caught emails.
						$wpdb->prepare(
							"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id",
							$wpdb->esc_like( PIXONAUT_E2E_MAIL_OPTION_PREFIX ) . '%'
						)
					);
					return new WP_REST_Response( array_values( array_map( static fn ( string $name ) => get_option( $name ), $names ) ) );
				},
			)
		);
	}
);
