<?php
/**
 * Plugin Name: Pixonaut for WooCommerce e2e support
 * Description: Test-only helpers for the e2e suite. Mapped into wp-env's tests environment only.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! defined( 'PIXONAUT_WC_E2E' ) || ! PIXONAUT_WC_E2E ) {
	return;
}

/**
 * Cookie that picks the theme for one browser context, so the classic and the
 * block-theme projects can run against the same site at the same time.
 */
const PIXONAUT_E2E_THEME_COOKIE = 'pixonaut_e2e_theme';

/**
 * Option the webhook sink keeps the requests it received in.
 */
const PIXONAUT_E2E_WEBHOOK_SINK_OPTION = 'pixonaut_e2e_webhook_sink';

/**
 * Themes a test may pick with the cookie.
 */
const PIXONAUT_E2E_THEMES = array( 'storefront', 'twentytwentyfive', 'astra' );

/**
 * The theme named by the request's cookie, or null to keep the site's own.
 */
function pixonaut_e2e_requested_theme(): ?string {
	$theme = isset( $_COOKIE[ PIXONAUT_E2E_THEME_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ PIXONAUT_E2E_THEME_COOKIE ] ) ) : '';
	return in_array( $theme, PIXONAUT_E2E_THEMES, true ) ? $theme : null;
}

foreach ( array( 'pre_option_template', 'pre_option_stylesheet' ) as $pixonaut_e2e_hook ) {
	add_filter(
		$pixonaut_e2e_hook,
		static function ( $value ) {
			return pixonaut_e2e_requested_theme() ?? $value;
		}
	);
}

// A fresh store starts in coming-soon mode, which hides the shop from guests.
add_filter(
	'pre_option_woocommerce_coming_soon',
	static function () {
		return 'no';
	}
);

// A fresh install sends the first admin screen loaded within 30 seconds to
// the setup wizard instead, which a test starting right after wp-env would hit.
add_filter( 'woocommerce_enable_setup_wizard', '__return_false' );

/**
 * Cookie that lets one browser context set the browser's and the studio's
 * Pixonaut addresses in the developer tools, as on a site without the
 * constants the tests site sets. WordPress keeps reaching the fake through
 * its own constant.
 */
const PIXONAUT_E2E_HOSTS_COOKIE = 'pixonaut_e2e_hosts';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! isset( $_COOKIE[ PIXONAUT_E2E_HOSTS_COOKIE ] ) || 'unlocked' !== $_COOKIE[ PIXONAUT_E2E_HOSTS_COOKIE ] || ! class_exists( \Pixonaut\WooCommerce\Api\Hosts::class ) ) {
			return;
		}
		\Pixonaut\WooCommerce\Api\Hosts::read_constants_with(
			static function ( string $name ): string {
				return \Pixonaut\WooCommerce\Api\Hosts::SERVER_API_CONSTANT === $name && defined( $name ) ? (string) constant( $name ) : '';
			}
		);
	},
	0
);

/**
 * Cookie that gives one browser context pretty permalinks, for a test that
 * connects the store: connecting needs them, and admin screens work without
 * an .htaccess.
 */
const PIXONAUT_E2E_PERMALINKS_COOKIE = 'pixonaut_e2e_permalinks';

/**
 * The structure the cookie selects.
 */
const PIXONAUT_E2E_PRETTY_PERMALINKS = '/%postname%/';

// wp-env's tests site has no .htaccess, so pretty permalinks would 404 on
// the storefront: plain ones unless the request asks otherwise.
add_filter(
	'pre_option_permalink_structure',
	static function () {
		$pretty = isset( $_COOKIE[ PIXONAUT_E2E_PERMALINKS_COOKIE ] ) && 'pretty' === $_COOKIE[ PIXONAUT_E2E_PERMALINKS_COOKIE ];
		return $pretty ? PIXONAUT_E2E_PRETTY_PERMALINKS : '';
	}
);

/**
 * Cookie that sets the store's `attribute_options` setting for one browser
 * context, so a test of the swatches changes no other test's store.
 */
