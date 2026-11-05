<?php
/**
 * Tells the store about lines that need attention.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Emails\NeedsAttentionEmail;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Each time a line moves to `needs_attention`, the store gets an email about
 * it, and the order an admin notice; the notice stays while any of the
 * order's Pixonaut lines is in `needs_attention`, and goes when the last one
 * leaves.
 */
final class AttentionAlerts {

	/**
	 * @param Notices $notices The admin notices.
	 */
	public function __construct( private readonly Notices $notices ) {
	}

	public function register(): void {
		add_action( 'pixonaut_wc_fulfilment_transition', array( $this, 'on_transition' ), 10, 3 );
	}

	/**
	 * The notice's key for an order.
	 *
	 * @param int $order_id The order.
	 */
	public static function key( int $order_id ): string {
		return 'fulfilment_needs_attention_' . $order_id;
	}

	/**
	 * @param string                $to   The line's new state.
	 * @param string                $from Its previous state.
	 * @param WC_Order_Item_Product $item The line.
	 */
	public function on_transition( string $to, string $from, WC_Order_Item_Product $item ): void {
		if ( LineState::NEEDS_ATTENTION !== $to && LineState::NEEDS_ATTENTION !== $from ) {
			return;
		}
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( LineState::NEEDS_ATTENTION === $to ) {
			$this->notices->add(
				self::key( $order->get_id() ),
				sprintf(
					/* translators: %s: link to the order. */
					__( 'Pixonaut: a personalised item of order %s needs attention before it can go to the printshop. The order notes say why.', 'pixonaut-for-woocommerce' ),
					sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) )
				),
				'error',
				false
			);
			$this->email( $item );
			return;
		}
		if ( ! self::needs_attention( $order ) ) {
			$this->notices->remove( self::key( $order->get_id() ) );
		}
	}

	/**
	 * Sends the store's email about the line, at once: WooCommerce's
	 * deferred emails would carry the line through a JSON job.
	 *
	 * @param WC_Order_Item_Product $item A line in `needs_attention`.
	 */
	private function email( WC_Order_Item_Product $item ): void {
		$email = WC()->mailer()->get_emails()[ NeedsAttentionEmail::class ] ?? null;
		if ( $email instanceof NeedsAttentionEmail ) {
			$email->trigger( $item, LineState::of( $item )->reason() ?? '' );
		}
	}

	/**
	 * Whether any Pixonaut line of the order is in `needs_attention`.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function needs_attention( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) && LineState::NEEDS_ATTENTION === LineState::of( $item )->state() ) {
				return true;
			}
		}
		return false;
	}
}
