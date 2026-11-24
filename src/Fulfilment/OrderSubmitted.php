<?php
/**
 * Applies `item.submitted` to the line of the item.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;

/**
 * Pixonaut handed an item to its printshop: once its prints were done, or
 * again after a retry, made in the store or in Pixonaut, of a submission
 * that failed. The item keeps its id, so the line holding it is found as for
 * any `item.*` event. Its line is brought in step with the order as Pixonaut
 * has it now (LineActions::current()): events are handled in jobs that need
 * not run in the order they were sent, so a status whose event was handled
 * first, such as an unknown outcome of the hand-on, is applied too, and a
 * retried item moves its line out of `needs_attention`. A final line is left
 * as it is.
 */
final class OrderSubmitted {

	public const TYPE = 'item.submitted';

	/**
	 * @param OrderEventLines $lines   Finds the lines an event is about.
	 * @param LineActions     $actions Reads the order and brings the line in step with it.
	 */
	public function __construct(
		private readonly OrderEventLines $lines,
		private readonly LineActions $actions,
	) {
	}

	/**
	 * @param Event $event An `item.submitted` event.
	 * @throws ApiException When the order could not be read; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		foreach ( $this->lines->of( $event ) as $line ) {
			if ( ! in_array( $line->state(), LineState::FINAL, true ) ) {
				$this->actions->current( $line );
			}
		}
	}
}