const PIXONAUT_E2E_ATTRIBUTE_OPTIONS_COOKIE = 'pixonaut_e2e_attribute_options';

/**
 * The stored settings with the cookie's `attribute_options`, when it names one.
 *
 * @param mixed $settings The stored settings.
 * @return mixed
 */
function pixonaut_e2e_attribute_options( $settings ) {
	if ( ! isset( $_COOKIE[ PIXONAUT_E2E_ATTRIBUTE_OPTIONS_COOKIE ] ) ) {
		return $settings;
	}
	$value = sanitize_key( wp_unslash( $_COOKIE[ PIXONAUT_E2E_ATTRIBUTE_OPTIONS_COOKIE ] ) );
	return array( \Pixonaut\WooCommerce\Settings\Settings::ATTRIBUTE_OPTIONS => $value ) + ( is_array( $settings ) ? $settings : array() );
}
// The plugin loads after the mu-plugins, so its `Schema::SETTINGS_OPTION` is not yet there to name the option by.
add_filter( 'option_pixonaut_wc_settings', 'pixonaut_e2e_attribute_options' );
add_filter( 'default_option_pixonaut_wc_settings', 'pixonaut_e2e_attribute_options' );

/**
 * Cookie that sets the store's product-form settings for one browser context
 * (`as_groups=yes`, `place=last`, `numbers=off`, as a query string), so a spec
 * never changes them for another running at once.
 */
const PIXONAUT_E2E_PRODUCT_FORM_COOKIE = 'pixonaut_e2e_product_form';

/**
 * The stored settings with the cookie's product-form settings, when it names any.
 *
 * @param mixed $settings The stored settings.
 * @return mixed
 */
function pixonaut_e2e_product_form( $settings ) {
	if ( ! isset( $_COOKIE[ PIXONAUT_E2E_PRODUCT_FORM_COOKIE ] ) ) {
		return $settings;
	}
	parse_str( sanitize_text_field( wp_unslash( $_COOKIE[ PIXONAUT_E2E_PRODUCT_FORM_COOKIE ] ) ), $form );
	return array_merge(
		is_array( $settings ) ? $settings : array(),
		array(
			'options_as_groups'      => 'yes' === ( $form['as_groups'] ?? '' ) ? 'yes' : 'no',
			'option_groups_position' => 'last' === ( $form['place'] ?? '' ) ? 'last' : 'first',
			'group_numbers'          => 'off' === ( $form['numbers'] ?? '' ) ? 'no' : 'yes',
		)
	);
}
add_filter( 'option_pixonaut_wc_settings', 'pixonaut_e2e_product_form' );
add_filter( 'default_option_pixonaut_wc_settings', 'pixonaut_e2e_product_form' );

/**
 * Cookie that swaps the block theme's single product blocks for the secondary
 * pair, Add to Cart with Options and Product Gallery, for one browser context.
 */
const PIXONAUT_E2E_BLOCKS_COOKIE = 'pixonaut_e2e_blocks';

/**
 * The primary pair's blocks, and what the secondary pair puts in their place.
 */
const PIXONAUT_E2E_SECONDARY_BLOCKS = array(
	'woocommerce/add-to-cart-form'      => 'woocommerce/add-to-cart-with-options',
	'woocommerce/product-image-gallery' => 'woocommerce/product-gallery',
);

/**
 * Drops every cached catalogue answer, mockups included: they were read from
 * an earlier test's fake, whose state and links this test's fake may not have.
 */
/**
 * Picks the blank variant a product or variation personalised on a blank
 * sells, as the store manager would on the product screen: `$ref`, or its
 * blank's first variant on sale.
 *
 * @param WC_Product  $product A product or variation.
 * @param string|null $ref     A variant ref; null for the first one on sale.
 */
