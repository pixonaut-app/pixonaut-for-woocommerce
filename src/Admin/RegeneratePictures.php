<?php
/**
 * Regenerate product image: draws a product's Pixonaut pictures again.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\PictureRunner;
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
 * While the store uses Pixonaut pictures as product images, a personalised
 * product's Pixonaut tab has **Regenerate product image**, and the products
 * list a bulk action of the same name. Each draws the product's image, its
 * gallery pictures and its variations' images again from the template
 * version and settings they use now (`ProductPictures::regenerate()`), in
 * the pictures' jobs, which runners started at once draw side by side
 * (`PictureRunner`). The button asks the product's route until every
 * picture is drawn and says how it went.
 */
final class RegeneratePictures {

	/**
	 * The product's route: GET says how its pictures stand, POST draws them again.
	 */
	public const ROUTE = '/products/(?P<id>\d+)/pictures';

	/**
	 * The bulk action on the products list.
	 */
	public const BULK_ACTION = 'pixonaut_regenerate_pictures';

	/**
	 * Query argument the products list is sent back with: how many products were queued.
	 */
	public const DONE_ARG = 'pixonaut_regenerated';

	/**
	 * The pictures are being drawn, or wait to be.
	 */
	public const DRAWING = 'drawing';

	/**
	 * The pictures are drawn from what the product is now.
	 */
	public const DRAWN = 'drawn';

	/**
	 * Pixonaut could not draw them; the job tries again later.
	 */
	public const FAILED = 'failed';

	/**
	 * The store manager's own image is kept; the product's other pictures,
	 * if it has any, are drawn again.
	 */
	public const KEPT = 'kept';

	/**
	 * Nothing was queued to draw the pictures again, though they are drawn
	 * from what the product was before. The button's line says the
	 * regeneration could not be done, as when the route could not be asked.
	 */
	public const NOT_QUEUED = 'error';

	/**
	 * The status of a request for a product that shows no picture.
	 */
	private const CONFLICT = 409;

	/**
	 * The arguments `handle_bulk_actions-edit-product` passes.
	 */
	private const BULK_ARGS = 3;

	/**
	 * The arguments `post_row_actions` passes.
	 */
	private const ROW_ARGS = 2;

	/**
	 * The nonce WordPress checks before a products list bulk action.
	 */
	private const BULK_NONCE = 'bulk-posts';

