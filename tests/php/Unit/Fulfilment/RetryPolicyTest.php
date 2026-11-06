<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Filters;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\ErrorMapper;

final class RetryPolicyTest extends TestCase {

	/**
	 * Unix time the answers are read at.
	 */
	private const NOW = 1_800_000_000;

	/**
	 * A `Retry-After` longer than the first wait of the schedule.
	 */
	private const LONG_RETRY_AFTER = 120;

	/**
	 * Each failure the policy meets, as Pixonaut answers it, and what it does.
	 *
	 * @return iterable<string, array{string, int, array<string, mixed>|null, string}>
	 */
	public static function failures(): iterable {
		$retry     = RetryPolicy::RETRY;
		$attention = RetryPolicy::NEEDS_ATTENTION;
		yield 'lock_timeout' => array( 'orders.create', 503, array( 'error' => 'lock_timeout' ), $retry );
		yield 'lock_timeout said not retryable' => array(
			'orders.create',
			503,
			array(
				'error'     => 'lock_timeout',
				'retryable' => false,
			),
			$retry,
		);
		yield 'db_error' => array( 'orders.create', 500, array( 'error' => 'db_error' ), $retry );
		yield 'a failure answered as text' => array( 'orders.create', 502, null, $retry );
		yield 'rate_limited' => array( 'designs.approve', 429, array( 'error' => 'rate_limited' ), $retry );
		yield 'idempotency_key_in_use after the inline resends' => array( 'orders.create', 409, array( 'error' => 'idempotency_key_in_use' ), $retry );
		yield 'revision_changed' => array( 'designs.approve', 409, array( 'error' => 'revision_changed' ), $attention );
		yield 'not_committed at another revision' => array( 'designs.approve', 409, array( 'error' => 'not_committed' ), $attention );
		yield 'design_erased' => array( 'orders.create', 409, array( 'error' => 'design_erased' ), $attention );
		yield 'design_already_ordered' => array( 'orders.create', 409, array( 'error' => 'design_already_ordered' ), $attention );
		yield 'printshop_differs_from_blank' => array( 'orders.create', 422, array( 'error' => 'printshop_differs_from_blank' ), $attention );
		yield 'printshop_disconnected' => array( 'orders.create', 422, array( 'error' => 'printshop_disconnected' ), $attention );
		yield 'print_needs_printshop' => array( 'orders.create', 409, array( 'error' => 'print_needs_printshop' ), $attention );
		yield 'postal_code_required' => array( 'orders.create', 422, array( 'error' => 'postal_code_required' ), $attention );
		yield 'idempotency_key_reused' => array( 'orders.create', 422, array( 'error' => 'idempotency_key_reused' ), $attention );
		yield 'idempotency_key_reused said retryable' => array(
			'orders.create',
			422,
			array(
				'error'     => 'idempotency_key_reused',
				'retryable' => true,
			),
			$attention,
		);
		yield 'a sandbox design gone' => array( 'orders.create', 404, array( 'error' => 'design_not_found' ), $attention );
		yield 'unauthorized' => array( 'orders.create', 401, array( 'error' => 'unauthorized' ), $attention );
		yield 'a code and status this version does not know' => array( 'orders.create', 418, array( 'error' => 'teapot' ), $attention );
	}

	/**
	 * @dataProvider failures
	 *
	 * @param string                    $operation The operation that failed.
	 * @param int                       $status    Its status.
	 * @param array<string, mixed>|null $body      Its body; null for a body that is not JSON.
	 * @param string                    $decision  What the policy does.
	 */
	public function test_decides( string $operation, int $status, ?array $body, string $decision ): void {
		$error = ErrorMapper::fromResponse( Pixonaut::catalogue()->operation( $operation ), $status, array( 'content-type' => 'application/json' ), null === $body ? 'Bad gateway' : (string) json_encode( $body ), self::NOW ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->assertSame( $decision, RetryPolicy::decide( $error ) );
	}

	public function test_a_failure_with_no_answer_is_retried(): void {
		$this->assertSame( RetryPolicy::RETRY, RetryPolicy::decide( new ApiException( 'orders.create', 0, 'transport_failed', true ) ) );
	}

	public function test_waits_one_minute_five_minutes_half_an_hour_two_hours_and_twelve_hours_then_stops(): void {
		$waits = array_map( static fn ( int $failures ): ?int => RetryPolicy::delay( $failures ), range( 0, count( RetryPolicy::SCHEDULE ) ) );

		$this->assertSame( array( 60, 300, 1800, 7200, 43200, null ), $waits );
		$this->assertNull( RetryPolicy::delay( -1 ) );
	}

	public function test_waits_at_least_as_long_as_pixonaut_asked(): void {
		$this->assertSame( self::LONG_RETRY_AFTER, RetryPolicy::delay( 0, self::LONG_RETRY_AFTER ) );
		$this->assertSame( RetryPolicy::SCHEDULE[1], RetryPolicy::delay( 1, self::LONG_RETRY_AFTER ) );
	}

	public function test_the_schedule_is_filtered_and_keeps_only_waits_in_whole_seconds(): void {
		Filters\expectApplied( 'pixonaut_wc_fulfilment_retry_schedule' )
			->with( RetryPolicy::SCHEDULE )
			->andReturn( array( 10, '20', -5, 30.5, 40 ) );

		$this->assertSame( array( 10, 40 ), RetryPolicy::schedule() );
	}

	public function test_an_empty_schedule_never_retries(): void {
		Filters\expectApplied( 'pixonaut_wc_fulfilment_retry_schedule' )->andReturn( array() );

		$this->assertNull( RetryPolicy::delay( 0 ) );
	}
}
