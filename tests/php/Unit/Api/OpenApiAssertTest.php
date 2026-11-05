<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use PHPUnit\Framework\AssertionFailedError;

final class OpenApiAssertTest extends TestCase {

	private const APPROVE_BODY = array( 'paths', '/v1/designs/{id}/approve', 'post', 'requestBody', 'content', 'application/json', 'schema' );

	/**
	 * A spot colour inside a template document; `rgb` is a 3-tuple of 0–255.
	 */
	private const SPOT_COLOUR = array( 'components', 'schemas', 'ResolvedTemplate', 'properties', 'background', 'oneOf', '0', 'properties', 'color', 'properties', 'value', 'anyOf', '1' );

	public function test_rejects_after_days_zero(): void {
		OpenApiAssert::assert_pointer( 'approve', (object) array( 'afterDays' => 3 ), self::APPROVE_BODY );

		$this->expectException( AssertionFailedError::class );
		OpenApiAssert::assert_pointer( 'approve', (object) array( 'afterDays' => 0 ), self::APPROVE_BODY );
	}

	/**
	 * @dataProvider bad_rgb_tuples
	 *
	 * @param list<int|float> $rgb Tuple.
	 */
	public function test_rejects_a_bad_rgb_tuple( array $rgb ): void {
		$this->expectException( AssertionFailedError::class );
		OpenApiAssert::assert_pointer( 'spot colour', json_decode( (string) json_encode( array( 'spot' => array( 'preview' => array( 'rgb' => $rgb ) ) ) ) ), self::SPOT_COLOUR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @return array<string, array{list<int|float>}>
	 */
	public static function bad_rgb_tuples(): array {
		return array(
			'too short'    => array( array( 255, 0 ) ),
			'too long'     => array( array( 255, 0, 0, 1 ) ),
			'out of range' => array( array( 255, 0, 300 ) ),
		);
	}

	public function test_request_must_be_in_consumed_json(): void {
		$path = OpenApiAssert::unconsumed_get_path();

		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( "GET {$path} is not in contracts/pixonaut/consumed.json" );
		OpenApiAssert::request( 'GET', $path, array(), null );
	}

	public function test_request_body_is_validated(): void {
		$this->expectException( AssertionFailedError::class );
		OpenApiAssert::request( 'POST', '/v1/designs', array( 'Idempotency-Key' => 'k' ), '{"proofToken":""}' );
	}

	public function test_header_parameters_are_validated(): void {
		$this->expectException( AssertionFailedError::class );
		OpenApiAssert::request( 'POST', '/v1/designs', array( 'Idempotency-Key' => "bad key\n" ), '{"proofToken":"pt_1"}' );
	}

	public function test_response_status_must_be_declared(): void {
		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( 'declares no 418 response' );
		OpenApiAssert::response( 'GET', '/v1/connect', 418, '{}' );
	}

	public function test_response_body_is_validated(): void {
		$this->expectException( AssertionFailedError::class );
		OpenApiAssert::response( 'GET', '/v1/connect', 200, '{"connectionId":"sc_1"}' );
	}

	public function test_webhook_payload_is_validated(): void {
		$this->expectException( AssertionFailedError::class );
		OpenApiAssert::webhook( 'design.approved', '{"type":"design.approved"}' );
	}
}