	/**
	 * The product types the Pixonaut tab configures.
	 */
	private const PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * @param Templates    $templates Renders the button.
	 * @param Settings     $settings  Says whether pictures are product images.
	 * @param PictureQueue $queue     Queues the variations' drawings.
	 * @param \Closure     $runner    `fn(): ?PictureRunner`, the runners that draw them at once; null before Action Scheduler is loaded.
	 */
	public function __construct(
		private readonly Templates $templates,
		private readonly Settings $settings,
		private readonly PictureQueue $queue,
		private readonly \Closure $runner,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, self::ROW_ARGS );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk' ), 10, self::BULK_ARGS );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	public function register_routes(): void {
		$args = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $args,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * GET: how the product's pictures stand, `{state}`.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = $this->pictured_product( (int) $request['id'] );
		return null === $product ? self::not_pictured() : $this->answer( $product );
	}

	/**
	 * POST: draws the product's pictures again, and says how they stand, `{state}`.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function regenerate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = $this->pictured_product( (int) $request['id'] );
		if ( null === $product ) {
			return self::not_pictured();
		}
		return new WP_REST_Response( $this->redraw( array( $product ) )[0] );
	}

	/**
	 * Draws the products' pictures again, as the button and the bulk action
	 * do, and says how each stands.
	 *
	 * @param WC_Product[] $products Simple or variable products that show a Pixonaut picture (`pictured_product()`).
	 * @phpstan-param list<WC_Product> $products
	 * @param bool         $start    Whether to start the runners, which draw them at once side by side; Regenerate now draws them from the browser instead.
	 * @return list<array{state: string, failed?: string}> In the products' order.
	 */
	public function redraw( array $products, bool $start = true ): array {
		foreach ( $products as $product ) {
			$this->queue_drawing( $product );
		}
		// Read before the runners start: a quick runner could finish a drawing before it is told as queued.
		$standings = array_map( fn ( WC_Product $product ): array => $this->standing( PictureQueue::fresh( $product->get_id() ) ?? $product ), $products );
		if ( $start && array() !== $products ) {
			$this->start();
		}
		return $standings;
	}

	/**
	 * The button, for the Pixonaut tab; empty for a product that shows no picture.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public function html( WC_Product $product ): string {
		if ( ! $this->pictured( $product ) ) {
			return '';
		}
		return $this->templates->html(
			'admin/product-regenerate.php',
			array(
				'url'        => rest_url( Plugin::REST_NAMESPACE . str_replace( '(?P<id>\d+)', (string) $product->get_id(), self::ROUTE ) ),
				'product_id' => $product->get_id(),
				'state'      => self::state( $product ),
			)
		);
	}

	/**
	 * Adds the bulk action to the products list.
	 *
	 * @param array<string, string> $actions The list's bulk actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		if ( ProductPictures::enabled( $this->settings ) ) {
			$actions += self::actions();
		}
		return $actions;
	}

	/**
	 * Adds Regenerate in background and Regenerate now to the row of a
	 * product that shows a picture. Each is the list's bulk action for that
	 * one product, so it works without the script, which runs Regenerate
	 * now in the page instead.
	 *
	 * @param mixed $actions The row's actions.
	 * @param mixed $post    The row's post.
	 */
	public function row_actions( mixed $actions, mixed $post ): mixed {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) || null === $this->pictured_product( $post->ID ) ) {
			return $actions;
		}
		foreach ( self::actions() as $action => $label ) {
			$url                = wp_nonce_url(
				add_query_arg(
					array(
						'post_type' => 'product',
						'action'    => $action,
						'post[]'    => $post->ID,
					),
					admin_url( 'edit.php' )
				),
				self::BULK_NONCE
			);
			$actions[ $action ] = sprintf( '<a href="%1$s" class="pixonaut-regenerate-row" data-action="%2$s" data-product-id="%3$d">%4$s</a>', esc_url( $url ), esc_attr( $action ), $post->ID, esc_html( $label ) );
		}
		return $actions;
	}

	/**
	 * The two ways to draw the pictures again, by bulk action.
	 *
	 * @return array<string, string>
	 */
	private static function actions(): array {
		return array(
			/* translators: Bulk and row action on the admin products list, and button in the product's Pixonaut tab: queues the product's Pixonaut pictures to be drawn again by background jobs. */
			self::BULK_ACTION          => __( 'Regenerate in background', 'pixonaut-for-woocommerce' ),
			/* translators: Bulk and row action on the admin products list, and button in the product's Pixonaut tab: draws the product's Pixonaut pictures again right away, while the page stays open. */
			RegenerateNow::BULK_ACTION => __( 'Regenerate now', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * Draws the selected products' pictures again; WordPress checked the
	 * list's nonce. Products showing no picture, that the user cannot edit,
	 * or with nothing to draw, as one keeping the store manager's own image
	 * without gallery pictures, are passed over.
	 *
	 * @param mixed $redirect_to Where the list goes back to.
	 * @param mixed $action      The bulk action.
	 * @param mixed $post_ids    The selected products.
	 */
	public function handle_bulk( mixed $redirect_to, mixed $action, mixed $post_ids ): mixed {
		// Regenerate now without the script, which runs it in the page, queues them as Regenerate in background does.
		if ( ! in_array( $action, array( self::BULK_ACTION, RegenerateNow::BULK_ACTION ), true ) || ! is_string( $redirect_to ) ) {
			return $redirect_to;
		}
		$products = array();
		foreach ( is_array( $post_ids ) ? $post_ids : array() as $post_id ) {
			$product = current_user_can( 'edit_post', (int) $post_id ) ? $this->pictured_product( (int) $post_id ) : null;
			if ( null !== $product ) {
				$products[] = $product;
			}
		}
		$queued = count( array_filter( $this->redraw( $products ), static fn ( array $standing ): bool => self::DRAWING === $standing['state'] ) );
		return add_query_arg( self::DONE_ARG, $queued, remove_query_arg( self::DONE_ARG, $redirect_to ) );
	}

	/**
	 * Says on the products list how many products the bulk action queued.
	 */
	public function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show, set by handle_bulk().
		if ( ! isset( $_GET[ self::DONE_ARG ] ) || 'edit-product' !== get_current_screen()?->id ) {
			return;
		}
		$queued = absint( wp_unslash( $_GET[ self::DONE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$text   = 0 === $queued
			? __( 'None of the selected products shows a Pixonaut picture, so no product image was regenerated.', 'pixonaut-for-woocommerce' )
			/* translators: %d: number of products. Admin products list, after the Regenerate product image bulk action. */
			: sprintf( _n( 'The product image of %d product is being regenerated.', 'The product images of %d products are being regenerated.', $queued, 'pixonaut-for-woocommerce' ), $queued );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 0 === $queued ? 'warning' : 'success' ), esc_html( $text ) );
	}

	/**
	 * DRAWING, FAILED, KEPT, NOT_QUEUED or DRAWN, for the product's pictures
	 * and its variations'.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public static function state( WC_Product $product ): string {
		if ( PictureQueue::drawing( $product ) ) {
			return self::DRAWING;
		}
		// The variations are read only once nothing is drawing, not on every poll before.
		foreach ( self::with_variations( $product ) as $each ) {
			if ( self::failed_now( $each ) ) {
				return self::FAILED;
			}
		}
		if ( PictureImage::is_own( $product ) ) {
			return self::KEPT;
		}
		return ProductPictures::basis( $product ) === ProductPictures::stored( $product )['basis'] ? self::DRAWN : self::NOT_QUEUED;
	}

	/**
	 * Moves the product's generation, which queues its drawing, and queues
	 * its variations' drawings at once rather than in a job of their own.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	private function queue_drawing( WC_Product $product ): void {
		ProductPictures::regenerate( $product );
		if ( $product->is_type( 'variable' ) && did_action( 'action_scheduler_init' ) ) {
			$this->queue->queue_variations_now( $product->get_id() );
		}
	}

	/**
	 * Starts the runners, so the drawings start without waiting for WP-Cron.
	 */
	private function start(): void {
		$runner = ( $this->runner )();
		if ( $runner instanceof PictureRunner ) {
			$runner->start();
		}
	}

	/**
	 * The route's answer: `{state}`, and when Pixonaut could not draw a
	 * picture, `{failed}` naming it.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	private function answer( WC_Product $product ): WP_REST_Response {
		return new WP_REST_Response( $this->standing( $product ) );
	}

	/**
	 * @param WC_Product $product A simple or variable product.
	 * @return array{state: string, failed?: string}
	 */
	private function standing( WC_Product $product ): array {
		$state  = self::state( $product );
		$answer = array( 'state' => $state );
		if ( self::FAILED === $state ) {
			$answer['failed'] = self::failed( $product );
		}
		return $answer;
	}

	/**
	 * Says whose pictures Pixonaut could not draw: the product's own, or
	 * those of its variations.
	 *
	 * @param WC_Product $product A simple or variable product whose state is FAILED.
	 */
	private static function failed( WC_Product $product ): string {
		$names = array();
		foreach ( self::with_variations( $product ) as $each ) {
			if ( self::failed_now( $each ) ) {
				/* translators: %s: a product or variation name, quoted in a list of the pictures Pixonaut could not draw. */
				$names[] = sprintf( __( '“%s”', 'pixonaut-for-woocommerce' ), $each->get_name() );
			}
		}
		return sprintf(
			/* translators: %s: a list of product or variation names, e.g. “Tee - Black, M” and “Tee - White, S”. Shown beside the Regenerate product image button. */
			__( 'Pixonaut could not draw the pictures of %s. They keep the images they have, and are tried again later.', 'pixonaut-for-woocommerce' ),
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * Whether Pixonaut could not draw the pictures of the product or
	 * variation as it is now.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private static function failed_now( WC_Product $product ): bool {
		$stored = ProductPictures::stored( $product );
		return null !== $stored['failed_at'] && ProductPictures::basis( $product ) === $stored['basis'];
	}

	/**
	 * The product and its variations.
	 *
	 * @param WC_Product $product A simple or variable product.
	 *
	 * @return list<WC_Product>
	 */
	private static function with_variations( WC_Product $product ): array {
		$family = array( $product );
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof WC_Product ) {
				$family[] = $child;
			}
		}
		return $family;
	}

	/**
	 * The product, when it shows a Pixonaut picture.
	 *
	 * @param int $product_id A post.
	 */
	public function pictured_product( int $product_id ): ?WC_Product {
		$product = wc_get_product( $product_id );
		return $product instanceof WC_Product && $this->pictured( $product ) ? $product : null;
	}

	/**
	 * @param WC_Product $product A product.
	 */
	private function pictured( WC_Product $product ): bool {
		return $product->is_type( self::PRODUCT_TYPES ) && ProductPictures::applies( $this->settings, $product );
	}

	private static function not_pictured(): WP_Error {
		return new WP_Error( 'pixonaut_wc_not_pictured', __( 'This product shows no Pixonaut picture.', 'pixonaut-for-woocommerce' ), array( 'status' => self::CONFLICT ) );
	}
}
