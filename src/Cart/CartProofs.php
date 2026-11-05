<?php
/**
 * Shows a personalised cart line's proofs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;

/**
 * The first side's design, on its blank as `LinePicture` chooses (its flat
 * proof for a product on no blank), replaces the product image in the
 * classic cart and mini-cart (`woocommerce_cart_item_thumbnail`) and in the
 * Store API (`woocommerce_store_api_cart_item_images`, which feeds the Cart
 * block, Mini-Cart and Checkout block). When the `show_field_values` setting is on, the line's
 * details list the values the customer chose for each side's design fields
 * (`woocommerce_get_item_data`, which reaches both); off, the line shows
 * only its name. The sides themselves get no rows, as the line's image
 * shows their proofs and its product link edits them (`CartEdit`). Every image URL is
 * the plugin's proof route; none is Pixonaut's. A side whose design's
 * personal data was erased has no proof to show: the line keeps the
 * product's image, and its details say the personalisation was removed.
 */
final class CartProofs {

	/**
	 * `woocommerce_thumbnail`'s width when WooCommerce reports none.
	 */
	private const DEFAULT_THUMBNAIL_WIDTH = 300;

	/**
	 * The Store API keeps an image only when it has an `id`; a proof is no
	 * attachment, so it has none of its own.
	 */
	private const NO_ATTACHMENT = 0;

