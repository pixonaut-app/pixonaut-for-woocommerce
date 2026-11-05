<?php
/**
 * Plugin Name: Pixonaut for WooCommerce compatibility tests
 * Description: Test-only: the theme and page builder a browser context runs, a builder page holding the product, and the PHP errors a request raised.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! defined( 'PIXONAUT_WC_E2E' ) || ! PIXONAUT_WC_E2E ) {
	return;
}

require_once WP_CONTENT_DIR . '/pixonaut-compat/switch.php';
require_once WP_CONTENT_DIR . '/pixonaut-compat/builder-pages.php';

/**
 * Option that keeps the PHP warnings and notices requests under a combination raised.
 */
const PIXONAUT_COMPAT_PHP_ERRORS_OPTION = 'pixonaut_compat_php_errors';

if ( null !== pixonaut_compat_current() ) {
	$pixonaut_compat_errors = array();
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only: the spec fails on what this collects.
	set_error_handler(
		static function ( int $level, string $message, string $file, int $line ) use ( &$pixonaut_compat_errors ): bool {
			$pixonaut_compat_errors[] = sprintf( '%s in %s:%d', $message, $file, $line );
			return false;
		},
		E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
	);
	register_shutdown_function(
		static function () use ( &$pixonaut_compat_errors ): void {
			if ( array() !== $pixonaut_compat_errors ) {
				update_option( PIXONAUT_COMPAT_PHP_ERRORS_OPTION, array_merge( (array) get_option( PIXONAUT_COMPAT_PHP_ERRORS_OPTION, array() ), $pixonaut_compat_errors ), false );
			}
		}
	);
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'pixonaut-e2e/v1',
			'/builder-pages',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						$page_id = pixonaut_compat_builder_page( (string) $request['builder'], (int) $request['product'] );
						return new WP_REST_Response(
							array(
								'id'        => $page_id,
								'permalink' => get_permalink( $page_id ),
							),
							201
						);
					},
				),
				// A page left behind joins a theme's fallback menu, whose growing header pushes other specs' designers out of view.
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						wp_delete_post( (int) $request['id'], true );
						return new WP_REST_Response( null, 204 );
					},
				),
			)
		);
		register_rest_route(
			'pixonaut-e2e/v1',
			'/php-errors',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static fn () => new WP_REST_Response( (array) get_option( PIXONAUT_COMPAT_PHP_ERRORS_OPTION, array() ) ),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						delete_option( PIXONAUT_COMPAT_PHP_ERRORS_OPTION );
						return new WP_REST_Response( null, 204 );
					},
				),
			)
		);
	}
);
