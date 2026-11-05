<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Connection;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Tests\Unit\FakeUserMeta;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ConnectAttemptsTest extends TestCase {

	use FakeUserMeta;

	private const ADMIN = 1;

	private const OTHER_ADMIN = 2;

	private const NOW = 1_800_000_000;

	private const REDIRECT_URI = 'https://shop.test/wp-admin/admin-post.php?action=pixonaut_wc_connect_return';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_user_meta();
	}

	public function test_the_state_returns_the_verifier_once(): void {
		$attempts = new ConnectAttempts();
		$started  = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );

		$this->assertSame(
			array(
				'verifier'     => $started['verifier'],
				'redirect_uri' => self::REDIRECT_URI,
				'store_access' => false,
			),
			$attempts->take( self::ADMIN, $started['state'], self::NOW )
		);
		$this->assertNull( $attempts->take( self::ADMIN, $started['state'], self::NOW ) );
	}

	public function test_the_state_is_not_stored_as_sent(): void {
		$started = ( new ConnectAttempts() )->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );

		$this->assertStringNotContainsString( $started['state'], (string) json_encode( $this->user_meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}

	public function test_a_wrong_state_ends_the_attempt(): void {
		$attempts = new ConnectAttempts();
		$started  = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );

		$this->assertNull( $attempts->take( self::ADMIN, 'forged', self::NOW ) );
		$this->assertNull( $attempts->take( self::ADMIN, $started['state'], self::NOW ) );
	}

	public function test_the_state_is_bound_to_the_admin_who_started(): void {
		$attempts = new ConnectAttempts();
		$started  = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );

		$this->assertNull( $attempts->take( self::OTHER_ADMIN, $started['state'], self::NOW ) );
		$this->assertNotNull( $attempts->take( self::ADMIN, $started['state'], self::NOW ) );
	}

	public function test_the_state_expires(): void {
		$attempts = new ConnectAttempts();
		$started  = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );

		$this->assertNull( $attempts->take( self::ADMIN, $started['state'], self::NOW + ConnectAttempts::LIFETIME_SECONDS + 1 ) );
	}

	public function test_a_new_connect_replaces_the_last(): void {
		$attempts = new ConnectAttempts();
		$first    = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );
		$attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );

		$this->assertNull( $attempts->take( self::ADMIN, $first['state'], self::NOW ) );
	}

	public function test_a_return_that_lost_the_claim_gets_nothing(): void {
		$attempts = new ConnectAttempts();
		$started  = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );
		$this->between_read_and_claim(
			function (): void {
				// A return that came in at the same time claims the attempt first.
				unset( $this->user_meta[ self::ADMIN ][ ConnectAttempts::META ] );
			}
		);

		$this->assertNull( $attempts->take( self::ADMIN, $started['state'], self::NOW ) );
	}

	public function test_a_connect_started_while_a_return_reads_stays(): void {
		$attempts = new ConnectAttempts();
		$first    = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );
		$second   = null;
		$this->between_read_and_claim(
			function () use ( $attempts, &$second ): void {
				$second = $attempts->begin( self::ADMIN, self::REDIRECT_URI, self::NOW );
			}
		);

		$this->assertNull( $attempts->take( self::ADMIN, $first['state'], self::NOW ) );
		$this->fake_user_meta();
		$this->assertIsArray( $second );
		$this->assertSame( $second['verifier'], $attempts->take( self::ADMIN, $second['state'], self::NOW )['verifier'] ?? null );
	}

	/**
	 * Runs something once, after the next read of the attempt and before it is claimed.
	 *
	 * @param callable $meanwhile What another request does.
	 */
	private function between_read_and_claim( callable $meanwhile ): void {
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key = '' ) use ( $meanwhile ): mixed {
				$read = $this->user_meta[ $user_id ][ $key ] ?? '';
				$meanwhile();
				return $read;
			}
		);
	}
}
