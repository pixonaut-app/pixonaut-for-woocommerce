<?php
/**
 * Why Pixonaut holds a line's order, in the merchant's words.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A free workspace's orders past its plan's monthly limit are taken and
 * held (`held`), never refused: Pixonaut sends them on to the printshop when
 * the workspace upgrades, or when the month resets and the new month has room
 * for them. A held line is ordered and waits; it is never a failure. A reason
 * this version does not know shows as held without one.
 */
final class OrderHold {

	/**
	 * The workspace has used its plan's orders for the month.
	 */
	public const MONTHLY_ORDER_LIMIT = 'monthly_order_limit';

	/**
	 * `releasedBy` of an order released because the workspace upgraded.
	 */
	public const RELEASED_BY_UPGRADE = 'upgrade';

	/**
	 * `releasedBy` of an order released when the month reset.
	 */
	public const RELEASED_BY_MONTH_RESET = 'month_reset';

	/**
	 * Tries to keep a hold when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * A hold as the line stores it, from an order's `hold`, the payload of
	 * `order.held` or the hold the line stored: the reason only when this
	 * version knows it, the month's end as a Unix time.
	 *
	 * @param mixed $hold `{reason, limit, periodEnd}`, or anything else when the order carries none.
	 *
	 * @return array{reason: string|null, limit: int|null, periodEnd: int|null}
	 */
	public static function of( mixed $hold ): array {
		$hold   = is_array( $hold ) ? $hold : array();
		$reason = $hold['reason'] ?? null;
		$end    = $hold['periodEnd'] ?? null;
		// Pixonaut sends ISO 8601; the line stores the Unix time it reads as.
		$end = is_string( $end ) ? strtotime( $end ) : ( is_int( $end ) ? $end : false );
		return array(
			'reason'    => self::MONTHLY_ORDER_LIMIT === $reason ? $reason : null,
			'limit'     => is_int( $hold['limit'] ?? null ) ? $hold['limit'] : null,
			'periodEnd' => false === $end ? null : $end,
		);
	}

	/**
	 * Stores the hold on a line whose order is held, unless the line has one
	 * already or its order was released: each hold is noted once, and never
	 * after the release. Saved with the line's save().
	 *
	 * @param LineState $line A line whose order is held.
	 * @param mixed     $hold The order's `hold`, or the payload of `order.held`.
	 *
	 * @return bool Whether it was stored, so the caller notes it once the line is saved.
	 */
	public static function store( LineState $line, mixed $hold ): bool {
		if ( null !== $line->ref( LineState::REF_HOLD ) || null !== $line->ref( LineState::REF_RELEASED_BY ) ) {
			return false;
		}
		$line->set_ref( LineState::REF_HOLD, self::of( $hold ) );
		return true;
	}

	/**
	 * Notes on the line's order that it is held, why and when it goes ahead.
	 *
	 * @param LineState $line A line that stored its hold.
	 */
	public static function note( LineState $line ): void {
		$order = wc_get_order( $line->item()->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( self::held_note( $line->item(), $line->ref( LineState::REF_HOLD ) ) );
		}
	}

	/**
	 * Keeps the hold of an order that a read of it, or the answer that handed
	 * the line to it, says is held. `order.status_changed` carries no hold, so
	 * a line those moved to `held` stores it here, with its note.
	 *
	 * @param WC_Order_Item_Product $item     The line.
	 * @param string                $order_id The held order; a line holding another order since is left alone.
	 * @param mixed                 $hold     The order's `hold`.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	public static function keep( WC_Order_Item_Product $item, string $order_id, mixed $hold ): void {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( $line->ref( LineState::REF_ORDER_ID ) !== $order_id || OrderStatus::HELD !== $line->ref( LineState::REF_ORDER_STATUS ) || ! self::store( $line, $hold ) ) {
				return;
			}
			if ( $line->save() ) {
				self::note( $line );
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order hold not kept on order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Why the line's order is held and when it goes ahead, for the order
	 * screen.
	 *
	 * @param LineState $line A line whose order is held.
	 */
	public static function explain( LineState $line ): string {
		$hold = self::of( $line->ref( LineState::REF_HOLD ) );
		if ( self::MONTHLY_ORDER_LIMIT !== $hold['reason'] ) {
			return __( 'Pixonaut has taken the order and holds it. It has not been sent to the printshop yet.', 'pixonaut-for-woocommerce' );
		}
		return self::monthly_limit( $hold );
	}