function pixonaut_e2e_pick_variant( WC_Product $product, ?string $ref ): void {
	$blank_id = \Pixonaut\WooCommerce\Catalogue\ProductConfig::config_for( $product )->blank_id;
	if ( null === $blank_id ) {
		return;
	}
	// The plugin booted long before a REST request, so its file is not needed: this returns the running instance.
	$variants = \Pixonaut\WooCommerce\Plugin::boot( '' )->product_variants();
	$on_sale  = array_values( array_filter( $variants->variants( $blank_id ), static fn ( $variant ): bool => null === $variant->retired ) );
	$variants->pick( $product, $ref ?? ( $on_sale[0]->ref ?? null ) );
	$variants->note( $product );
}

function pixonaut_e2e_forget_catalogue(): void {
	global $wpdb;
	$prefix = \Pixonaut\WooCommerce\Schema::CATALOGUE_CACHE_PREFIX;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test support; transients have no prefix delete.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' . $prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%' ) );
	wp_cache_flush();
}

/**
 * The block template with the secondary pair in place of the primary one.
 *
 * @param WP_Block_Template $template A block template.
 */
function pixonaut_e2e_secondary_blocks( WP_Block_Template $template ): WP_Block_Template {
	$secondary = isset( $_COOKIE[ PIXONAUT_E2E_BLOCKS_COOKIE ] ) && 'secondary' === $_COOKIE[ PIXONAUT_E2E_BLOCKS_COOKIE ];
	if ( $secondary ) {
		foreach ( PIXONAUT_E2E_SECONDARY_BLOCKS as $primary => $replacement ) {
			$template->content = (string) preg_replace( '#wp:' . preg_quote( $primary, '#' ) . '\b(\s*\{[^}]*\})?#', 'wp:' . $replacement, $template->content );
		}
	}
	return $template;
}

/**
 * GitHub's API, which the plugin's update checker asks for releases.
 */
const PIXONAUT_E2E_GITHUB_API_HOST = 'api.github.com';

