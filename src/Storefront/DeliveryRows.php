<?php
/**
 * The chosen variation's delivery text and delivery time, in the product's
 * attributes table.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;
use WC_Product_Variable;
use WP_Block;

/**
 * Two rows of the table a product page lists its attributes in, after them:
 *
 * - "Delivery": on a variable product the inline designer shows on, the
 *   chosen variation's description, which its add-to-cart form then leaves
 *   out (`storefront.css`), so the form holds the designer and the buy row
 *   alone.
 * - "Delivery time": while the store shows delivery times, how long the
 *   product, or the chosen variation, takes as its printshop says
 *   (`DeliveryTime`).
 *
 * On a variable product each row holds every variation's text in a place
 * that keeps the height of the longest and shows the chosen one's
 * (`VariationSlots`), so a switch moves none of the table's rows; a row no
 * variation has a text for is left out.
 *
 * The rows go into WooCommerce's attributes table (`Additional
 * information`), and into the Product Specifications block, which prints
 * its own table without that table's filter.
 */
final class DeliveryRows {

	/** The row of the variation's description. */
	public const DELIVERY_KEY = 'pixonaut_delivery';

	/** The row of the delivery time. */
	public const DELIVERY_TIME_KEY = 'pixonaut_delivery_time';

	/** The place of the variation's description in its row. */
	public const DELIVERY_CLASS = 'pixonaut-variation-description';

	/** The place of the delivery time in its row. */
	public const DELIVERY_TIME_CLASS = 'pixonaut-delivery-time';

	/** Where the Product Specifications block's rows end. */
	private const SPECIFICATIONS_BODY_END = '</tbody>';

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 * @param DeliveryTime   $times    What is said of a delivery time.
	 * @param VariationSlots $slots    The place that keeps its height.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly DeliveryTime $times,
		private readonly VariationSlots $slots,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_display_product_attributes', array( $this, 'add_rows' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-specifications', array( $this, 'add_specifications_rows' ), 10, 3 );
		add_filter( 'woocommerce_available_variation', array( $this, 'leave_out_description' ), 10, 2 );
	}

	/**
	 * Leaves the description out of what the add-to-cart form of a variable
	 * product the designer shows on is told of each variation, so the form
	 * never draws it: it is the table's "Delivery" row.
	 *
	 * @param mixed $data    What the form is told of the variation.
	 * @param mixed $product The variable product.
	 * @return mixed
	 */
	public function leave_out_description( mixed $data, mixed $product ): mixed {
		if ( ! is_array( $data ) || ! $product instanceof WC_Product_Variable || ! $this->has_designer( $product ) ) {
			return $data;
		}
		$data['variation_description'] = '';
		return $data;
	}

	/**
	 * Adds the rows to WooCommerce's attributes table.
	 *
	 * @param mixed $rows    The table's rows by key, each a `label` and a `value`, HTML.
	 * @param mixed $product The product the table is of.
	 * @return mixed
	 */
	public function add_rows( mixed $rows, mixed $product ): mixed {
		if ( ! is_array( $rows ) || ! $product instanceof WC_Product ) {
			return $rows;
		}
		return array_merge( $rows, $this->rows( $product ) );
	}

	/**
	 * Adds the rows to the Product Specifications block's table, in its own
	 * markup. A block that prints no table, for a product with nothing to
	 * list, is left as it is.
	 *
	 * @param mixed $content  The block's HTML.
	 * @param mixed $block    The parsed block.
	 * @param mixed $instance The block, with the product in its context.
	 * @return mixed
	 */
	public function add_specifications_rows( mixed $content, mixed $block, mixed $instance = null ): mixed {
		unset( $block );
		$at = is_string( $content ) ? strrpos( $content, self::SPECIFICATIONS_BODY_END ) : false;
		if ( false === $at || ! $instance instanceof WP_Block || ! is_numeric( $instance->context['postId'] ?? null ) ) {
			return $content;
		}
		$product = wc_get_product( (int) $instance->context['postId'] );
		if ( ! $product instanceof WC_Product ) {
			return $content;
		}
		$html = '';
		foreach ( $this->rows( $product ) as $key => $row ) {
			$html .= sprintf(
				'<tr class="wp-block-product-specifications-item wp-block-product-specifications-item-%1$s"><th scope="row" class="wp-block-product-specifications-item__label">%2$s</th><td class="wp-block-product-specifications-item__value">%3$s</td></tr>',
				esc_attr( $key ),
				esc_html( $row['label'] ),
				wp_kses_post( $row['value'] )
			);
		}
		return '' === $html ? $content : substr_replace( (string) $content, $html, $at, 0 );
	}

	/**
	 * The rows of a product that has a text for them.
	 *
	 * @param WC_Product $product The product the table is of.
	 * @return array<string, array{label: string, value: string}>
	 */
	public function rows( WC_Product $product ): array {
		$rows = array();
		if ( $product instanceof WC_Product_Variable && $this->has_designer( $product ) ) {
			$texts = array();
			foreach ( VariationSlots::variations( $product ) as $variation ) {
				// A line of the table, as the table's other values are: its own breaks kept, in no paragraph a theme spaces.
				$texts[ $variation->get_id() ] = nl2br( wptexturize( wp_kses_post( trim( $variation->get_description() ) ) ) );
			}
			$rows[ self::DELIVERY_KEY ] = array(
				/* translators: Label of a row of the product's attributes table: how the product is delivered, as the chosen variation describes it, e.g. "as a poster, without a frame". */
				'label' => __( 'Delivery', 'pixonaut-for-woocommerce' ),
				'value' => $this->slots->varying( $product->get_id(), self::DELIVERY_CLASS, 'div', $texts ),
			);
		}
		if ( $this->times->shown() ) {
			if ( $product instanceof WC_Product_Variable ) {
				$lines = array();
				foreach ( VariationSlots::variations( $product ) as $variation ) {
					$lines[ $variation->get_id() ] = esc_html( $this->times->line_of( $variation ) );
				}
				$value = $this->slots->varying( $product->get_id(), self::DELIVERY_TIME_CLASS, 'div', $lines );
			} else {
				$value = $this->slots->fixed( self::DELIVERY_TIME_CLASS, 'div', esc_html( $this->times->line_of( $product ) ) );
			}
			$rows[ self::DELIVERY_TIME_KEY ] = array(
				/* translators: Label of a row of the product's attributes table: how long until the customer has the order, e.g. "5–8 days". */
				'label' => __( 'Delivery time', 'pixonaut-for-woocommerce' ),
				'value' => $value,
			);
		}
		return array_filter( $rows, static fn ( array $row ): bool => '' !== $row['value'] );
	}

	/**
	 * Whether the inline designer shows on a variable product, which
	 * `InlineProducts` builds once a request.
	 *
	 * @param WC_Product_Variable $product The variable product.
	 */
	private function has_designer( WC_Product_Variable $product ): bool {
		return null !== $this->products->for_product( $product );
	}
}
