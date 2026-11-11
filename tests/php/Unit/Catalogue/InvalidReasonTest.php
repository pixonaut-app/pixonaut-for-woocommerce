<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\InvalidDetail;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use ReflectionClass;

final class InvalidReasonTest extends TestCase {

	private const SPEC = __DIR__ . '/../../../../contracts/pixonaut/openapi.json';

	/**
	 * The operations a product check calls (`ProductLinker`, `StandingRows`
	 * through `Catalogue` and `Designs`), whose refusals reach the store
	 * manager as a reason.
	 */
	private const CHECK_OPERATIONS = array(
		'blanks.get',
		'blanks.variants.list',
		'templates.get',
		'templates.constraints.get',
		'templates.printSize.validate',
		'designs.findOrCreateStanding',
	);

	private const PRINT_SIZE_OPERATION = 'templates.printSize.validate';

	/**
	 * What every reason without a message of its own would read.
	 */
	private const GENERIC = 'Pixonaut cannot print this configuration.';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_every_verdict_pixonaut_can_give_on_a_print_size_has_a_message(): void {
		$verdicts = self::verdicts();

		$this->assertNotEmpty( $verdicts );
		$this->assertSame( array(), array_values( array_diff( $verdicts, InvalidReason::PIXONAUT_VERDICTS ) ), 'Pixonaut answers a print size verdict InvalidReason has no constant for.' );
		foreach ( $verdicts as $verdict ) {
			$this->assertStringNotContainsString( $verdict, InvalidReason::message( $verdict ), "{$verdict} falls back to naming the code." );
		}
	}

	public function test_every_error_code_a_product_check_can_be_refused_with_has_a_message(): void {
		$codes = array_merge( self::refusals(), ApiException::CLIENT_CODES );

		$this->assertContains( 'unauthorized', $codes );
		$this->assertSame( array(), array_values( array_filter( $codes, static fn ( string $code ): bool => ! InvalidReason::knows( $code ) ) ), 'Pixonaut can refuse a product check with a code that has no message.' );
		foreach ( $codes as $code ) {
			$text = InvalidReason::message( InvalidReason::CHECK_FAILED, new InvalidDetail( code: $code ) );
			$this->assertStringNotContainsString( "“{$code}”", $text, "{$code} falls back to naming the code." );
		}
	}

	public function test_every_reason_of_this_plugin_has_a_message_of_its_own(): void {
		$reasons = array_filter( ( new ReflectionClass( InvalidReason::class ) )->getConstants(), 'is_string' );

		foreach ( array_merge( array_values( $reasons ), array( ProductConfig::REASON_NEWER_SCHEMA ) ) as $reason ) {
			$text = InvalidReason::message( $reason );
			$this->assertNotSame( self::GENERIC, $text, $reason );
			$this->assertStringNotContainsString( "“{$reason}”", $text, $reason );
		}
	}

	public function test_a_code_without_a_message_is_named_as_it_is(): void {
		$this->assertStringContainsString( '“too_small”', InvalidReason::message( 'too_small' ) );
		$this->assertStringContainsString( '“brand_new_refusal”', InvalidReason::message( InvalidReason::CHECK_FAILED, new InvalidDetail( code: 'brand_new_refusal' ) ) );
	}

	public function test_a_refused_size_names_its_side_and_size_and_where_it_is_set(): void {
		$text = InvalidReason::message( InvalidReason::PRINT_SIZE_NOT_AREA, new InvalidDetail( 'Front', '700 × 1000 mm' ), true );

		$this->assertStringStartsWith( 'Side “Front” at 700 × 1000 mm: ', $text );
		$this->assertStringContainsString( 'the variation’s Pixonaut fields', $text );
		$this->assertStringContainsString( 'the product’s Pixonaut tab', InvalidReason::message( InvalidReason::TOO_LARGE, new InvalidDetail( size: '700 × 1000 mm' ) ) );
		$this->assertStringStartsWith( 'At 700 × 1000 mm: ', InvalidReason::message( InvalidReason::TOO_LARGE, new InvalidDetail( size: '700 × 1000 mm' ) ) );
	}

	public function test_a_refused_standing_design_says_what_pixonaut_answered(): void {
		$text = InvalidReason::message( InvalidReason::STANDING_REFUSED, new InvalidDetail( code: 'rate_limited' ) );

		$this->assertStringStartsWith( 'Pixonaut would not make the design of a fixed-design side. ', $text );
		$this->assertStringContainsString( 'Wait a minute', $text );
	}

	public function test_a_detail_naming_nothing_is_none_and_survives_storing(): void {
		$this->assertNull( InvalidDetail::of( '', null, '' ) );
		$this->assertEquals( new InvalidDetail( 'Back', '30×40' ), InvalidDetail::from_array( ( new InvalidDetail( 'Back', '30×40' ) )->to_array() ) );
	}

	/**
	 * The verdicts Pixonaut can give a print size, as the pinned spec lists them.
	 *
	 * @return list<string>
	 */
	private static function verdicts(): array {
		$found = array();
		foreach ( self::responses( self::PRINT_SIZE_OPERATION ) as $status => $response ) {
			if ( '200' === (string) $status ) {
				$found = array_merge( $found, self::reason_enums( $response ) );
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * The `x-extensible-enum` of every `reason` property under a schema.
	 *
	 * @param mixed $schema Part of the spec.
	 * @return list<string>
	 */
	private static function reason_enums( mixed $schema ): array {
		if ( ! is_array( $schema ) ) {
			return array();
		}
		$found = array();
		if ( is_array( $schema['properties']['reason']['x-extensible-enum'] ?? null ) ) {
			$found = array_values( array_filter( $schema['properties']['reason']['x-extensible-enum'], 'is_string' ) );
		}
		foreach ( $schema as $part ) {
			$found = array_merge( $found, self::reason_enums( $part ) );
		}
		return $found;
	}

	/**
	 * The error codes the check's operations can answer, and those any route can.
	 *
	 * @return list<string>
	 */
	private static function refusals(): array {
		$spec  = self::spec();
		$codes = array();
		foreach ( is_array( $spec['x-refusals'] ?? null ) ? $spec['x-refusals'] : array() as $refusal ) {
			$codes[] = is_array( $refusal ) ? (string) ( $refusal['code'] ?? '' ) : (string) $refusal;
		}
		foreach ( self::CHECK_OPERATIONS as $operation ) {
			foreach ( self::responses( $operation ) as $response ) {
				$enum  = $response['content']['application/json']['schema']['properties']['error']['x-extensible-enum'] ?? array();
				$codes = array_merge( $codes, is_array( $enum ) ? $enum : array() );
			}
		}
		return array_values( array_unique( array_filter( $codes ) ) );
	}

	/**
	 * An operation's responses, by status.
	 *
	 * @param string $operation_id The operation.
	 * @return array<string, array<string, mixed>>
	 */
	private static function responses( string $operation_id ): array {
		foreach ( self::spec()['paths'] as $operations ) {
			foreach ( $operations as $operation ) {
				if ( is_array( $operation ) && ( $operation['operationId'] ?? null ) === $operation_id ) {
					return $operation['responses'];
				}
			}
		}
		self::fail( "The pinned spec has no {$operation_id}." );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec(): array {
		static $spec = null;
		$spec      ??= json_decode( (string) file_get_contents( self::SPEC ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return $spec;
	}
}