	/**
	 * The order note for a line whose order Pixonaut holds.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 * @param mixed                 $hold The line's stored hold.
	 */
	public static function held_note( WC_Order_Item_Product $item, mixed $hold ): string {
		$hold = self::of( $hold );
		$why  = self::MONTHLY_ORDER_LIMIT === $hold['reason']
			? self::monthly_limit( $hold )
			: __( 'It has not been sent to the printshop yet.', 'pixonaut-for-woocommerce' );
		/* translators: 1: product name of the order line, 2: why it is held and when it goes ahead. */
		return sprintf( __( 'Pixonaut: the printshop order for "%1$s" is held. %2$s', 'pixonaut-for-woocommerce' ), $item->get_name(), $why );
	}

	/**
	 * The order note for a line whose held order Pixonaut released.
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $released_by `releasedBy` of `order.released`.
	 * @param bool                  $goes_on     Whether the order goes on to the printshop; false for one released to `failed` or `cancelled`, whose own note says what came of it.
	 */
	public static function released_note( WC_Order_Item_Product $item, string $released_by, bool $goes_on = true ): string {
		$name = $item->get_name();
		if ( ! $goes_on ) {
			return match ( $released_by ) {
				/* translators: %s: product name of the order line. */
				self::RELEASED_BY_UPGRADE     => sprintf( __( 'Pixonaut: the printshop order for "%s" was released because the Pixonaut workspace upgraded its plan.', 'pixonaut-for-woocommerce' ), $name ),
				/* translators: %s: product name of the order line. */
				self::RELEASED_BY_MONTH_RESET => sprintf( __( 'Pixonaut: the printshop order for "%s" was released because a new month began on the free plan.', 'pixonaut-for-woocommerce' ), $name ),
				/* translators: %s: product name of the order line. */
				default                       => sprintf( __( 'Pixonaut: the printshop order for "%s" was released.', 'pixonaut-for-woocommerce' ), $name ),
			};
		}
		return match ( $released_by ) {
			/* translators: %s: product name of the order line. */
			self::RELEASED_BY_UPGRADE     => sprintf( __( 'Pixonaut: the printshop order for "%s" was released because the Pixonaut workspace upgraded its plan, and goes on to the printshop.', 'pixonaut-for-woocommerce' ), $name ),
			/* translators: %s: product name of the order line. */
			self::RELEASED_BY_MONTH_RESET => sprintf( __( 'Pixonaut: the printshop order for "%s" was released because a new month began on the free plan, and goes on to the printshop.', 'pixonaut-for-woocommerce' ), $name ),
			/* translators: %s: product name of the order line. */
			default                       => sprintf( __( 'Pixonaut: the printshop order for "%s" was released, and goes on to the printshop.', 'pixonaut-for-woocommerce' ), $name ),
		};
	}

	/**
	 * A hold for the monthly order limit, in words.
	 *
	 * @param array{reason: string|null, limit: int|null, periodEnd: int|null} $hold The hold.
	 */
	private static function monthly_limit( array $hold ): string {
		$used = null === $hold['limit']
			? __( 'The Pixonaut workspace has used its free plan\'s orders for this month.', 'pixonaut-for-woocommerce' )
			: sprintf(
				/* translators: %d: the free plan's orders a month. */
				_n( 'The Pixonaut workspace has used its free plan\'s %d order for this month.', 'The Pixonaut workspace has used its free plan\'s %d orders for this month.', $hold['limit'], 'pixonaut-for-woocommerce' ),
				$hold['limit']
			);
		$when = null === $hold['periodEnd']
			? __( 'It goes to the printshop when the workspace upgrades, or when the month resets if the new month has room for it.', 'pixonaut-for-woocommerce' )
			: sprintf(
				/* translators: %s: the date the month resets. */
				__( 'It goes to the printshop when the workspace upgrades, or when the month resets on %s if the new month has room for it.', 'pixonaut-for-woocommerce' ),
				wp_date( (string) get_option( 'date_format' ), $hold['periodEnd'] )
			);
		return $used . ' ' . $when;
	}
}
