<?php
/**
 * Reports from product pages whose designer could not load.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use RuntimeException;
use WC_Product;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * A public route the storefront script calls when a side cannot be
 * personalised. Anyone can call it, so a report is a hint, never proof: a
 * placement Pixonaut no longer resolves is checked with Pixonaut before the
 * product is flagged invalid, and an SDK that failed to start in one browser
 * only raises a notice for the store manager.
 */
final class UnavailableReports {

	public const ROUTE = '/storefront/unavailable';

	/**
	 * The SDK's wasm did not match the hashes the script carries.
	 */
	public const WASM_INTEGRITY = 'wasm_integrity';

	/**
	 * The SDK's wasm could not be loaded or started.
	 */
	public const WASM_BOOT = 'wasm_boot';

	/**
	 * The SDK's wasm came from another release than its script.
	 */
	public const WASM_VERSION_MISMATCH = 'wasm_version_mismatch';

	/**
	 * The template is newer than the pinned SDK's renderer draws: Pixonaut
	 * refused it to this release, or the renderer could not read it.
	 */
	public const SDK_OUTDATED = 'sdk_outdated';

	/**
	 * The SDK's script did not load: blocked by the site's Content Security
	 * Policy, refused by its SRI, or the CDN unreachable.
	 */
	public const SDK_LOAD = 'sdk_load';

	public const REASONS = array(
		self::WASM_INTEGRITY,
		self::WASM_BOOT,
		self::WASM_VERSION_MISMATCH,
		self::SDK_OUTDATED,
		self::SDK_LOAD,
		InvalidReason::UNKNOWN_PLACEMENT,
	);

	/**
	 * Invalid reason stored on a product whose placement Pixonaut no longer resolves.
	 */
	public const INVALID_REASON = InvalidReason::UNKNOWN_PLACEMENT;

	/**
	 * How long one report of a side and reason is not acted on again: an
	 * hour. Not `HOUR_IN_SECONDS`, which is not defined outside WordPress.
	 */
	private const THROTTLE_SECONDS = 3600;

	/**
	 * Start of the key of the notice that the SDK is older than a template.
	 */
	private const OUTDATED_NOTICE = 'storefront_sdk_' . self::SDK_OUTDATED;

	/**
	 * @param InlineProducts $products  Resolves the product's designer.
	 * @param Catalogue      $catalogue Asks Pixonaut whether a placement resolves.
	 * @param Notices        $notices   Admin notices.
	 * @param Logger         $logger    Logs a check that could not be made.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly Catalogue $catalogue,
		private readonly Notices $notices,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'admin_init', array( $this, 'forget_outdated_notices' ) );
	}

	/**
	 * Key of the notice that the pinned SDK is older than a template. It
	 * names the SDK release, so a plugin update that pins a newer one leaves
	 * the notice behind (see forget_outdated_notices()).
	 */
	public static function outdated_notice_key(): string {
		return self::OUTDATED_NOTICE . '_' . (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( Sdk::VERSION ) );
	}

	/**
	 * Drops the notice that the SDK is older than a template when it was
	 * raised under another SDK release: the update that brought this one may
	 * have fixed it, and a template still too new reports it again.
	 */
	public function forget_outdated_notices(): void {
		foreach ( array_keys( $this->notices->all() ) as $key ) {
			if ( str_starts_with( $key, self::OUTDATED_NOTICE ) && self::outdated_notice_key() !== $key ) {
				$this->notices->remove( $key );
			}
		}
	}