	/**
	 * @param ProofUrls   $urls      Mints the proof URLs.
	 * @param Templates   $templates Renders the markup.
	 * @param Labels      $labels    Customer-facing text.
	 * @param Settings    $settings  Whether the fields are listed.
	 * @param LinePicture $picture   Chooses the picture a side shows.
	 */
	public function __construct(
		private readonly ProofUrls $urls,
		private readonly Templates $templates,
		private readonly Labels $labels,
		private readonly Settings $settings,
		private readonly LinePicture $picture,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( $this, 'store_api_images' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
	}

	/**
	 * @param mixed $thumbnail The product image's markup.
	 * @param mixed $cart_item The cart item.
	 */
	public function thumbnail( mixed $thumbnail, mixed $cart_item ): mixed {
		if ( ! is_array( $cart_item ) ) {
			return $thumbnail;
		}
		// The first side, in the product's order, stands for the line.
		$side     = CartLine::of( $cart_item )?->sides[0] ?? null;
		$proof_id = null === $side ? null : self::shown_proof( $side );
		if ( null === $side || null === $proof_id ) {
			return $thumbnail;
		}
		$width   = $this->width( $cart_item );
		$picture = $this->picture->of( self::product( $cart_item ), $side->key );
		return $this->templates->html(
			'cart/proof-thumbnail.php',
			array(
				'src'    => $this->urls->url( $side->design_id, $proof_id, $width, $picture ),
				'srcset' => $this->srcset( $side->design_id, $proof_id, $picture ),
				'width'  => $width,
				'alt'    => $this->labels->get( Labels::PROOF_ALT, self::context( $cart_item ) ),
			)
		);
	}

	/**
	 * One image per side that has a proof, the first shown, in place of the
	 * product's.
	 *
	 * @param mixed $images    The product's image objects.
	 * @param mixed $cart_item The cart item.
	 */
	public function store_api_images( mixed $images, mixed $cart_item ): mixed {
		if ( ! is_array( $cart_item ) ) {
			return $images;
		}
		$line = CartLine::of( $cart_item );
		if ( null === $line ) {
			return $images;
		}
		$width   = $this->width( $cart_item );
		$alt     = $this->labels->get( Labels::PROOF_ALT, self::context( $cart_item ) );
		$product = self::product( $cart_item );
		$proofs  = array();
		foreach ( $line->sides as $side ) {
			$proof_id = self::shown_proof( $side );
			if ( null === $proof_id ) {
				continue;
			}
			$picture  = $this->picture->of( $product, $side->key );
			$proofs[] = (object) array(
				'id'        => self::NO_ATTACHMENT,
				'src'       => $this->urls->url( $side->design_id, $proof_id, ProofUrls::LARGEST_WIDTH, $picture ),
				'thumbnail' => $this->urls->url( $side->design_id, $proof_id, $width, $picture ),
				'srcset'    => $this->srcset( $side->design_id, $proof_id, $picture ),
				'sizes'     => $width . 'px',
				'name'      => null === $product ? '' : $product->get_name(),
				'alt'       => $alt,
			);
		}
		return array() === $proofs ? $images : $proofs;
	}

	/**
	 * One row per field of each side's design, the field's label and the
	 * value the customer chose, while the setting lists them; a side whose
	 * design's personal data was erased shows one row by its name saying so
	 * instead. A row the same as one before it is left out: the Cart block
	 * keys each row by its key and value.
	 *
	 * @param mixed $item_data Details other code added.
	 * @param mixed $cart_item The cart item.
	 *
	 * @return mixed
	 */
	public function item_data( mixed $item_data, mixed $cart_item ): mixed {
		if ( ! is_array( $item_data ) || ! is_array( $cart_item ) ) {
			return $item_data;
		}
		$line = CartLine::of( $cart_item );
		if ( null === $line ) {
			return $item_data;
		}
		$names   = self::side_names( $cart_item );
		$context = self::context( $cart_item );
		$listed  = true === $this->settings->get( Settings::SHOW_FIELD_VALUES );
		$rows    = array();
		foreach ( $line->sides as $side ) {
			if ( ProofCache::is_erased( $side->design_id ) ) {
				$value  = $this->labels->get( Labels::PERSONALISATION_REMOVED, $context );
				$rows[] = array(
					'key'     => $names[ $side->key ] ?? $side->key,
					'value'   => $value,
					'display' => esc_html( $value ),
				);
				continue;
			}
			foreach ( $listed ? $side->fields : array() as $field ) {
				$rows[] = array(
					'key'     => $field->shown_label( $this->labels, $context ),
					'value'   => $field->shown_value( $this->labels, $context ),
					'display' => $field->shown_html( $this->labels, $context ),
				);
			}
		}

		/**
		 * Filters the rows a personalised cart item shows under its name in
		 * the cart, the checkout and the mini cart: while the
		 * `show_field_values` setting is on, one per field of each side's
		 * design, its label as `key` and the customer's value as `value` and
		 * `display` (a picture's is the `field_image` label); and one by the
		 * side's name for a side whose personalisation was removed. As
		 * `woocommerce_get_item_data` takes them; a row the same as one
		 * before it is left out after the filter.
		 *
		 * @since 0.1.0
		 *
		 * @param list<array{key: string, value: string, display: string}> $rows      The rows; `display` is escaped HTML.
		 * @param array<string, mixed>                                      $cart_item The cart item.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_cart_item_display', function ( $rows ) {
		 *     return array_map( function ( $row ) {
		 *         $row['key'] = sprintf( __( 'Design: %s', 'my-theme' ), $row['key'] );
		 *         return $row;
		 *     }, $rows );
		 * } );
		 */
		return self::distinct( array_merge( $item_data, (array) apply_filters( 'pixonaut_wc_cart_item_display', $rows, $cart_item ) ) );
	}

	/**
	 * The rows without one whose key repeats an earlier row's with the same
	 * value or display, so two fields with the same label and value show once.
	 *
	 * @param array<int|string, mixed> $rows The item's rows.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function distinct( array $rows ): array {
		$seen = array();
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = $row['key'] ?? $row['name'] ?? null;
			$ids = array();
			foreach ( array( 'value', 'display' ) as $part ) {
				// A row may carry only a value or only a display.
				if ( isset( $row[ $part ] ) ) {
					$ids[] = (string) wp_json_encode( array( $key, $part, $row[ $part ] ) );
				}
			}
			if ( array() !== array_intersect_key( $seen, array_flip( $ids ) ) ) {
				unset( $rows[ $index ] );
				continue;
			}
			$seen += array_fill_keys( $ids, true );
		}
		return array_values( $rows );
	}

	/**
	 * The proof the side shows; null when none is kept, or its design was erased.
	 *
	 * @param CommittedSide $side The side.
	 */
	private static function shown_proof( CommittedSide $side ): ?string {
		return ProofCache::is_erased( $side->design_id ) ? null : $side->proof_id;
	}

	/**
	 * The thumbnail's width, before it is rounded up to a served width.
	 *
	 * @param array<string, mixed> $cart_item The cart item.
	 */
	private function width( array $cart_item ): int {
		$size  = wc_get_image_size( 'woocommerce_thumbnail' );
		$width = is_numeric( $size['width'] ?? null ) && (int) $size['width'] > 0 ? (int) $size['width'] : self::DEFAULT_THUMBNAIL_WIDTH;

		/**
		 * Filters the width, in pixels, a cart line's proof is drawn at. The
		 * image is served at the next width up from `ProofUrls::WIDTHS`.
		 *
		 * @since 0.1.0
		 *
		 * @param int                  $width     `woocommerce_thumbnail`'s width.
		 * @param array<string, mixed> $cart_item The cart item.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_cart_proof_width', function () {
		 *     return 120;
		 * } );
		 */
		return ProofUrls::served_width( (int) apply_filters( 'pixonaut_wc_cart_proof_width', $width, $cart_item ) );
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

	/**
	 * @param array<string, mixed> $cart_item The cart item.
	 */
	private static function product( array $cart_item ): ?WC_Product {
		$product = $cart_item['data'] ?? null;
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * The sides' names as the product shows them, by key.
	 *
	 * @param array<string, mixed> $cart_item The cart item.
	 *
	 * @return array<string, string>
	 */
	private static function side_names( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		$names = array();
		foreach ( ProductConfig::config_for( $product )->sides as $side ) {
			$names[ $side->key ] = $side->label;
		}
		return $names;
	}

	/**
	 * @param array<string, mixed> $cart_item The cart item.
	 *
	 * @return array<string, mixed>
	 */
	private static function context( array $cart_item ): array {
		$product = $cart_item['data'] ?? null;
		return $product instanceof WC_Product ? array( 'product_id' => $product->get_id() ) : array();
	}
}