// The update checker runs without a token, so the tests site would ask the
// real GitHub for releases on every Plugins screen. It gets an empty list.
add_filter(
	'pre_http_request',
	static function ( $response, array $args, string $url ) {
		if ( false !== $response || PIXONAUT_E2E_GITHUB_API_HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $response;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '[]',
			'response' => array(
				'code'    => WP_Http::OK,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_filter(
	'get_block_templates',
	static function ( array $templates ): array {
		return array_map( 'pixonaut_e2e_secondary_blocks', $templates );
	}
);
add_filter(
	'get_block_template',
	static function ( $template ) {
		return $template instanceof WP_Block_Template ? pixonaut_e2e_secondary_blocks( $template ) : $template;
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'pixonaut-e2e/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$active = class_exists( \Pixonaut\WooCommerce\Plugin::class );
					return new WP_REST_Response(
						array(
							'active'      => $active,
							'version'     => $active ? \Pixonaut\WooCommerce\Plugin::VERSION : null,
							'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
						)
					);
				},
			)
		);

		// Sends one GET from WordPress to the fake Pixonaut's server base, so a
		// test proves the site reaches its own fake.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/fake-request',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'path' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^/',
					),
					'key'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! defined( 'PIXONAUT_WC_SERVER_API_BASE' ) ) {
						return new WP_Error( 'pixonaut_e2e_no_fake', 'PIXONAUT_WC_SERVER_API_BASE is not set: start the site with pnpm env:start.', array( 'status' => 500 ) );
					}
					$response = wp_remote_get(
						(string) constant( 'PIXONAUT_WC_SERVER_API_BASE' ) . (string) $request['path'],
						array(
							'headers' => array( 'Authorization' => 'Bearer ' . (string) $request['key'] ),
						)
					);
					if ( is_wp_error( $response ) ) {
						return new WP_Error( 'pixonaut_e2e_fake_unreachable', $response->get_error_message(), array( 'status' => 502 ) );
					}
					return new WP_REST_Response(
						array(
							'status' => wp_remote_retrieve_response_code( $response ),
							'body'   => json_decode( wp_remote_retrieve_body( $response ), true ),
						)
					);
				},
			)
		);

		// A webhook endpoint the fake Pixonaut delivers to over the Docker
		// network: keeps each request's raw body and signature headers, so a
		// test reads back exactly what arrived.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/webhook-sink',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						$received   = get_option( PIXONAUT_E2E_WEBHOOK_SINK_OPTION, array() );
						$received[] = array(
							'body'      => $request->get_body(),
							'timestamp' => $request->get_header( 'x-pixonaut-timestamp' ),
							'signature' => $request->get_header( 'x-pixonaut-signature' ),
						);
						update_option( PIXONAUT_E2E_WEBHOOK_SINK_OPTION, $received, false );
						return new WP_REST_Response( null, 204 );
					},
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						return new WP_REST_Response( get_option( PIXONAUT_E2E_WEBHOOK_SINK_OPTION, array() ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						delete_option( PIXONAUT_E2E_WEBHOOK_SINK_OPTION );
						return new WP_REST_Response( null, 204 );
					},
				),
			)
		);

		register_rest_route(
			'pixonaut-e2e/v1',
			'/connection',
			array(
				// Forgets the store's connection, so a test starts disconnected
				// whatever the last one left.
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						delete_option( \Pixonaut\WooCommerce\Schema::CONNECTION_OPTION );
						delete_option( \Pixonaut\WooCommerce\Schema::NOTICES_OPTION );
						delete_metadata( 'user', 0, \Pixonaut\WooCommerce\Schema::CONNECT_ATTEMPT_META, '', true );
						pixonaut_e2e_forget_catalogue();
						return new WP_REST_Response( null, 204 );
					},
				),
				// Stores a connection as `POST /v1/connect/token` would have
				// answered it, for a test about the storefront rather than
				// about connecting.
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						pixonaut_e2e_forget_catalogue();
						// No slug unless the test names one: the store is one connected before Pixonaut answered one.
						$answer = $request->get_json_params() + array( 'tenantSlug' => '' );
						$store  = new \Pixonaut\WooCommerce\Connection\ConnectionStore( \Pixonaut\WooCommerce\Connection\Cipher::for_site() );
						$store->save( \Pixonaut\WooCommerce\Connection\StoredConnection::from_exchange( \Pixonaut\WooCommerce\Api\Answers\ConnectedStoreExchange::from_answer( $answer ), wp_generate_uuid4(), untrailingslashit( home_url() ), time() ) );
						return new WP_REST_Response( null, 204 );
					},
				),
			)
		);

		// A customer account a test signs in with.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/customers',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$username = 'customer-' . wp_generate_password( 8, false );
					$password = wp_generate_password( 16, false );
					$user_id  = wp_insert_user(
						array(
							'user_login' => $username,
							'user_pass'  => $password,
							'user_email' => $username . '@example.com',
							'role'       => 'customer',
						)
					);
					if ( is_wp_error( $user_id ) ) {
						return $user_id;
					}
					return new WP_REST_Response(
						array(
							'username' => $username,
							'password' => $password,
						),
						201
					);
				},
			)
		);

		register_rest_route(
			'pixonaut-e2e/v1',
			'/products',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => array(
					'name'          => array(
						'type'    => 'string',
						'default' => 'E2E product',
					),
					'regular_price' => array(
						'type'    => 'string',
						'default' => '19.00',
					),
					// A stored Pixonaut configuration document, for a personalised product.
					'pixonaut'      => array(
						'type' => 'object',
					),
					// Values of a `size` attribute, for a variable product with a variation per value.
					'variations'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					// Values of a second variation attribute, `finish`, after `size`; each variation sells any finish.
					'finishes'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					// A stored configuration by `size` value, for a variation that overrides its product's.
					'overrides'     => array(
						'type' => 'object',
					),
					// The `size` value the add-to-cart form opens on; none by default.
					'default'       => array(
						'type' => 'string',
					),
					// A regular price by `size` value, for a variation priced other than `regular_price`.
					'prices'        => array(
						'type' => 'object',
					),
					// The blank variant the product, or each variation, sells; by default its blank's first one on sale.
					'variant'       => array(
						'type' => 'string',
					),
					// A blank variant ref by `size` value, for a variation that sells another than `variant`.
					'variants'      => array(
						'type' => 'object',
					),
				),
				'callback'            => static function ( WP_REST_Request $request ) {
					$sizes   = is_array( $request['variations'] ) ? array_map( 'strval', $request['variations'] ) : array();
					$product = array() === $sizes ? new WC_Product_Simple() : new WC_Product_Variable();
					$product->set_name( (string) $request['name'] );
					$product->set_status( 'publish' );
					if ( array() === $sizes ) {
						$product->set_regular_price( (string) $request['regular_price'] );
					} else {
						$attribute = new WC_Product_Attribute();
						$attribute->set_name( 'size' );
						$attribute->set_options( $sizes );
						$attribute->set_visible( true );
						$attribute->set_variation( true );
						$attributes = array( $attribute );
						if ( is_array( $request['finishes'] ) ) {
							$finish = new WC_Product_Attribute();
							$finish->set_name( 'finish' );
							$finish->set_options( array_map( 'strval', $request['finishes'] ) );
							$finish->set_visible( true );
							$finish->set_variation( true );
							$attributes[] = $finish;
						}
						$product->set_attributes( $attributes );
						if ( is_string( $request['default'] ) ) {
							$product->set_default_attributes( array( 'size' => $request['default'] ) );
						}
					}
					$product->save();
					$overrides     = is_array( $request['overrides'] ) ? $request['overrides'] : array();
					$prices        = is_array( $request['prices'] ) ? $request['prices'] : array();
					$variation_ids = array();
					foreach ( $sizes as $size ) {
						$variation = new WC_Product_Variation();
						$variation->set_parent_id( $product->get_id() );
						$variation->set_attributes( array( 'size' => $size ) );
						$variation->set_regular_price( (string) ( $prices[ $size ] ?? $request['regular_price'] ) );
						$variation->save();
						if ( is_array( $overrides[ $size ] ?? null ) ) {
							\Pixonaut\WooCommerce\Catalogue\ProductConfig::from_json( (string) wp_json_encode( $overrides[ $size ] ) )->with_override( true )->save_to( $variation );
						}
						$variation_ids[ $size ] = $variation->get_id();
					}
					if ( array() !== $sizes ) {
						WC_Product_Variable::sync( $product->get_id() );
					}
					if ( is_array( $request['pixonaut'] ) ) {
						\Pixonaut\WooCommerce\Catalogue\ProductConfig::from_json( (string) wp_json_encode( $request['pixonaut'] ) )->save_to( $product );
					}
					$variant = is_string( $request['variant'] ) ? $request['variant'] : null;
					if ( array() === $sizes ) {
						pixonaut_e2e_pick_variant( $product, $variant );
					}
					$by_size = is_array( $request['variants'] ) ? $request['variants'] : array();
					foreach ( $variation_ids as $size => $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation instanceof WC_Product ) {
							pixonaut_e2e_pick_variant( $variation, is_string( $by_size[ $size ] ?? null ) ? $by_size[ $size ] : $variant );
						}
					}

					return new WP_REST_Response(
						array(
							'id'         => $product->get_id(),
							'permalink'  => $product->get_permalink(),
							'variations' => (object) $variation_ids,
						),
						201
					);
				},
			)
		);

		// Forgets the Pixonaut configuration of every product and variation,
		// so the products earlier tests linked drop out of what a catalogue
		// change re-checks: the tests site keeps them from run to run.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/product-configurations',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$keys = array(
						\Pixonaut\WooCommerce\Schema::PRODUCT_CONFIG_META,
						\Pixonaut\WooCommerce\Schema::PRODUCT_TEMPLATE_INDEX_META,
						\Pixonaut\WooCommerce\Schema::PRODUCT_BLANK_INDEX_META,
						\Pixonaut\WooCommerce\Schema::PRODUCT_VARIANT_META,
					);
					foreach ( $keys as $key ) {
						delete_post_meta_by_key( $key );
					}
					return new WP_REST_Response( null, 204 );
				},
			)
		);
	}
);