	/**
	 * The route's address, for the storefront script.
	 */
	public static function url(): string {
		return rest_url( Plugin::REST_NAMESPACE . self::ROUTE );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				// Customers report from public pages, signed in or not.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'product' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'side'    => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 64,
					),
					'reason'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::REASONS,
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The report.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$product  = wc_get_product( (int) $request['product'] );
		$designer = $product instanceof WC_Product ? $this->products->for_product( $product ) : null;
		$side     = (string) $request['side'];
		$reason   = (string) $request['reason'];
		if ( null === $designer || ! isset( $designer->mounts[ $side ] ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		if ( $this->throttled( $designer->product->get_id(), $side, $reason ) ) {
			return new WP_REST_Response( null, 202 );
		}
		if ( InvalidReason::UNKNOWN_PLACEMENT === $reason ) {
			$this->check_placement( $designer, $side );
		} elseif ( self::SDK_OUTDATED === $reason ) {
			$this->notices->add(
				self::outdated_notice_key(),
				sprintf(
					/* translators: %s: the product's name. */
					__( 'Customers cannot personalise “%s”: this version of Pixonaut for WooCommerce is older than the product’s template. Update the Pixonaut for WooCommerce plugin to fix this.', 'pixonaut-for-woocommerce' ),
					$designer->product->get_name()
				),
				'error'
			);
		} else {
			$this->notices->add(
				'storefront_sdk_' . $reason,
				sprintf(
					/* translators: 1: the product's name, 2: the host the SDK loads from, e.g. cdn.pixonaut.app. */
					__( 'The Pixonaut designer could not start for a customer on “%1$s”, so they could not personalise it. Check that the site lets the browser load and run the Pixonaut SDK from %2$s (see Content Security Policy in the Pixonaut for WooCommerce developer docs).', 'pixonaut-for-woocommerce' ),
					$designer->product->get_name(),
					(string) wp_parse_url( Sdk::script( new Settings() )['url'], PHP_URL_HOST )
				)
			);
		}
		return new WP_REST_Response( null, 202 );
	}

	/**
	 * Flags the product invalid when Pixonaut confirms it no longer resolves
	 * the side's placement. The stored placement is checked, not the mount
	 * options a theme may have filtered: only the product's own
	 * configuration being gone makes it invalid.
	 *
	 * @param InlineDesigner $designer The product's designer.
	 * @param string         $key      The side reported.
	 */
	private function check_placement( InlineDesigner $designer, string $key ): void {
		$side = $this->side( $designer, $key );
		if ( null === $side || null === $side->template_version || null === $designer->config->blank_id || null === $side->placement_id ) {
			return;
		}
		try {
			if ( $this->placement_resolves( InlineProducts::template_id( $side ), $designer->config->blank_id, $side->placement_id ) ) {
				return;
			}
			if ( ! $this->invalidate( $designer ) ) {
				return;
			}
		} catch ( ApiError | RuntimeException $e ) {
			$this->logger->error( 'Could not check a reported placement: ' . $e->getMessage(), array( 'product' => $designer->product->get_id() ) );
			return;
		}
		$this->notices->add(
			'storefront_product_' . $designer->product->get_id() . '_unknown_placement',
			sprintf(
				/* translators: 1: the product's name, 2: the side's name, e.g. Front. */
				__( 'Pixonaut no longer has the placement that the %2$s side of “%1$s” prints on, so customers cannot personalise it. Link the product to a placement again.', 'pixonaut-for-woocommerce' ),
				$designer->product->get_name(),
				$side->label
			),
			'error'
		);
	}

	/**
	 * Whether Pixonaut still resolves the pair for the template.
	 *
	 * @param string $template_id  Template id, `baseId@version`.
	 * @param string $blank_id     The blank.
	 * @param string $placement_id The placement on it.
	 *
	 * @throws ApiError When Pixonaut refused for another reason or could not be reached.
	 */
	private function placement_resolves( string $template_id, string $blank_id, string $placement_id ): bool {
		try {
			$this->catalogue->constraints( $template_id, $blank_id, $placement_id );
		} catch ( ApiError $e ) {
			if ( InvalidReason::UNKNOWN_PLACEMENT === $e->error_code ) {
				return false;
			}
			throw $e;
		}
		return true;
	}

	/**
	 * Stores the product's configuration as invalid (a variation's, where it
	 * overrides its product's), unless the store
	 * manager changed it while Pixonaut was asked. The configuration is read
	 * again from the database just before it is saved; a change in the
	 * moment between that read and the save is still overwritten.
	 *
	 * @param InlineDesigner $designer The product's designer, with the configuration that was checked.
	 * @return bool Whether it was flagged.
	 */
	private function invalidate( InlineDesigner $designer ): bool {
		$product = wc_get_product( $designer->product->get_id() );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$product->read_meta_data( true );
		$holder = self::holder( $product );
		if ( null === $holder ) {
			return false;
		}
		$holder->read_meta_data( true );
		$stored = ProductConfig::stored_on( $holder );
		// The designer's configuration went through the side filter; compare like with like.
		if ( $stored->resolved_for( $product )->for_designer()->to_json() !== $designer->config->to_json() ) {
			return false;
		}
		$stored->with_validity( false, self::INVALID_REASON )->save_to( $holder );
		return true;
	}

	/**
	 * The product whose stored configuration applies: a variation that
	 * overrides holds its own, any other takes its product's.
	 *
	 * @param WC_Product $product A product or variation, its meta read.
	 */
	private static function holder( WC_Product $product ): ?WC_Product {
		if ( ! $product->is_type( 'variation' ) || ProductConfig::stored_on( $product )->override ) {
			return $product;
		}
		$parent = wc_get_product( $product->get_parent_id() );
		return $parent instanceof WC_Product ? $parent : null;
	}

	/**
	 * @param InlineDesigner $designer The product's designer.
	 * @param string         $key      A side key.
	 */
	private function side( InlineDesigner $designer, string $key ): ?Side {
		foreach ( $designer->config->sides as $side ) {
			if ( $side->key === $key ) {
				return $side;
			}
		}
		return null;
	}

	/**
	 * The option that holds a report's throttle window. An outdated SDK's
	 * window also names the SDK release, so a report under the release an
	 * update brings is acted on at once.
	 *
	 * @param int    $product_id The product.
	 * @param string $side       The side.
	 * @param string $reason     The reason.
	 */
	public static function throttle_option( int $product_id, string $side, string $reason ): string {
		$window = $product_id . '|' . $side . '|' . $reason;
		if ( self::SDK_OUTDATED === $reason ) {
			$window .= '|' . Sdk::VERSION;
		}
		return Schema::REPORT_THROTTLE_OPTION_PREFIX . md5( $window );
	}

	/**
	 * Whether the same report came within the throttle window; starts one
	 * when not. Of reports arriving together, only the one that claims the
	 * window goes on.
	 *
	 * @param int    $product_id The product.
	 * @param string $side       The side.
	 * @param string $reason     The reason.
	 */
	private function throttled( int $product_id, string $side, string $reason ): bool {
		$option = self::throttle_option( $product_id, $side, $reason );
		if ( $this->claim( $option ) ) {
			return false;
		}
		$ends = $this->window_end( $option );
		if ( is_numeric( $ends ) && (int) $ends > time() ) {
			return true;
		}
		if ( null !== $ends ) {
			$this->release( $option, $ends );
		}
		return ! $this->claim( $option );
	}

	/**
	 * Inserts the window's option; false when it exists. Straight to the
	 * table, since `add_option()` overwrites an option it did not see.
	 *
	 * @param string $option The window's option.
	 */
	private function claim( string $option ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic insert-if-absent, which the options API lacks.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $option, (string) ( time() + self::THROTTLE_SECONDS ) ) );
		return 1 === $inserted;
	}

	/**
	 * When the window ends, as stored, or null when there is none.
	 *
	 * @param string $option The window's option.
	 */
	private function window_end( string $option ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read beside the claim, past the options cache.
		$ends = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		return is_string( $ends ) ? $ends : null;
	}

	/**
	 * Removes an ended window, unless another report has claimed it anew.
	 *
	 * @param string $option The window's option.
	 * @param string $ends   When the ended window ended, as stored.
	 */
	private function release( string $option, string $ends ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes only the window that was read.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $ends ) );
	}
}
