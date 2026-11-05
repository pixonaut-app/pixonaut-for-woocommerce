<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Connection;

use LogicException;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class CipherTest extends TestCase {

	private const SECRET = 'auth-key-and-salt';

	private const PLAINTEXT = '{"integration_key":"ik_live_0123456789abcdef01234567"}';

	public function test_round_trip(): void {
		$cipher = new Cipher( self::SECRET );

		$sealed = $cipher->encrypt( self::PLAINTEXT );

		$this->assertSame( self::PLAINTEXT, $cipher->decrypt( $sealed ) );
		$this->assertStringNotContainsString( 'ik_live', $sealed );
	}

	public function test_each_encryption_differs(): void {
		$cipher = new Cipher( self::SECRET );

		$this->assertNotSame( $cipher->encrypt( self::PLAINTEXT ), $cipher->encrypt( self::PLAINTEXT ) );
	}

	public function test_another_key_cannot_read_it(): void {
		$sealed = ( new Cipher( self::SECRET ) )->encrypt( self::PLAINTEXT );

		$this->assertNull( ( new Cipher( 'another-salt' ) )->decrypt( $sealed ) );
	}

	public function test_altered_or_foreign_text_reads_as_nothing(): void {
		$cipher = new Cipher( self::SECRET );
		$sealed = $cipher->encrypt( self::PLAINTEXT );
		$last   = strlen( $sealed ) - 2;

		$altered = substr( $sealed, 0, $last ) . ( 'A' === $sealed[ $last ] ? 'B' : 'A' ) . substr( $sealed, $last + 1 );

		$this->assertNull( $cipher->decrypt( $altered ) );
		$this->assertNull( $cipher->decrypt( self::PLAINTEXT ) );
		$this->assertNull( $cipher->decrypt( 'pxc1:not base64' ) );
		$this->assertNull( $cipher->decrypt( 'pxc1:' ) );
	}

	public function test_refuses_an_empty_secret(): void {
		$this->expectException( LogicException::class );

		new Cipher( '' );
	}
}
