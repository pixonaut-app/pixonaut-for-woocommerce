<?php
/**
 * Plugin Name: Pixonaut for WooCommerce e2e orders
 * Description: Test-only checkout, payment and background-job helpers for the e2e journey. Mapped into wp-env's tests environment only.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! defined( 'PIXONAUT_WC_E2E' ) || ! PIXONAUT_WC_E2E ) {
	return;
}

/**
 * Cookie that sends one browser context to the shortcode checkout instead of
 * the store's block checkout.
 */
const PIXONAUT_E2E_CHECKOUT_COOKIE = 'pixonaut_e2e_checkout';

/**
 * Option that keeps the id of the shortcode checkout page the setup made.
 */
const PIXONAUT_E2E_CLASSIC_CHECKOUT_OPTION = 'pixonaut_e2e_classic_checkout_page';

/**
 * Pages the cart setup made, by what they hold: the classic cart's shortcode,
 * or the Mini-Cart block. Each value is the option keeping the page's id.
 */
const PIXONAUT_E2E_CART_PAGES = array(
	'classic' => array(
		'option'  => 'pixonaut_e2e_classic_cart_page',
		'title'   => 'Classic cart',
		'content' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->',
	),
	'mini'    => array(
		'option'  => 'pixonaut_e2e_mini_cart_page',
		'title'   => 'Mini cart',
		'content' => '<!-- wp:woocommerce/mini-cart /-->',
	),
);

/**
 * The test gateway: cash on delivery moves a placed order to processing, as
 * a paid order does.
 */
const PIXONAUT_E2E_GATEWAY = 'cod';

/**
 * Price of the product the plugin does not fulfil.
 */
const PIXONAUT_E2E_PLAIN_PRICE = '10';

/**
 * WooCommerce's zone for every address no other zone covers.
 */
const PIXONAUT_E2E_REST_OF_WORLD_ZONE = 0;

// Action Scheduler's own runners (WP-Cron and the async request) stay idle:
// a test runs the plugin's jobs itself, through `/pixonaut-e2e/v1/jobs`, and
// reads what is pending before it does. With no batch allowed, a runner
// that starts claims nothing.
add_filter( 'action_scheduler_queue_runner_concurrent_batches', '__return_zero' );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

add_filter(
	'pre_option_woocommerce_checkout_page_id',
	static function ( $value ) {
		$classic = isset( $_COOKIE[ PIXONAUT_E2E_CHECKOUT_COOKIE ] ) && 'classic' === $_COOKIE[ PIXONAUT_E2E_CHECKOUT_COOKIE ];
		$page_id = (int) get_option( PIXONAUT_E2E_CLASSIC_CHECKOUT_OPTION, 0 );
		return $classic && $page_id > 0 ? $page_id : $value;
	}
);

/**
 * Makes the store able to take an order: the test gateway, free shipping
 * everywhere, and the shortcode checkout page. Running it again changes
 * nothing.
 */
function pixonaut_e2e_prepare_checkout(): void {
	$gateway            = (array) get_option( 'woocommerce_' . PIXONAUT_E2E_GATEWAY . '_settings', array() );
	$gateway['enabled'] = 'yes';
	update_option( 'woocommerce_' . PIXONAUT_E2E_GATEWAY . '_settings', $gateway );

	$zone    = new WC_Shipping_Zone( PIXONAUT_E2E_REST_OF_WORLD_ZONE );
	$methods = array_map( static fn ( $method ) => $method->id, $zone->get_shipping_methods() );
	if ( ! in_array( 'free_shipping', $methods, true ) ) {
		$zone->add_shipping_method( 'free_shipping' );
	}

	if ( ! get_post( (int) get_option( PIXONAUT_E2E_CLASSIC_CHECKOUT_OPTION, 0 ) ) instanceof WP_Post ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Classic checkout',
				'post_name'    => 'classic-checkout',
				'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
			)
		);
		update_option( PIXONAUT_E2E_CLASSIC_CHECKOUT_OPTION, $page_id, false );
	}
}

/**
 * The order's status, its Pixonaut lines' state and its notes, newest first,
 * as a test reads them.
 *
 * @param WC_Order $order An order.
 *
 * @return array<string, mixed>
 */
