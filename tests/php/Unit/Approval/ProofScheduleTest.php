<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Approval;

use Pixonaut\WooCommerce\Approval\ProofSchedule;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ProofScheduleTest extends TestCase {

	private const DAY = 86400;

	private const START = 1_800_000_000;

	public function test_the_deadline_is_the_deadline_days_after_the_start(): void {
		$this->assertSame( self::START + 14 * self::DAY, ( new ProofSchedule( 14, 2, 2, 2 ) )->deadline( self::START ) );
	}

	public function test_reminders_start_after_the_first_delay_and_repeat_at_most_so_often(): void {
		$this->assertSame(
			array( self::START + 2 * self::DAY, self::START + 4 * self::DAY ),
			( new ProofSchedule( 14, 2, 2, 2 ) )->reminders( self::START )
		);
		$this->assertSame(
			array( self::START + 3 * self::DAY, self::START + 8 * self::DAY, self::START + 13 * self::DAY ),
			( new ProofSchedule( 30, 3, 5, 3 ) )->reminders( self::START )
		);
	}

	public function test_no_reminder_falls_on_or_after_the_deadline(): void {
		$this->assertSame(
			array( self::START + 2 * self::DAY ),
			( new ProofSchedule( 4, 2, 2, 5 ) )->reminders( self::START ),
			'the second would fall on the deadline itself'
		);
		$this->assertSame( array(), ( new ProofSchedule( 3, 3, 1, 2 ) )->reminders( self::START ) );
	}

	public function test_zero_reminders_sends_none(): void {
		$this->assertSame( array(), ( new ProofSchedule( 14, 2, 2, 0 ) )->reminders( self::START ) );
	}
}
