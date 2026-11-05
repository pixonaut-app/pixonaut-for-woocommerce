<?php
/**
 * Shows a personalised order line's picture, and its proof pages while they wait.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Cart\LinePicture;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Templates;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * A personalised order line shows its first side's design on the product,
 * as `LinePicture` chooses, from the plugin's proof route: under the line's
 * name on the order received page and in My Account, which show no product
 * images (`woocommerce_order_item_meta_start`, which both the classic
 * templates and the Order Confirmation block fire), and in place of the
 * product image in order emails (`woocommerce_order_item_thumbnail`), whose
 * order table shows images while it lists a personalised line. A
 * fulfilment email's items table is handled as an order table: its lines
 * fire the same two hooks. A line whose
 * design's personal data was erased shows no picture, and an email keeps
 * the product's image for it.
 *
 * On the order pages, a line waiting for the customer's approval also links
 * each proof page still waiting (`order/proof-links.php`): Pixonaut's hosted
 * page, in the workspace's brand and on its domain, where the customer
 * approves the design, never the proof's bare image. An email links them in
 * its own words instead.
 */
final class LinePictures {

	/**
	 * The width, in pixels, the picture is shown at on the order pages.
	 */
	public const PAGE_WIDTH = 120;

	/**
	 * The width of an email's images when its arguments name none: WooCommerce's own.
	 */
	private const EMAIL_WIDTH = 48;

	/**
	 * The arguments `woocommerce_order_item_meta_start` passes that are read.
	 */
	private const META_START_ARGS = 4;

	/**
	 * The width an email's order table shows images at, while it prints;
	 * null outside one, whose lines also fire `woocommerce_order_item_meta_start`.
	 *
	 * @var int|null
	 */
	private ?int $email_width = null;

	/**
	 * @param ProofUrls   $urls      Mints the picture URLs.
	 * @param LinePicture $picture   Chooses the picture a side shows.
	 * @param Templates   $templates Renders the markup.
	 * @param Labels      $labels    Customer-facing text.
	 */
	public function __construct(
		private readonly ProofUrls $urls,
		private readonly LinePicture $picture,
		private readonly Templates $templates,
		private readonly Labels $labels,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_order_item_meta_start', array( $this, 'page_picture' ), 10, self::META_START_ARGS );
		add_filter( 'woocommerce_email_order_items_args', array( $this, 'email_items' ) );
		add_filter( 'woocommerce_email_order_items_table', array( $this, 'email_items_printed' ) );
		add_filter( 'woocommerce_email_fulfillment_items_args', array( $this, 'email_items' ) );
		add_filter( 'woocommerce_get_email_fulfillment_items_table', array( $this, 'email_items_printed' ) );
		add_filter( 'woocommerce_order_item_thumbnail', array( $this, 'email_thumbnail' ), 10, 2 );
	}

