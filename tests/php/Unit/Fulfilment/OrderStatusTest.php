<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class OrderStatusTest extends TestCase {

	/**
	 * @return iterable<string, array{string|null, string, string, bool}>
	 */
	public static function changes(): iterable {
		yield 'the first change' => array( null, OrderStatus::SUBMITTED, OrderStatus::QUEUED, true );
		yield 'the first change seen is a later one' => array( null, OrderStatus::PRINTED, OrderStatus::SHIPPED, true );
		yield 'the next step' => array( OrderStatus::QUEUED, OrderStatus::QUEUED, OrderStatus::PRINTED, true );
		yield 'a step further than the line' => array( OrderStatus::SUBMITTED, OrderStatus::PRINTED, OrderStatus::SHIPPED, true );
		yield 'the same status again' => array( OrderStatus::SHIPPED, OrderStatus::PRINTED, OrderStatus::SHIPPED, false );
		yield 'an earlier change after a later one' => array( OrderStatus::SHIPPED, OrderStatus::SUBMITTED, OrderStatus::QUEUED, false );
		yield 'a skipped change delivered late' => array( OrderStatus::PRINTED, OrderStatus::SUBMITTED, OrderStatus::QUEUED, false );
		yield 'off the chain, from where the line stands' => array( OrderStatus::PRINTED, OrderStatus::PRINTED, OrderStatus::FAILED, true );
		yield 'off the chain, from elsewhere' => array( OrderStatus::PRINTED, OrderStatus::QUEUED, OrderStatus::FAILED, false );
		yield 'back on the chain from off it' => array( OrderStatus::OUTCOME_UNKNOWN, OrderStatus::OUTCOME_UNKNOWN, OrderStatus::SUBMITTED, true );
		yield 'a forward change delivered after a failure' => array( OrderStatus::FAILED, OrderStatus::SUBMITTED, OrderStatus::QUEUED, false );
		yield 'a recovery from a failure' => array( OrderStatus::FAILED, OrderStatus::FAILED, OrderStatus::SUBMITTED, true );
		yield 'a forward change delivered after an unknown outcome' => array( OrderStatus::OUTCOME_UNKNOWN, OrderStatus::SUBMITTED, OrderStatus::QUEUED, false );
		yield 'a waiting order handed to its printshop' => array( OrderStatus::AWAITING_PRINTS, '', OrderStatus::SUBMITTED, true );
		yield 'a waiting order reported after it was handed on' => array( OrderStatus::SUBMITTED, '', OrderStatus::AWAITING_PRINTS, false );
		yield 'a waiting order failing' => array( OrderStatus::AWAITING_PRINTS, OrderStatus::AWAITING_PRINTS, OrderStatus::FAILED, true );
		yield 'an order placed held' => array( null, '', OrderStatus::HELD, true );
		yield 'a held order released' => array( OrderStatus::HELD, OrderStatus::HELD, OrderStatus::SUBMITTED, true );
		yield 'a held order released to wait for its prints' => array( OrderStatus::HELD, OrderStatus::HELD, OrderStatus::AWAITING_PRINTS, true );
		yield 'a hold reported after the release' => array( OrderStatus::SUBMITTED, '', OrderStatus::HELD, false );
		yield 'a status this version does not know' => array( OrderStatus::QUEUED, OrderStatus::SUBMITTED, 'teleported', false );
	}

	/**
	 * @dataProvider changes
	 *
	 * @param string|null $applied  Status last applied to the line.
	 * @param string      $previous The change's previousStatus.
	 * @param string      $status   The change's status.
	 * @param bool        $moves_on Whether it moves the line on.
	 */
	public function test_moves_on( ?string $applied, string $previous, string $status, bool $moves_on ): void {
		$this->assertSame( $moves_on, OrderStatus::moves_on( $applied, $previous, $status ) );
	}

	public function test_every_chain_status_maps_to_a_line_state(): void {
		$this->assertSame( OrderStatus::CHAIN, array_keys( OrderStatus::LINE_STATES ) );
	}
}
