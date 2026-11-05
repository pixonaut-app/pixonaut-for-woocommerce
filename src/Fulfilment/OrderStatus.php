<?php
/**
 * A Pixonaut order's status.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

/**
 * The statuses a Pixonaut order reports in `order.status_changed` (and
 * `order.released`), and the order in which they follow each other on the
 * way to the customer. The set
 * is open: Pixonaut may add statuses.
 */
final class OrderStatus {

	/**
	 * Taken, but not yet handed on: a free workspace past its plan's
	 * monthly orders. Pixonaut releases it, with `order.released`, when the
	 * workspace upgrades or the month resets.
	 */
	public const HELD = 'held';

	/**
	 * Placed, waiting for its prints to finish rendering before Pixonaut
	 * hands it to the printshop.
	 */
	public const AWAITING_PRINTS = 'awaiting_prints';

	public const SUBMITTED = 'submitted';

	public const QUEUED = 'queued';

	public const PRINTED = 'printed';

	public const SHIPPED = 'shipped';

	public const FAILED = 'failed';

	public const OUTCOME_UNKNOWN = 'outcome_unknown';

	public const CANCELLED = 'cancelled';

	/**
	 * The forward path, in rank order.
	 */
	public const CHAIN = array( self::HELD, self::AWAITING_PRINTS, self::SUBMITTED, self::QUEUED, self::PRINTED, self::SHIPPED );

	/**
	 * Statuses off the forward path, which have handlers of their own.
	 */
	public const OFF_CHAIN = array( self::FAILED, self::OUTCOME_UNKNOWN, self::CANCELLED );

	/**
	 * The line state each status on the forward path puts a line in.
	 */
	public const LINE_STATES = array(
		self::HELD            => LineState::ORDERED,
		self::AWAITING_PRINTS => LineState::ORDERED,
		self::SUBMITTED       => LineState::ORDERED,
		self::QUEUED          => LineState::ORDERED,
		self::PRINTED         => LineState::ORDERED,
		self::SHIPPED         => LineState::SHIPPED,
	);

	/**
	 * Whether a change from $previous to $status moves a line on, given the
	 * status last applied to it: it does when it starts where the line stands,
	 * or reaches further along CHAIN than the line has been. A duplicate, or a
	 * change delivered after a later one, does not. Once a status off CHAIN
	 * (`failed`, `outcome_unknown`) was applied, only a change from that
	 * status moves the line on: a forward change delivered late would
	 * otherwise undo it.
	 *
	 * @param string|null $applied  The status last applied to the line; null when none was.
	 * @param string      $previous The change's `previousStatus`.
	 * @param string      $status   The change's `status`.
	 */
	public static function moves_on( ?string $applied, string $previous, string $status ): bool {
		if ( $status === $applied ) {
			return false;
		}
		if ( null !== $applied && $previous === $applied ) {
			return true;
		}
		$to = array_search( $status, self::CHAIN, true );
		if ( false === $to ) {
			return false;
		}
		if ( null === $applied ) {
			return true;
		}
		$from = array_search( $applied, self::CHAIN, true );
		return false !== $from && $to > $from;
	}
}
