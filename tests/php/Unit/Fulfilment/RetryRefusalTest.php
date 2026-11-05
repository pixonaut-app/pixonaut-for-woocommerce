<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\RetryRefusal;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class RetryRefusalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_every_code_the_spec_declares_for_a_retry_has_words(): void {
		$codes = self::declared_codes();
		$this->assertContains( ErrorCodes::PRINT_NOT_COMPLETED, $codes );

		$unmapped = array_values( array_filter( $codes, static fn ( string $code ): bool => null === RetryRefusal::words( $code ) ) );

		$this->assertSame( array(), $unmapped, 'Each code the retry can refuse with needs its words in RetryRefusal.' );
	}

	public function test_every_code_the_spec_declares_for_a_retry_is_known(): void {
		$unknown = array_values( array_filter( self::declared_codes(), static fn ( string $code ): bool => ! ErrorCodes::is_known( $code ) ) );

		$this->assertSame( array(), $unknown );
	}

	public function test_a_print_still_being_made_says_to_wait_for_it(): void {
		$this->assertSame( 'The print is still being made. Try again once it is finished.', RetryRefusal::explain( ErrorCodes::PRINT_NOT_COMPLETED ) );
	}

	public function test_a_code_shared_with_placing_the_order_reads_as_it_does_there(): void {
		$this->assertSame( NeedsAttention::explain( ErrorCodes::PRINTSHOP_DISCONNECTED ), RetryRefusal::explain( ErrorCodes::PRINTSHOP_DISCONNECTED ) );
	}

	public function test_an_unknown_code_is_named(): void {
		$this->assertNull( RetryRefusal::words( 'teleporter_jammed' ) );
		$this->assertStringContainsString( '"teleporter_jammed"', RetryRefusal::explain( 'teleporter_jammed' ) );
	}

	/**
	 * Every error code the pinned spec declares for `POST /v1/orders/{id}/retry`.
	 *
	 * @return list<string>
	 */
	private static function declared_codes(): array {
		$spec                  = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/contracts/pixonaut/openapi.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		list( $method, $path ) = explode( ' ', Operations::RETRY_ORDER, 2 );
		$codes                 = array();
		foreach ( $spec['paths'][ $path ][ strtolower( $method ) ]['responses'] as $status => $response ) {
			$error = $response['content']['application/json']['schema']['properties']['error'] ?? array();
			if ( (int) $status < 400 ) {
				continue;
			}
			foreach ( array_merge( $error['x-extensible-enum'] ?? array(), $error['enum'] ?? array() ) as $code ) {
				$codes[] = (string) $code;
			}
		}
		return array_values( array_unique( $codes ) );
	}
}
