<?php
/**
 * Adjust layout: one option of a standing side laid out by hand.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Closure;
use Pixonaut\WooCommerce\Catalogue\LayoutAdjustment;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Catalogue\StandingRows;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsCorrectionLinksCreateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Product;

/**
 * On a blank whose print areas change with an option, each option of a
 * standing side is printed from its own standing design (`StandingRows`).
 * The product panel's **Adjust layout** under an option's preview opens that
 * design on Pixonaut's hosted correction page for its layout alone
 * (`adjustLayout`). Approving there sends the store manager back here with
 * the adjustment's proof, from which Pixonaut makes a standing design of its
 * own; the option is printed from it at once, and keeps it while the side
 * changes version or size (`LayoutAdjustment`). **Reset** puts the option
 * back on the side's design, at once too.
 *
 * Each trip gets a random `state`, kept for the user who started it as long
 * as the correction link works; the return takes it back once. Both ends
 * come back to the product's edit screen with a line saying how it went.
 */
final class LayoutAdjustments {

	public const ADJUST_ACTION = 'pixonaut_adjust_layout';

	public const RETURN_ACTION = 'pixonaut_adjusted_layout';

	public const RESET_ACTION = 'pixonaut_reset_layout';

	/**
	 * Query arguments naming the option: the product, its side and the
	 * option's design now.
	 */
	public const PRODUCT_ARG = 'product';

	public const SIDE_ARG = 'side';

	public const DESIGN_ARG = 'design';

	/**
	 * Parameters the hosted page adds to the return address.
	 */
	public const STATE_ARG = 'state';

	public const TOKEN_ARG = 'proofToken';

	/**
	 * Query arguments the edit screen is sent back with: how it went, and
	 * the option's values.
	 */
	public const DONE_ARG = 'pixonaut_layout';

	public const OPTION_ARG = 'pixonaut_layout_option';

	public const ADJUSTED = 'adjusted';

	public const RESET = 'reset';

	public const FAILED = 'failed';

	/**
	 * The edit screen the trip came from: nothing changed.
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * How long a trip stays open: as long as the correction link it went
	 * out with works, 2 hours.
	 */
	public const LIFETIME_SECONDS = 7200;

	/**
	 * Characters in a state.
	 */
	private const STATE_LENGTH = 32;

	private const TRIP_PREFIX = 'pixonaut_layout_trip_';

