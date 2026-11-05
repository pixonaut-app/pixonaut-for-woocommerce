<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Webhooks;

use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Webhooks\EventsPull;

final class EventsPullTest extends TestCase {

	/**
	 * @return iterable<string, array{0: string|null, 1: list<array<string, mixed>>, 2: string|null, 3: string|null}>
	 */
	public static function pages(): iterable {
		yield 'more to come' => array( 'evt_1', array( array( 'id' => 'evt_2' ) ), 'evt_3', 'evt_3' );
		yield 'nothing more yet: the last event' => array( 'evt_1', array( array( 'id' => 'evt_2' ), array( 'id' => 'evt_3' ) ), null, 'evt_3' );
		yield 'an empty last page keeps the cursor' => array( 'evt_1', array(), null, 'evt_1' );
		yield 'an empty page with more to come moves on' => array( 'evt_1', array(), 'evt_1000', 'evt_1000' );
		yield 'an empty stream from its start' => array( null, array(), null, null );
		yield 'the first events of the stream' => array( null, array( array( 'id' => 'evt_1' ) ), null, 'evt_1' );
	}

	/**
	 * @dataProvider pages
	 *
	 * @param string|null                $after    The cursor before the page.
	 * @param list<array<string, mixed>> $events   The page's events.
	 * @param string|null                $next     Its `nextCursor`.
	 * @param string|null                $expected The cursor after it.
	 */
	public function test_the_cursor_after_a_page( ?string $after, array $events, ?string $next, ?string $expected ): void {
		$this->assertSame(
			$expected,
			EventsPull::moved_on(
				$after,
				array(
					'events'      => $events,
					'next_cursor' => $next,
				)
			)
		);
	}
}
