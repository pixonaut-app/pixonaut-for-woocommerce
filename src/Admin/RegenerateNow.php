<?php
/**
 * Regenerate now: draws product pictures at once, driven from the browser.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * **Regenerate now**, on a personalised product's Pixonaut tab, its row on
 * the products list and as a bulk action there, draws the products'
 * pictures while the store manager watches, without waiting on Action
 * Scheduler or WP-Cron. The browser first asks `START_ROUTE`, which queues
 * every product's pictures as **Regenerate in background** does, without
 * starting the runners, and answers with each product's pictures to draw.
 * Then it asks `DRAW_ROUTE` once for each, which draws that one picture
 * while the request runs and drops its queued job: no request runs longer
 * than one drawing. Whatever the tab does not reach, because it was closed
 * or stopped, is left queued and drawn in the background.
 */
final class RegenerateNow {

	/**
	 * Queues the products' pictures and lists each product's pictures to draw.
	 */
	public const START_ROUTE = '/pictures/now';

	/**
	 * Draws one product's or variation's pictures.
	 */
	public const DRAW_ROUTE = '/pictures/draw';

	/**
	 * The bulk action on the products list, and the products list's row action.
	 */
	public const BULK_ACTION = 'pixonaut_regenerate_pictures_now';

	/**
	 * Id of the element the script reads the routes and texts from.
	 */
	public const ROOT_ID = 'pixonaut-regenerate-now';

	/**
	 * Script handle of the products list's script.
	 */
	public const LIST_SCRIPT_HANDLE = 'pixonaut-wc-admin-products';

	/**
	 * The most products one start request takes.
	 */
	public const MAX_PRODUCTS = 500;

	/**
	 * The status of a draw request for a post that is not a product or variation shown with a picture.
	 */
	private const CONFLICT = 409;

