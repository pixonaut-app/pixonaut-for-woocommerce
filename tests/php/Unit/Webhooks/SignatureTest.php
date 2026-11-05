<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Webhooks;

use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Webhooks\Signature;

final class SignatureTest extends TestCase {

	private const SECRET = 'whsec_unit';

	private const NOW = 1791892800;

	private const BODY = '{"id":"evt_1","type":"order.status_changed","payload":{},"createdAt":1791892800000}';

	/**
	 * @return array<string, array{array{name: string, secret: string, body: string, headers: array<string, string>}, int}>
	 */
	public static function valid_vectors(): array {
		return self::vectors( 'valid' );
	}

	/**
	 * @return array<string, array{array{name: string, secret: string, body: string, headers: array<string, string>}, int}>
	 */
	public static function invalid_vectors(): array {
		return self::vectors( 'invalid' );
	}

	/**
	 * @dataProvider valid_vectors
	 *
	 * @param array{name: string, secret: string, body: string, headers: array<string, string>} $vector Pixonaut's vector.
	 * @param int                                                                               $now    The vectors' now.
	 */
	public function test_pixonauts_valid_vectors_verify( array $vector, int $now ): void {
		$this->assertTrue( self::verify_vector( $vector, $now ) );
	}

	/**
	 * @dataProvider invalid_vectors
	 *
	 * @param array{name: string, secret: string, body: string, headers: array<string, string>} $vector Pixonaut's vector.
	 * @param int                                                                               $now    The vectors' now.
	 */
	public function test_pixonauts_invalid_vectors_do_not( array $vector, int $now ): void {
		$this->assertFalse( self::verify_vector( $vector, $now ) );
	}

	public function test_the_tolerance_matches_the_vectors(): void {
		$this->assertSame( self::document()['toleranceSeconds'], Signature::TOLERANCE_SECONDS );
		$this->assertSame( self::document()['scheme'], Signature::SCHEME );
	}

	/**
	 * @return iterable<string, array{int, bool}>
	 */
	public static function ages(): iterable {
		yield 'just sent' => array( 0, true );
		yield 'at the tolerance' => array( Signature::TOLERANCE_SECONDS, true );
		yield 'a second past it' => array( Signature::TOLERANCE_SECONDS + 1, false );
		yield 'from the future, at the tolerance' => array( -Signature::TOLERANCE_SECONDS, true );
		yield 'from the future, past it' => array( -Signature::TOLERANCE_SECONDS - 1, false );
	}

	/**
	 * @dataProvider ages
	 *
	 * @param int  $age      Seconds between the timestamp and now.
	 * @param bool $verifies Whether it verifies.
	 */
	public function test_tolerance_edges( int $age, bool $verifies ): void {
		$timestamp = (string) ( self::NOW - $age );

		$this->assertSame( $verifies, Signature::verify( self::SECRET, $timestamp, self::sign( $timestamp ), self::BODY, self::NOW ) );
	}

	public function test_one_matching_signature_of_several_verifies(): void {
		$timestamp = (string) self::NOW;
		$header    = 'v1=' . str_repeat( '0', 64 ) . ', ' . self::sign( $timestamp );

		$this->assertTrue( Signature::verify( self::SECRET, $timestamp, $header, self::BODY, self::NOW ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function malformed(): iterable {
		yield 'no timestamp' => array( '', 'v1=00' );
		yield 'a timestamp that is not a number' => array( '1791892800.5', 'v1=00' );
		yield 'no signature' => array( (string) self::NOW, '' );
		yield 'another scheme' => array( (string) self::NOW, 'v0=00' );
	}

	/**
	 * @dataProvider malformed
	 *
	 * @param string $timestamp Timestamp header.
	 * @param string $signature Signature header.
	 */
	public function test_malformed_headers_do_not_verify( string $timestamp, string $signature ): void {
		$this->assertFalse( Signature::verify( self::SECRET, $timestamp, $signature, self::BODY, self::NOW ) );
	}

	public function test_an_empty_secret_never_verifies(): void {
		$timestamp = (string) self::NOW;
		$signature = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . self::BODY, '' );

		$this->assertFalse( Signature::verify( '', $timestamp, $signature, self::BODY, self::NOW ) );
	}

	private static function sign( string $timestamp ): string {
		return 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . self::BODY, self::SECRET );
	}

	/**
	 * @param array{name: string, secret: string, body: string, headers: array<string, string>} $vector Pixonaut's vector.
	 * @param int                                                                               $now    The vectors' now.
	 */
	private static function verify_vector( array $vector, int $now ): bool {
		return Signature::verify(
			$vector['secret'],
			$vector['headers'][ Signature::TIMESTAMP_HEADER ] ?? '',
			$vector['headers'][ Signature::SIGNATURE_HEADER ] ?? '',
			$vector['body'],
			$now
		);
	}

	/**
	 * @param string $kind `valid` or `invalid`.
	 * @return array<string, array{array{name: string, secret: string, body: string, headers: array<string, string>}, int}>
	 */
	private static function vectors( string $kind ): array {
		$document = self::document();
		$cases    = array();
		foreach ( 'valid' === $kind ? $document['valid'] : $document['invalid'] as $vector ) {
			$cases[ $vector['name'] ] = array( $vector, $document['now'] );
		}
		return $cases;
	}

	/**
	 * @return array{scheme: string, toleranceSeconds: int, now: int, valid: list<array{name: string, secret: string, body: string, headers: array<string, string>}>, invalid: list<array{name: string, secret: string, body: string, headers: array<string, string>}>}
	 */
	private static function document(): array {
		$json = file_get_contents( dirname( __DIR__, 4 ) . '/contracts/pixonaut/webhook-signature-vectors.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
	}
}
