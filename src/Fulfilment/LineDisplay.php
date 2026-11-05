<?php
/**
 * Shows a personalised line's state where WooCommerce shows its details.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use stdClass;
use WC_Order_Item;
use WC_Order_Item_Product;

/**
 * Adds a Pixonaut line's tracking, once shipped, to the line's displayed
 * meta, so it appears wherever WooCommerce lists an order's items: My
 * Account, the order received page and every order email, WooCommerce's
 * completed email included. The values the customer chose for each design's
 * fields come first while the `show_field_values` setting is on. On the
 * order screen in the admin the rows, the fields always among them and the
 * line's state after them, print in a table of their own under WooCommerce's:
 * its meta table is also the form that edits the line's meta, which would
 * save them as meta of the line. That table hides the meta the plugin keeps
 * for itself. The customer is not shown the line's state, which names a step
 * of the store's fulfilment. A line whose design's personal data was erased
 * says its personalisation was removed, and lists no values. Each text comes
 * from a label key.
 */
final class LineDisplay {

	/**
	 * Keys of the rows added, unlike any meta id WooCommerce uses.
	 */
	public const STATE_ROW    = 'pixonaut_line_state';
	public const TRACKING_ROW = 'pixonaut_tracking';
	public const REMOVED_ROW  = 'pixonaut_personalisation_removed';

	/**
	 * Starts the index of a field's row, followed by the side's key and the
	 * field's place among its fields: `pixonaut_field_<side>_<n>`.
	 */
	public const FIELD_ROW_PREFIX = 'pixonaut_field_';

	/**
	 * Whether the admin order screen is printing a line's meta, between
	 * `woocommerce_before_order_itemmeta` and `woocommerce_after_order_itemmeta`.
	 *
	 * @var bool
	 */
	private bool $in_admin_item = false;

	/**
	 * The arguments `woocommerce_after_order_itemmeta` passes that are read.
	 */
	private const ITEMMETA_ARGS = 2;

	/**
	 * The line's meta the plugin keeps for itself, which the admin order
	 * screen does not list.
	 */
	public const HIDDEN_META = array(
		LineState::META_KEY,
		LineState::ORDER_ID_META_KEY,
		LineState::DESIGN_ID_META_KEY,
	);