	/**
	 * @param Templates          $templates  Renders the script's root.
	 * @param Settings           $settings   Says whether pictures are product images.
	 * @param RegeneratePictures $regenerate Queues the products' pictures, as Regenerate in background does.
	 * @param PictureQueue       $queue      Says which pictures are due.
	 * @param ProductPictures    $pictures   Draws them.
	 * @param string             $file       The plugin's main file.
	 */
	public function __construct(
		private readonly Templates $templates,
		private readonly Settings $settings,
		private readonly RegeneratePictures $regenerate,
		private readonly PictureQueue $queue,
		private readonly ProductPictures $pictures,
		private readonly string $file,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'root' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::START_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => static fn (): bool => current_user_can( CatalogueSearch::CAPABILITY ),
				'args'                => array(
					'product_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems' => 1,
						'maxItems' => self::MAX_PRODUCTS,
					),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::DRAW_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'draw' ),
				'permission_callback' => array( $this, 'can_draw' ),
				'args'                => array(
					'item' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * POST START_ROUTE: queues the pictures of each product named that the
	 * user may edit and that shows a picture, and answers with each one's
	 * pictures to draw, `{products: [{id, name, items, state}]}`: `items`
	 * are the product and the variations whose pictures are due, `state` is
	 * how they stand now (`RegeneratePictures::state()`). Products passed over are left out.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response {
		$products = array();
		foreach ( array_unique( array_map( 'intval', (array) $request['product_ids'] ) ) as $id ) {
			$product = current_user_can( 'edit_post', $id ) ? $this->regenerate->pictured_product( $id ) : null;
			if ( null !== $product ) {
				$products[] = $product;
			}
		}
		$standings = $this->regenerate->redraw( $products, false );
		$answers   = array();
		foreach ( $products as $index => $product ) {
			$answers[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'items' => $this->due( $product ),
				'state' => $standings[ $index ]['state'],
			);
		}
		return new WP_REST_Response( array( 'products' => $answers ) );
	}

	/**
	 * Whether the user may edit the product the item is, or is a variation of.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_draw( WP_REST_Request $request ): bool {
		$item   = (int) $request['item'];
		$parent = 'product_variation' === get_post_type( $item ) ? (int) wp_get_post_parent_id( $item ) : $item;
		return $parent > 0 && current_user_can( 'edit_post', $parent );
	}

	/**
	 * POST DRAW_ROUTE: draws the product's or variation's pictures now, and
	 * drops the job queued for them; answers `{state}`: `drawn`, `failed`
	 * (Pixonaut could not draw them; the job tries again later), `kept`
	 * (the store manager's own image stays) or `drawing` (another job is
	 * drawing them, so they are left to it).
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function draw( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item    = (int) $request['item'];
		$product = PictureQueue::fresh( $item );
		$owner   = null === $product ? null : $this->regenerate->pictured_product( $product->is_type( 'variation' ) ? $product->get_parent_id() : $item );
		if ( null === $product || null === $owner ) {
			return new WP_Error( 'pixonaut_wc_not_pictured', __( 'This product shows no Pixonaut picture.', 'pixonaut-for-woocommerce' ), array( 'status' => self::CONFLICT ) );
		}
		wc_set_time_limit( ProductPictures::LOCK_SECONDS );
		if ( $this->queue->is_due( $product ) ) {
			$this->pictures->refresh( $item );
		}
		$after = PictureQueue::fresh( $item ) ?? $product;
		if ( $this->queue->is_due( $after ) ) {
			$stored = ProductPictures::stored( $after );
			$failed = null !== $stored['failed_at'] && ProductPictures::basis( $after ) === $stored['basis'];
			return new WP_REST_Response( array( 'state' => $failed ? RegeneratePictures::FAILED : RegeneratePictures::DRAWING ) );
		}
		// Drawn here: the job queued for it has nothing left to do.
		as_unschedule_action( ProductPictures::ACTION, array( $item ), ProductPictures::GROUP );
		$stored = ProductPictures::stored( $after );
		if ( null !== $stored['failed_at'] ) {
			return new WP_REST_Response( array( 'state' => RegeneratePictures::FAILED ) );
		}
		return new WP_REST_Response( array( 'state' => PictureImage::is_own( $after ) ? RegeneratePictures::KEPT : RegeneratePictures::DRAWN ) );
	}

	/**
	 * The product and those of its variations whose pictures are due.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return list<int>
	 */
	private function due( WC_Product $product ): array {
		$items = array();
		foreach ( array_merge( array( $product->get_id() ), $product->get_children() ) as $id ) {
			$each = PictureQueue::fresh( (int) $id );
			if ( null !== $each && $this->queue->is_due( $each ) ) {
				$items[] = (int) $id;
			}
		}
		return $items;
	}

	/**
	 * Loads the products list's script.
	 *
	 * @param string $hook_suffix The admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || 'edit-product' !== get_current_screen()?->id || ! ProductPictures::enabled( $this->settings ) ) {
			return;
		}
		$asset_file = dirname( $this->file ) . '/assets/build/admin-products.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset   = require $asset_file;
		$version = is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION;
		wp_enqueue_style( self::LIST_SCRIPT_HANDLE, plugins_url( 'assets/build/admin-products.css', $this->file ), array(), $version );
		wp_enqueue_script( self::LIST_SCRIPT_HANDLE, plugins_url( 'assets/build/admin-products.js', $this->file ), is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(), $version, true );
	}

	/**
	 * Prints the script's root on the products list and the product screen
	 * while pictures are product images: the routes, and the confirmation's
	 * and the progress' texts.
	 */
	public function root(): void {
		$screen = get_current_screen()?->id;
		if ( ! in_array( $screen, array( 'edit-product', 'product' ), true ) || ! ProductPictures::enabled( $this->settings ) ) {
			return;
		}
		$html = $this->templates->html(
			'admin/regenerate-now.php',
			array(
				'root_id'    => self::ROOT_ID,
				'start_url'  => rest_url( Plugin::REST_NAMESPACE . self::START_ROUTE ),
				'draw_url'   => rest_url( Plugin::REST_NAMESPACE . self::DRAW_ROUTE ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
	}
}
