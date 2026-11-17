<?php
/**
 * "Generate a picture for every variation": the product's choice, and what
 * turning it on or off costs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\PictureReport;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\VariationPictureCost;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Admin_Meta_Boxes;
use WC_Product;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * A variable product's Pixonaut tab chooses whether its variations each
 * get a picture (`VariationPictures`): as the store-wide setting, on or
 * off. Before the choice is saved, the tab and the settings page show what
 * it costs (`VariationPictureCost`), read from `ROUTE`; past the warning,
 * turning it on is saved only with `CONFIRM_FIELD` ticked. The settings
 * page saves the store-wide setting the same way (`SettingsPage`).
 */
final class VariationPicturesField {

	/**
	 * The route answering what a change costs: `?product=<id>&choice=<choice>`
	 * for a product, `?on=<0|1>` for the store-wide setting, with
	 * `&pictures=1` when product pictures are turned on with it.
	 */
	public const ROUTE = '/variation-pictures/cost';

	/**
	 * The product's choice in the Pixonaut tab.
	 */
	public const FIELD = 'pixonaut_variation_pictures';

	/**
	 * Ticked to turn it on past the warning, on the settings page and in the tab.
	 */
	public const CONFIRM_FIELD = 'pixonaut_variation_pictures_confirm';

	/**
	 * The value of CONFIRM_FIELD when ticked.
	 */
	public const CONFIRMED = '1';

	/**
	 * Who may read what the store-wide setting costs.
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * @param Templates            $templates Renders the field.
	 * @param Settings             $settings  The store-wide setting.
	 * @param VariationPictureCost $cost      What a change costs.
	 */
	public function __construct(
		private readonly Templates $templates,
		private readonly Settings $settings,
		private readonly VariationPictureCost $cost,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// After the panel's own save, which stores the configuration this reads.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20 );
	}

	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'product'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'choice'   => array(
						'type' => 'string',
						'enum' => VariationPictures::CHOICES,
					),
					'on'       => array( 'type' => 'boolean' ),
					'pictures' => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_read( WP_REST_Request $request ): bool {
		return null === $request['product'] ? current_user_can( self::CAPABILITY ) : current_user_can( 'edit_post', (int) $request['product'] );
	}

	/**
	 * GET: what the change costs, with the text the store manager reads.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		if ( true === $request['pictures'] ) {
			// Product pictures turned on too: every product that has variation pictures on draws them.
			return new WP_REST_Response( self::answer( array( 'on' => true ) + $this->cost->turning_on( $this->cost->resolving_on( true === $request['on'] ) ) ) );
		}
		if ( null === $request['product'] ) {
			return new WP_REST_Response( self::answer( $this->cost->changing_to( $this->cost->following_store_wide(), true === $request['on'] ) ) );
		}
		$product = wc_get_product( (int) $request['product'] );
		$choice  = is_string( $request['choice'] ) ? $request['choice'] : VariationPictures::DEFAULT;
		return new WP_REST_Response( self::answer( $this->product_cost( $product instanceof WC_Product ? $product : null, $choice ) ) );
	}

	/**
	 * What choosing for the product costs; nothing for a product that is not
	 * a variable one.
	 *
	 * @param WC_Product|null $product A product.
	 * @param string          $choice  DEFAULT, ON or OFF.
	 *
	 * @return array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool}
	 */
	public function product_cost( ?WC_Product $product, string $choice ): array {
		$on       = VariationPictures::resolves( $choice, VariationPictures::store_wide( $this->settings ) );
		$products = null !== $product && $product->is_type( 'variable' ) ? array( $product ) : array();
		return $this->cost->changing_to( $products, $on );
	}

	/**
	 * The field, for a variable product's Pixonaut tab while pictures are
	 * product images; empty otherwise.
	 *
	 * @param WC_Product $product A product.
	 */
	public function html( WC_Product $product ): string {
		if ( ! $product->is_type( 'variable' ) || ! ProductPictures::enabled( $this->settings ) || null === ProductConfig::document_on( $product ) ) {
			return '';
		}
		return $this->templates->html(
			'admin/product-variation-pictures.php',
			array(
				'field'         => self::FIELD,
				'anchor'        => PictureReport::SETTING_ANCHOR,
				'choice'        => VariationPictures::choice( $product ),
				'store_wide'    => VariationPictures::store_wide( $this->settings ),
				'cost_url'      => rest_url( Plugin::REST_NAMESPACE . self::ROUTE ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'confirm_field' => self::CONFIRM_FIELD,
				'product_id'    => $product->get_id(),
			)
		);
	}

	/**
	 * Saves the product's choice. Turning it on past the warning without the
	 * confirmation keeps the choice it had, and says why.
	 *
	 * @param int $product_id The product being saved.
	 */
	public function save( int $product_id ): void {
		// The panel's nonce is checked by WooCommerce's save, which runs this.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- as above.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}
		$choice    = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );
		$confirmed = self::CONFIRMED === ( isset( $_POST[ self::CONFIRM_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::CONFIRM_FIELD ] ) ) : '' );
		// phpcs:enable
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! in_array( $choice, VariationPictures::CHOICES, true ) || VariationPictures::choice( $product ) === $choice ) {
			return;
		}
		$warning = $confirmed ? null : VariationPictureCost::warning( $this->product_cost( $product, $choice ) );
		if ( null !== $warning ) {
			WC_Admin_Meta_Boxes::add_error( self::refused( $warning ) );
			return;
		}
		VariationPictures::choose( $product, $choice );
	}

	/**
	 * Why turning it on was not saved.
	 *
	 * @param string $warning The warning it needed confirming.
	 */
	public static function refused( string $warning ): string {
		return $warning . ' ' . __( 'A picture for every variation was not turned on: tick "Turn it on anyway" under it to confirm, then save again.', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Why turning product pictures on was not saved, while products have a
	 * picture for every variation on.
	 *
	 * @param string $warning The warning it needed confirming.
	 */
	public static function refused_pictures( string $warning ): string {
		return $warning . ' ' . __( 'Pixonaut pictures were not turned on as product images, as products with a picture for every variation would draw them all: tick "Turn it on anyway" under Generate a picture for every variation to confirm, or turn that off, then save again.', 'pixonaut-for-woocommerce' );
	}

	/**
	 * The route's answer.
	 *
	 * @param array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool} $cost What the change costs.
	 *
	 * @return array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool, summary: string, warning: string|null}
	 */
	private static function answer( array $cost ): array {
		return $cost + array(
			'summary' => VariationPictureCost::summary( $cost ),
			'warning' => VariationPictureCost::warning( $cost ),
		);
	}
}