function pixonaut_e2e_order( WC_Order $order ): array {
	$lines = array();
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! \Pixonaut\WooCommerce\Fulfilment\LineState::is_tracked( $item ) ) {
			continue;
		}
		$line  = \Pixonaut\WooCommerce\Fulfilment\LineState::of( $item );
		$refs  = array();
		$sides = array();
		foreach ( \Pixonaut\WooCommerce\Fulfilment\LineState::REFS as $name ) {
			$refs[ $name ] = $line->ref( $name );
		}
		foreach ( $line->sides() as $side ) {
			foreach ( \Pixonaut\WooCommerce\Fulfilment\LineState::SIDE_REFS as $name ) {
				$sides[ $side ][ $name ] = $line->side_ref( $side, $name );
			}
		}
		$lines[] = array(
			'itemId' => $item->get_id(),
			'state'  => $line->state(),
			'reason' => $line->reason(),
			'refs'   => $refs,
			'sides'  => $sides,
		);
	}
	$notes = array_map( static fn ( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	$edit  = wp_parse_url( $order->get_edit_order_url() );
	return array(
		'status'  => $order->get_status(),
		'lines'   => $lines,
		'notes'   => $notes,
		// Path and query only: the browser reaches the site by another host than the site knows itself by.
		'editUrl' => ( $edit['path'] ?? '' ) . ( isset( $edit['query'] ) ? '?' . $edit['query'] : '' ),
	);
}

/**
 * The plugin's pending Action Scheduler jobs, by action id.
 *
 * @param array<string, mixed> $query More `as_get_scheduled_actions()` arguments.
 *
 * @return array<int, ActionScheduler_Action>
 */
function pixonaut_e2e_pending_actions( array $query ): array {
	return as_get_scheduled_actions(
		array(
			'group'    => \Pixonaut\WooCommerce\Schema::ACTION_GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		) + $query,
		'objects'
	);
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'pixonaut-e2e/v1',
			'/checkout',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				// Answers each checkout's address; the cookie picks which
				// one a browser context gets at `wc_get_checkout_url()`.
				'callback'            => static function () {
					pixonaut_e2e_prepare_checkout();
					return new WP_REST_Response(
						array(
							'classic' => get_permalink( (int) get_option( PIXONAUT_E2E_CLASSIC_CHECKOUT_OPTION ) ),
							'block'   => get_permalink( wc_get_page_id( 'checkout' ) ),
						)
					);
				},
			)
		);

		register_rest_route(
			'pixonaut-e2e/v1',
			'/cart',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				// Answers each cart's address: the store's cart page holds
				// the Cart block, and the pages made here the classic cart
				// and the Mini-Cart block.
				'callback'            => static function () {
					$urls = array( 'block' => get_permalink( wc_get_page_id( 'cart' ) ) );
					foreach ( PIXONAUT_E2E_CART_PAGES as $name => $page ) {
						if ( ! get_post( (int) get_option( $page['option'], 0 ) ) instanceof WP_Post ) {
							$page_id = wp_insert_post(
								array(
									'post_type'    => 'page',
									'post_status'  => 'publish',
									'post_title'   => $page['title'],
									'post_content' => $page['content'],
								)
							);
							update_option( $page['option'], $page_id, false );
						}
						$urls[ $name ] = get_permalink( (int) get_option( $page['option'] ) );
					}
					return new WP_REST_Response( $urls );
				},
			)
		);

		// Runs the plugin's due background jobs now, as Action Scheduler's
		// queue runner would on its next pass; its own runners are off here.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/jobs',
			array(
				// The plugin's pending jobs, due or not, and which are due.
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						$jobs = array();
						foreach ( pixonaut_e2e_pending_actions( array() ) as $action ) {
							$when   = $action->get_schedule();
							$date   = $when instanceof ActionScheduler_Abstract_Schedule ? $when->get_date() : null;
							$jobs[] = array(
								'hook' => $action->get_hook(),
								'args' => $action->get_args(),
								'due'  => null === $date || $date->getTimestamp() <= time(),
							);
						}
						return new WP_REST_Response( $jobs );
					},
				),
				// With `early`, also the jobs scheduled for later, such as retries.
				// With `hook`, that hook's pending jobs, due or not: a recurring
				// job is next due minutes away. With `item` too, only that order
				// item's, so a test leaves other tests' lines alone.
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $request ) {
						$hook  = $request->get_param( 'hook' );
						$item  = (int) $request->get_param( 'item' );
						$query = $request->get_param( 'early' ) ? array() : array( 'date' => gmdate( 'Y-m-d H:i:s' ) );
						if ( is_string( $hook ) && '' !== $hook ) {
							$query = array( 'hook' => $hook );
						}
						$due = pixonaut_e2e_pending_actions( $query );
						if ( 0 !== $item ) {
							$due = array_filter( $due, static fn ( $action ): bool => (int) ( $action->get_args()['item_id'] ?? 0 ) === $item );
						}
						$runner = ActionScheduler::runner();
						foreach ( array_keys( $due ) as $id ) {
							$runner->process_action( (int) $id, 'E2E' );
						}
						return new WP_REST_Response( array( 'ran' => count( $due ) ) );
					},
				),
			)
		);

		// Publishes a product the plugin does not fulfil; answers its id.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/plain-product',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$product = new WC_Product_Simple();
					$product->set_name( 'Plain mug ' . wp_generate_password( 6, false ) );
					$product->set_regular_price( PIXONAUT_E2E_PLAIN_PRICE );
					$product->set_status( 'publish' );
					return new WP_REST_Response( array( 'id' => $product->save() ) );
				},
			)
		);

		register_rest_route(
			'pixonaut-e2e/v1',
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = wc_get_order( (int) $request['id'] );
					if ( ! $order instanceof WC_Order ) {
						return new WP_Error( 'pixonaut_e2e_no_order', 'No such order.', array( 'status' => 404 ) );
					}
					return new WP_REST_Response( pixonaut_e2e_order( $order ) );
				},
			)
		);

		// Fires the payment hook again, as a gateway that reports a payment
		// twice does.
		register_rest_route(
			'pixonaut-e2e/v1',
			'/orders/(?P<id>\d+)/payment-complete',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					/**
					 * WooCommerce's payment hook, with the order id and no transaction id.
					 *
					 * @since 0.1.0
					 */
					do_action( 'woocommerce_payment_complete', (int) $request['id'], '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired again on purpose.
					return new WP_REST_Response( null, 204 );
				},
			)
		);
	}
);
