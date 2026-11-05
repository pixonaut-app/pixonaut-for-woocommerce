<?php
/**
 * Erases the personal data Pixonaut holds for a person's orders.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Privacy;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use WC_Order;

/**
 * Tools > Erase Personal Data, for each personalised order line of the person:
 *
 * - A line never paid for: its designs are erased, whatever the store keeps.
 * - A paid line, while the store keeps order data on erasure requests
 *   (WooCommerce's "Remove personal data from orders on request" is off):
 *   retained, as WooCommerce retains the order itself.
 * - A paid line otherwise: erased once it has shipped, failed or been
 *   cancelled, by `LineErasure`; until then retained, since it is needed to
 *   fulfil the order.
 *
 * WooCommerce removing an order's personal data, on an erasure request or by
 * its personal data retention ("Retain completed orders"), erases the order's
 * lines at Pixonaut the same way, before WooCommerce anonymises the order.
 */
final class PersonalDataEraser {

	public const ID = 'pixonaut-for-woocommerce';

	/**
	 * WooCommerce's option: whether an erasure request removes order data.
	 */
	public const REMOVE_ORDER_DATA_OPTION = 'woocommerce_erasure_request_removes_order_data';

	/**
	 * Before WooCommerce's erasers (priority 10), which anonymise the order's
	 * email and customer: after them, the person's orders are no longer found.
	 */
	public const ERASER_PRIORITY = 5;

	/**
	 * WooCommerce's action before it removes an order's personal data, while
	 * the order still holds it.
	 */
	public const BEFORE_REMOVE_ORDER_DATA_ACTION = 'woocommerce_privacy_before_remove_order_personal_data';

	/**
	 * WooCommerce's action once it removed an order's personal data, order
	 * notes included.
	 */
	public const REMOVED_ORDER_DATA_ACTION = 'woocommerce_privacy_remove_order_personal_data';

	/**
	 * The notes `erase_order()` added, by order id, to add back once
	 * WooCommerce has deleted the order's notes.
	 *
	 * @var array<int, list<string>>
	 */
	private array $notes = array();

	/**
	 * @param LineErasure $erasure Erases a line, now or once it is finished.
	 */
	public function __construct( private readonly LineErasure $erasure ) {
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add' ), self::ERASER_PRIORITY );
		add_action( self::BEFORE_REMOVE_ORDER_DATA_ACTION, array( $this, 'erase_order' ) );
		add_action( self::REMOVED_ORDER_DATA_ACTION, array( $this, 'restore_notes' ) );
	}

	/**
	 * Erases the lines of an order whose personal data WooCommerce removes.
	 * The store no longer keeps the order's data, so a paid line is erased
	 * too, once it is finished. A line already asked to be erased, by the
	 * eraser running earlier in the same request, is left to that request.
	 *
	 * @param mixed $order The order WooCommerce anonymises.
	 */
	public function erase_order( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$before = self::notes_of( $order );
		foreach ( CustomerOrders::lines( $order ) as $line ) {
			if ( LineErasure::is_erased( $line ) || is_array( $line->ref( LineState::REF_ERASE ) ) ) {
				continue;
			}
			$this->erasure->request( $line );
		}
		$added = array_diff_key( self::notes_of( $order ), $before );
		if ( array() !== $added ) {
			$this->notes[ $order->get_id() ] = array_values( $added );
		}
	}

	/**
	 * Adds back the notes `erase_order()` added, which WooCommerce deleted
	 * with the order's other notes: what was erased, and why a line was not
	 * (the store is not connected to its workspace, Pixonaut refused), which
	 * the order no longer lets the merchant find through its customer.
	 *
	 * @param mixed $order The order WooCommerce anonymised.
	 */
	public function restore_notes( mixed $order ): void {
		if ( ! $order instanceof WC_Order || ! isset( $this->notes[ $order->get_id() ] ) ) {
			return;
		}
		foreach ( array_reverse( $this->notes[ $order->get_id() ] ) as $note ) {
			$order->add_order_note( $note );
		}
		unset( $this->notes[ $order->get_id() ] );
	}

	/**
	 * @param WC_Order $order The order.
	 *
	 * @return array<int, string> Its notes' contents by id, newest first.
	 */
	private static function notes_of( WC_Order $order ): array {
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$notes[ (int) $note->id ] = (string) $note->content;
		}
		return $notes;
	}

	/**
	 * @param mixed $erasers The registered erasers.
	 *
	 * @return array<string, mixed>
	 */
	public function add( mixed $erasers ): array {
		$erasers             = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			/* translators: Name of this eraser in WordPress Tools > Erase Personal Data, which the merchant reads. */
			'eraser_friendly_name' => __( 'Personalised designs', 'pixonaut-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email The person's email address.
	 * @param int    $page  The page, from 1.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$orders      = CustomerOrders::page( $email, $page );
		$keeps_order = 'yes' !== get_option( self::REMOVE_ORDER_DATA_OPTION, 'no' );
		$removed     = false;
		$retained    = false;
		$messages    = array();
		foreach ( $orders as $order ) {
			foreach ( CustomerOrders::lines( $order ) as $line ) {
				if ( LineErasure::is_erased( $line ) ) {
					continue;
				}
				$outcome = $keeps_order && LineErasure::is_paid( $line ) ? null : $this->erasure->request( $line );
				if ( LineErasure::ERASED === $outcome ) {
					$removed = true;
					continue;
				}
				$message = self::message( $outcome, $order );
				if ( null !== $message ) {
					$retained             = true;
					$messages[ $message ] = true;
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_keys( $messages ),
			'done'           => CustomerOrders::is_last( $orders ),
		);
	}

	/**
	 * What the request page says about a line that was not erased.
	 *
	 * @param string|null $outcome What `LineErasure::request()` came to; null for a line the store keeps with its order.
	 * @param WC_Order    $order   The line's order.
	 */
	private static function message( ?string $outcome, WC_Order $order ): ?string {
		$number = $order->get_order_number();
		return match ( $outcome ) {
			/* translators: %s: order number. */
			null                     => sprintf( __( 'Personalised designs in order %s were retained with the order.', 'pixonaut-for-woocommerce' ), $number ),
			/* translators: %s: order number. */
			LineErasure::WAITING     => sprintf( __( 'Personalised designs in order %s were retained: they are needed to fulfil the order. They are erased once the order has shipped or been cancelled.', 'pixonaut-for-woocommerce' ), $number ),
			/* translators: %s: order number. */
			LineErasure::PENDING     => sprintf( __( 'Personalised designs in order %s could not be erased yet. The store tries again shortly.', 'pixonaut-for-woocommerce' ), $number ),
			/* translators: %s: order number. */
			LineErasure::NOT_ERASED  => sprintf( __( 'Personalised designs in order %s could not be erased. The order notes say why.', 'pixonaut-for-woocommerce' ), $number ),
			default                  => null,
		};
	}
}