	/**
	 * Prints the line's picture under its name on an order page.
	 *
	 * @param mixed $item_id    The line's id; unused.
	 * @param mixed $item       The line.
	 * @param mixed $order      The order; unused.
	 * @param mixed $plain_text Whether a plain-text email prints it.
	 */
	public function page_picture( mixed $item_id, mixed $item, mixed $order, mixed $plain_text = false ): void {
		unset( $item_id, $order );
		if ( null !== $this->email_width || true === $plain_text || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$html = $this->html( $item, self::PAGE_WIDTH );
		if ( null !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes it.
		}
		$this->proof_links( $item );
	}

	/**
	 * Links the line's proof pages while it waits for the customer.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 */
	private function proof_links( WC_Order_Item_Product $item ): void {
		if ( ! LineState::is_tracked( $item ) ) {
			return;
		}
		$line = LineState::of( $item );
		$urls = LineState::AWAITING_CUSTOMER === $line->state() ? $line->waiting_proof_pages() : array();
		if ( array() === $urls ) {
			return;
		}
		$this->templates->render(
			'order/proof-links.php',
			array(
				'proof_urls' => $urls,
				'link_text'  => $this->labels->get( Labels::REVIEW_PROOF, array( 'item_id' => $item->get_id() ) ),
			)
		);
	}

	/**
	 * Shows images in an HTML email's order or fulfilment items table that
	 * lists a personalised line, and notes their width while it prints.
	 *
	 * @param mixed $args The table's arguments.
	 *
	 * @return mixed
	 */
	public function email_items( mixed $args ): mixed {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$size              = $args['image_size'] ?? null;
		$this->email_width = is_array( $size ) && is_numeric( $size[0] ?? null ) && (int) $size[0] > 0 ? (int) $size[0] : self::EMAIL_WIDTH;
		$order             = $args['order'] ?? null;
		if ( true !== ( $args['plain_text'] ?? false ) && $order instanceof WC_Order && self::lists_personalised( $order ) ) {
			$args['show_image'] = true;
		}
		return $args;
	}

	/**
	 * @param mixed $html The email's order or fulfilment items table.
	 *
	 * @return mixed
	 */
	public function email_items_printed( mixed $html ): mixed {
		$this->email_width = null;
		return $html;
	}

	/**
	 * The line's picture in place of its product image in an email.
	 *
	 * @param mixed $image The product image's markup.
	 * @param mixed $item  The line.
	 *
	 * @return mixed
	 */
	public function email_thumbnail( mixed $image, mixed $item ): mixed {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $image;
		}
		return $this->html( $item, $this->email_width ?? self::EMAIL_WIDTH ) ?? $image;
	}

	/**
	 * The picture's markup; null for a line with no design to show.
	 *
	 * @param WC_Order_Item_Product $item  The line.
	 * @param int                   $width The width it is shown at.
	 */
	private function html( WC_Order_Item_Product $item, int $width ): ?string {
		$shown = self::shown_side( $item );
		if ( null === $shown ) {
			return null;
		}
		$product = $item->get_product();
		$picture = $this->picture->of( $product instanceof WC_Product ? $product : null, $shown['side'] );
		return $this->templates->html(
			'order/line-picture.php',
			array(
				'src'    => $this->urls->url( $shown['design_id'], $shown['proof_id'], $width, $picture ),
				'srcset' => $this->srcset( $shown['design_id'], $shown['proof_id'], $picture ),
				'width'  => $width,
				'alt'    => $this->labels->get( Labels::PROOF_ALT, array( 'item_id' => $item->get_id() ) ),
			)
		);
	}

	/**
	 * The first side, in the order its designs are sent, that has a proof to
	 * show; null when none has.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 *
	 * @return array{side: string, design_id: string, proof_id: string}|null
	 */
	private static function shown_side( WC_Order_Item_Product $item ): ?array {
		if ( ! LineState::is_tracked( $item ) ) {
			return null;
		}
		$line = LineState::of( $item );
		foreach ( $line->sides() as $side ) {
			$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			$proof_id  = $line->side_ref( $side, LineState::SIDE_PROOF_ID );
			if ( ! is_string( $design_id ) || ! is_string( $proof_id ) || $line->is_side_erased( $side ) || ProofCache::is_erased( $design_id ) ) {
				continue;
			}
			return array(
				'side'      => $side,
				'design_id' => $design_id,
				'proof_id'  => $proof_id,
			);
		}
		return null;
	}

	/**
	 * Whether any of the order's lines has a design to show.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function lists_personalised( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && null !== self::shown_side( $item ) ) {
				return true;
			}
		}
		return false;
	}

	private function srcset( string $design_id, string $proof_id, ProofPicture $picture ): string {
		return implode(
			', ',
			array_map(
				fn ( int $width ): string => $this->urls->url( $design_id, $proof_id, $width, $picture ) . ' ' . $width . 'w',
				ProofUrls::WIDTHS
			)
		);
	}
}
