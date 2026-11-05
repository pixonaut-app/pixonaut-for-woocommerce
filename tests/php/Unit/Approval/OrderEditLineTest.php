<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Approval;

use Pixonaut\WooCommerce\Approval\OrderEditLine;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class OrderEditLineTest extends TestCase {

	private const REVISION = 3;

	/**
	 * @return array<string, array{string, string, bool, bool, int|null, int|null, int, string}>
	 */
	public static function lines(): array {
		$waiting = LineState::AWAITING_CUSTOMER;
		$proof   = ApprovalMode::PROOF_EMAIL;
		$next    = self::REVISION + 1;
		return array(
			'waiting at the proof\'s revision'            => array( $waiting, $proof, false, false, self::REVISION, null, self::REVISION, OrderEditLine::OPEN ),
			'waiting on a change made from the proof'     => array( $waiting, $proof, false, false, $next, self::REVISION, self::REVISION, OrderEditLine::OPEN ),
			'waiting at a newer revision than the proof'  => array( $waiting, $proof, false, false, $next, null, self::REVISION, OrderEditLine::CHANGED ),
			'waiting on a change made from another proof' => array( $waiting, $proof, false, false, $next + 1, $next, self::REVISION, OrderEditLine::CHANGED ),
			'waiting with no revision'                    => array( $waiting, $proof, false, false, null, null, self::REVISION, OrderEditLine::CHANGED ),
			'the order cancelled, refunded or failed'     => array( $waiting, $proof, true, false, self::REVISION, null, self::REVISION, OrderEditLine::CLOSED ),
			'the side approved already'                   => array( $waiting, $proof, false, true, self::REVISION, null, self::REVISION, OrderEditLine::CLOSED ),
			'the line approved'                           => array( LineState::APPROVED, $proof, false, true, self::REVISION, null, self::REVISION, OrderEditLine::CLOSED ),
			'the line ordered, at a newer revision'       => array( LineState::ORDERED, $proof, false, true, $next, null, self::REVISION, OrderEditLine::CLOSED ),
			'the line needs attention'                    => array( LineState::NEEDS_ATTENTION, $proof, false, false, self::REVISION, null, self::REVISION, OrderEditLine::CLOSED ),
			'another approval mode'                       => array( $waiting, ApprovalMode::CHECKBOX, false, false, self::REVISION, null, self::REVISION, OrderEditLine::CLOSED ),
		);
	}

	/**
	 * @dataProvider lines
	 *
	 * @param string   $state     The line's state.
	 * @param string   $mode      Its approval mode.
	 * @param bool     $stopped   Whether the order was stopped.
	 * @param bool     $approved  Whether the side is approved.
	 * @param int|null $revision  The side's revision on the line.
	 * @param int|null $edit_base The revision an unapproved change started from.
	 * @param int      $shown     The revision the proof showed.
	 * @param string   $status    The answer expected.
	 */
	public function test_a_line_is_open_only_while_it_waits_for_the_proof_s_revision( string $state, string $mode, bool $stopped, bool $approved, ?int $revision, ?int $edit_base, int $shown, string $status ): void {
		$this->assertSame( $status, OrderEditLine::status( $state, $mode, $stopped, $approved, $revision, $edit_base, $shown ) );
	}
}