	/**
	 * @param Labels    $labels    The customer-facing texts.
	 * @param Settings  $settings  Whether the customer is shown the fields.
	 * @param Templates $templates Renders the admin's table.
	 */
	public function __construct(
		private readonly Labels $labels,
		private readonly Settings $settings,
		private readonly Templates $templates,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'add_rows' ), 10, 2 );
		add_action( 'woocommerce_before_order_itemmeta', array( $this, 'enter_admin_item' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_rows' ), 10, self::ITEMMETA_ARGS );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_meta' ) );
	}

	/**
	 * @param mixed $keys The meta keys the admin order screen does not list.
	 * @return array<int, string>
	 */
	public function hide_meta( mixed $keys ): array {
		return array_values( array_unique( array_merge( (array) $keys, self::HIDDEN_META ) ) );
	}

	public function enter_admin_item(): void {
		$this->in_admin_item = true;
	}

	/**
	 * Prints the line's rows, its fields among them, under WooCommerce's
	 * meta on the admin order screen.
	 *
	 * @param mixed $item_id The line's id.
	 * @param mixed $item    The line.
	 */
	public function admin_rows( mixed $item_id, mixed $item ): void {
		$this->in_admin_item = false;
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$rows = $this->rows( array(), $item, true, true );
		if ( array() !== $rows ) {
			$this->templates->render( 'admin/order-item-details.php', array( 'rows' => $rows ) );
		}
	}

	/**
	 * @param array<string|int, stdClass> $rows The line's displayed meta.
	 * @param WC_Order_Item               $item The line.
	 * @return array<string|int, stdClass>
	 */
	public function add_rows( array $rows, WC_Order_Item $item ): array {
		if ( $this->in_admin_item || ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return $rows;
		}
		return $this->rows( $rows, $item, true === $this->settings->get( Settings::SHOW_FIELD_VALUES ), false );
	}

	/**
	 * The line's displayed meta with the plugin's rows added.
	 *
	 * @param array<string|int, stdClass> $rows   The meta before them.
	 * @param WC_Order_Item_Product       $item   The line.
	 * @param bool                        $listed Whether the design's fields are among them.
	 * @param bool                        $admin  Whether the admin order screen shows them, with the line's state.
	 * @return array<string|int, stdClass>
	 */
	private function rows( array $rows, WC_Order_Item_Product $item, bool $listed, bool $admin ): array {
		$line    = LineState::of( $item );
		$context = array( 'item_id' => $item->get_id() );

		foreach ( $listed ? $line->sides() : array() as $side ) {
			if ( $line->is_side_erased( $side ) ) {
				continue;
			}
			foreach ( $line->side_fields( $side ) as $index => $field ) {
				$rows[ self::FIELD_ROW_PREFIX . $side . '_' . $index ] = self::row( $field->shown_label( $this->labels, $context ), $field->shown_value( $this->labels, $context ), $field->shown_html( $this->labels, $context ) );
			}
		}
		if ( $admin ) {
			$state                   = $this->labels->get( Labels::line_state_key( $line->state() ), $context );
			$rows[ self::STATE_ROW ] = self::row( $this->labels->get( Labels::LINE_STATE, $context ), $line->state(), esc_html( $state ) );
		}
		$tracking = LineState::SHIPPED === $line->state() ? Tracking::of( $line ) : null;
		if ( null !== $tracking ) {
			$text = esc_html( Tracking::text( $tracking ) );
			if ( isset( $tracking['url'] ) ) {
				$text .= ' ' . esc_url( $tracking['url'] );
			}
			$rows[ self::TRACKING_ROW ] = self::row( $this->labels->get( Labels::TRACKING, $context ), $tracking['number'], make_clickable( $text ) );
		}
		foreach ( $line->sides() as $side ) {
			if ( $line->is_side_erased( $side ) ) {
				$removed                   = $this->labels->get( Labels::PERSONALISATION_REMOVED, $context );
				$rows[ self::REMOVED_ROW ] = self::row( $this->labels->get( Labels::PERSONALISATION, $context ), $removed, esc_html( $removed ) );
				break;
			}
		}

		/**
		 * Filters a personalised order line's displayed meta, after the plugin
		 * added one row per field of each side's design (while the
		 * `show_field_values` setting is on, and always on the admin order
		 * screen, where the rows come alone and print under WooCommerce's
		 * meta), at index `pixonaut_field_<side>_<n>`, whose `display_key` is
		 * the field's label and `display_value` the customer's value (a
		 * picture's is the `field_image` label), then on the admin order screen
		 * the line's state, once shipped its tracking, and once its design's
		 * personal data was erased a note that its personalisation was removed
		 * (indexes `pixonaut_line_state`, `pixonaut_tracking` and
		 * `pixonaut_personalisation_removed`). WooCommerce shows these rows,
		 * all but the line's state, in My Account, on the order received page
		 * and in every order email.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string|int, stdClass> $rows The line's displayed meta: `key`, `value`, `display_key`, `display_value` (escaped HTML).
		 * @param WC_Order_Item_Product       $item The order line.
		 * @param LineState                   $line The line's state, refs and designs.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_line_display', function ( $rows ) {
		 *     unset( $rows['pixonaut_tracking'] );
		 *     return $rows;
		 * } );
		 */
		return (array) apply_filters( 'pixonaut_wc_fulfilment_line_display', $rows, $item, $line );
	}

	/**
	 * A row as WooCommerce's formatted meta holds it.
	 *
	 * @param string $label The label.
	 * @param string $value The raw value.
	 * @param string $html  The value as shown, escaped.
	 */
	private static function row( string $label, string $value, string $html ): stdClass {
		return (object) array(
			'key'           => $label,
			'value'         => $value,
			'display_key'   => esc_html( $label ),
			'display_value' => $html,
		);
	}
}