	/**
	 * @param Sdk                         $pixonaut Mints the correction link.
	 * @param StandingRows                $rows    Makes and resets the option's design.
	 * @param LoggerInterface             $logger  Logs what Pixonaut refused.
	 * @param Closure(string): never|null $go      `fn(string $location)` sends the browser on and ends the request; null to redirect and exit.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly StandingRows $rows,
		private readonly LoggerInterface $logger,
		private readonly ?Closure $go = null,
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ADJUST_ACTION, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::RETURN_ACTION, array( $this, 'finish' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'reset' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The address of an option's Adjust layout, or of its Reset.
	 *
	 * @param string $action    ADJUST_ACTION or RESET_ACTION.
	 * @param int    $product   The product.
	 * @param string $side      The side's key.
	 * @param string $design_id The option's design.
	 */
	public static function url( string $action, int $product, string $side, string $design_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'          => $action,
					self::PRODUCT_ARG => $product,
					self::SIDE_ARG    => rawurlencode( $side ),
					self::DESIGN_ARG  => rawurlencode( $design_id ),
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $action, $product )
		);
	}

	/**
	 * Opens an option's design for its layout on Pixonaut's hosted page.
	 */
	public function start(): void {
		$found = $this->requested( self::ADJUST_ACTION );
		if ( null === $found ) {
			$this->go( admin_url( 'edit.php?post_type=product' ) );
		}
		list( $product, $side, $row ) = $found;
		$basis                        = StandingRows::basis( $side, (string) ProductConfig::stored_on( $product )->blank_id, $row );
		$state                        = wp_generate_password( self::STATE_LENGTH, false );
		$url                          = null;
		if ( null !== $basis ) {
			try {
				$url = $this->pixonaut->designs()->createCorrectionLink(
					$row->design_id,
					new DesignsCorrectionLinksCreateRequest( adjustLayout: true, returnUrl: admin_url( 'admin-post.php?action=' . self::RETURN_ACTION ), state: $state )
				)->url;
			} catch ( ApiException $e ) {
				$this->refused( $e, 'open', $product, $side, $row );
			}
		}
		if ( null === $basis || null === $url ) {
			$this->go( self::back( $product, self::FAILED, $row ) );
		}
		set_transient(
			self::TRIP_PREFIX . $state,
			array(
				'user'    => get_current_user_id(),
				'product' => $product->get_id(),
				'side'    => $side->key,
				'design'  => $row->design_id,
				'basis'   => $basis->to_array(),
			),
			self::LIFETIME_SECONDS
		);
		// Pixonaut's hosted page is on another host, which wp_safe_redirect() refuses.
		$this->go( $url );
	}

	/**
	 * Takes the store manager back from the hosted page, and prints the
	 * option from the adjustment's own design when they approved one.
	 */
	public function finish(): void {
		// The state is the trip's secret, single-use and the user's own; the hosted page sends no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET[ self::STATE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::STATE_ARG ] ) ) : '';
		$token = isset( $_GET[ self::TOKEN_ARG ] ) && is_string( $_GET[ self::TOKEN_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$trip = self::take( $state );
		// The store manager may have lost the right to edit the product while on Pixonaut's page.
		if ( null === $trip || ! current_user_can( 'edit_post', $trip['product'] ) ) {
			$this->go( admin_url( 'edit.php?post_type=product' ) );
		}
		$product = wc_get_product( $trip['product'] );
		$found   = $product instanceof WC_Product ? self::find( $product, $trip['side'], $trip['design'] ) : null;
		if ( ! $product instanceof WC_Product || null === $found ) {
			$this->go( admin_url( 'edit.php?post_type=product' ) );
		}
		list( $side, $row ) = $found;
		if ( '' === $token ) {
			$this->go( self::back( $product, self::CANCELLED, $row ) );
		}
		try {
			$adjusted = $this->rows->adjust( $side, $row, $trip['basis'], $token );
		} catch ( ApiException $e ) {
			$this->refused( $e, 'save', $product, $side, $row );
			$this->go( self::back( $product, self::FAILED, $row ) );
		}
		$rows = array();
		foreach ( $side->standing_rows as $held ) {
			$rows[] = $held->design_id === $row->design_id ? $adjusted : $held;
		}
		$this->go( self::back( $product, $this->store( $product, $side, $rows ) ? self::ADJUSTED : self::FAILED, $row ) );
	}

	/**
	 * Puts an adjusted option back on the side's design.
	 */
	public function reset(): void {
		$found = $this->requested( self::RESET_ACTION );
		if ( null === $found ) {
			$this->go( admin_url( 'edit.php?post_type=product' ) );
		}
		list( $product, $side, $row ) = $found;
		$this->go( self::back( $product, $this->reset_option( $product, $side, $row ), $row ) );
	}

	/**
	 * Lays one option of a standing side out as the side again: Pixonaut
	 * finds the design the side's template gives it, which the side then
	 * prints the option from. An option not adjusted needs nothing.
	 *
	 * @param WC_Product  $product The product or variation.
	 * @param Side        $side    Its standing side.
	 * @param StandingRow $row     The option.
	 * @return string `RESET`, or `FAILED` when Pixonaut refused or the product changed meanwhile.
	 */
	public function reset_option( WC_Product $product, Side $side, StandingRow $row ): string {
		$blank_id = ProductConfig::stored_on( $product )->blank_id;
		if ( null === $row->adjusted || null === $side->template_version || null === $blank_id ) {
			return self::RESET;
		}
		try {
			$rows = $this->rows->reset( (string) new TemplateId( $side->template_base_id, $side->template_version ), $blank_id, $side, $row );
		} catch ( ApiException $e ) {
			$this->refused( $e, 'reset', $product, $side, $row );
			return self::FAILED;
		}
		return $this->store( $product, $side, $rows ) ? self::RESET : self::FAILED;
	}

	/**
	 * Says on the product's edit screen how an adjustment or a reset went.
	 */
	public function notice(): void {
		// Read only to choose a line of text.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$done   = isset( $_GET[ self::DONE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE_ARG ] ) ) : '';
		$option = isset( $_GET[ self::OPTION_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::OPTION_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$lines = array(
			/* translators: %s: an option's value, e.g. "XL" or "XL, Tall". Notice after the store manager adjusted a standing side's layout for that option on Pixonaut. Admin product page. */
			self::ADJUSTED => array( 'success', __( 'The layout of %s is saved. It keeps it when the side changes, until you reset it.', 'pixonaut-for-woocommerce' ) ),
			/* translators: %s: an option's value, e.g. "XL". Notice after the store manager reset a standing side's layout for that option. Admin product page. */
			self::RESET    => array( 'success', __( '%s follows the side again.', 'pixonaut-for-woocommerce' ) ),
			/* translators: %s: an option's value, e.g. "XL". Notice when Pixonaut could not open, save or reset a standing side's layout for that option. Admin product page. */
			self::FAILED   => array( 'error', __( 'The layout of %s could not be changed in Pixonaut. Try again in a moment.', 'pixonaut-for-woocommerce' ) ),
		);
		if ( ! isset( $lines[ $done ] ) || '' === $option ) {
			return;
		}
		list( $type, $line ) = $lines[ $done ];
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( sprintf( $line, $option ) ) );
	}

	/**
	 * The product, side and option a request from the panel names, once its
	 * nonce and the user's right to edit the product are checked.
	 *
	 * @param string $action The request's action.
	 * @return array{0: WC_Product, 1: Side, 2: StandingRow}|null
	 */
	private function requested( string $action ): ?array {
		$product_id = isset( $_GET[ self::PRODUCT_ARG ] ) ? absint( $_GET[ self::PRODUCT_ARG ] ) : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( $product_id < 1 || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $product_id ) ) || ! current_user_can( 'edit_post', $product_id ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$side   = isset( $_GET[ self::SIDE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SIDE_ARG ] ) ) : '';
		$design = isset( $_GET[ self::DESIGN_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::DESIGN_ARG ] ) ) : '';
		$found  = self::find( $product, $side, $design );
		return null === $found ? null : array( $product, $found[0], $found[1] );
	}

	/**
	 * A standing side of the product and its option printed from a design.
	 *
	 * @param WC_Product $product   The product.
	 * @param string     $side_key  The side's key.
	 * @param string     $design_id The option's design.
	 * @return array{0: Side, 1: StandingRow}|null
	 */
	public static function find( WC_Product $product, string $side_key, string $design_id ): ?array {
		foreach ( ProductConfig::stored_on( $product )->sides as $side ) {
			if ( $side->key !== $side_key || ! $side->is_standing() ) {
				continue;
			}
			foreach ( $side->standing_rows as $row ) {
				if ( $row->design_id === $design_id ) {
					return array( $side, $row );
				}
			}
		}
		return null;
	}

	/**
	 * Stores the side's rows, unless the product's configuration changed
	 * since the side was read.
	 *
	 * @param WC_Product    $product The product.
	 * @param Side          $side    The side as read.
	 * @param StandingRow[] $rows    Its rows now.
	 * @phpstan-param list<StandingRow> $rows
	 */
	private function store( WC_Product $product, Side $side, array $rows ): bool {
		$document = ProductConfig::document_on( $product );
		if ( null === $document ) {
			return false;
		}
		$config = ProductConfig::from_json( $document );
		$sides  = array();
		foreach ( $config->sides as $held ) {
			$sides[] = $held->key === $side->key ? $held->with_standing_rows( $rows ) : $held;
		}
		return $config->with_sides( $sides )->replace_on( $product, $document );
	}

	/**
	 * The trip a state names, taken back once, when the current user started it.
	 *
	 * @param string $state The state the hosted page carried back.
	 * @return array{product: int, side: string, design: string, basis: LayoutAdjustment}|null
	 */
	private static function take( string $state ): ?array {
		if ( '' === $state || 1 !== preg_match( '/^[A-Za-z0-9]+$/', $state ) ) {
			return null;
		}
		$trip = get_transient( self::TRIP_PREFIX . $state );
		delete_transient( self::TRIP_PREFIX . $state );
		if ( ! is_array( $trip ) || ( $trip['user'] ?? null ) !== get_current_user_id() || ! is_int( $trip['product'] ?? null ) || ! is_string( $trip['side'] ?? null ) || ! is_string( $trip['design'] ?? null ) ) {
			return null;
		}
		$basis = LayoutAdjustment::from_array( $trip['basis'] ?? null );
		return null === $basis ? null : array(
			'product' => $trip['product'],
			'side'    => $trip['side'],
			'design'  => $trip['design'],
			'basis'   => $basis,
		);
	}

	/**
	 * Logs what Pixonaut refused.
	 *
	 * @param ApiException $error   What Pixonaut answered.
	 * @param string       $doing   `open`, `save` or `reset`.
	 * @param WC_Product   $product The product.
	 * @param Side         $side    The side.
	 * @param StandingRow  $row     The option.
	 */
	private function refused( ApiException $error, string $doing, WC_Product $product, Side $side, StandingRow $row ): void {
		$this->logger->warning(
			sprintf( 'Pixonaut would not %s the layout of option "%s" of side "%s" of product %d: %s', $doing, $row->label(), $side->key, $product->get_id(), $error->getMessage() ),
			array(
				'product'   => $product->get_id(),
				'side'      => $side->key,
				'design_id' => $row->design_id,
				'status'    => $error->status,
				'code'      => $error->errorCode,
			)
		);
	}

	/**
	 * The product's edit screen, saying how it went.
	 *
	 * @param WC_Product  $product The product.
	 * @param string      $done    ADJUSTED, RESET, FAILED or CANCELLED.
	 * @param StandingRow $row     The option.
	 */
	private static function back( WC_Product $product, string $done, StandingRow $row ): string {
		$edit = add_query_arg(
			array(
				'post'   => $product->get_id(),
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);
		return self::CANCELLED === $done ? $edit : add_query_arg(
			array(
				self::DONE_ARG   => $done,
				self::OPTION_ARG => rawurlencode( $row->label() ),
			),
			$edit
		);
	}

	private static function nonce_action( string $action, int $product ): string {
		return $action . '_' . $product;
	}

	/**
	 * @param string $location Where the browser goes next.
	 */
	private function go( string $location ): never {
		if ( null !== $this->go ) {
			( $this->go )( $location );
		}
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}
}
