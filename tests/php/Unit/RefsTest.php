<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Pixonaut\WooCommerce\Refs;

final class RefsTest extends TestCase {

	private const SITE = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	private const TOKEN = 'eyJhdWQiOiJwcm9vZiJ9.c2lnbmF0dXJl';

	private const OWNER = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

	public function test_the_commit_key_hashes_the_token(): void {
		$this->assertSame( 'wc:' . self::SITE . ':commit:' . hash( 'sha256', self::TOKEN ), Refs::commit_key( self::SITE, self::TOKEN ) );
	}

	public function test_the_commit_key_differs_by_what_the_commit_says_of_the_preview(): void {
		$keys = array(
			Refs::commit_key( self::SITE, self::TOKEN ),
			Refs::commit_key( self::SITE, self::TOKEN, true ),
			Refs::commit_key( self::SITE, self::TOKEN, false ),
		);

		$this->assertCount( 3, array_unique( $keys ) );
		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression( '/^[\x21-\x7e]{1,255}$/', $key );
		}
	}

	public function test_the_commit_key_fits_an_idempotency_key(): void {
		$this->assertMatchesRegularExpression( '/^[\x21-\x7e]{1,255}$/', Refs::commit_key( self::SITE, str_repeat( 'x', 4096 ) ) );
	}

	public function test_the_line_key_follows_the_owner_the_tokens_and_their_sides(): void {
		$line = Refs::line_key( array( 'front' => self::TOKEN ), self::OWNER );

		$this->assertSame( $line, Refs::line_key( array( 'front' => self::TOKEN ), self::OWNER ) );
		$this->assertNotSame( $line, Refs::line_key( array( 'front' => self::TOKEN ), 'another-customer' ) );
		$this->assertNotSame( $line, Refs::line_key( array( 'back' => self::TOKEN ), self::OWNER ) );
		$this->assertNotSame( $line, Refs::line_key( array( 'front' => self::TOKEN . 'x' ), self::OWNER ) );
		$this->assertNotSame(
			Refs::line_key(
				array(
					'front' => 'a',
					'back'  => 'b',
				),
				self::OWNER
			),
			Refs::line_key(
				array(
					'front' => 'b',
					'back'  => 'a',
				),
				self::OWNER
			)
		);
	}

	public function test_cart_and_order_refs(): void {
		$this->assertSame( 'wc:' . self::SITE . ':cart:abc', Refs::cart_ref( self::SITE, 'abc' ) );
		$this->assertSame( 'wc:' . self::SITE . ':order:42:item:7', Refs::order_item_ref( self::SITE, 42, 7 ) );
	}

	public function test_only_a_cart_ref_is_a_cart_ref(): void {
		$this->assertTrue( Refs::is_cart_ref( Refs::cart_ref( self::SITE, 'abc' ) ) );
		$this->assertTrue( Refs::is_cart_ref( Refs::cart_ref( 'another-site', 'abc' ) ) );
		$this->assertFalse( Refs::is_cart_ref( Refs::order_item_ref( self::SITE, 42, 7 ) ) );
		$this->assertFalse( Refs::is_cart_ref( Refs::order_key( self::SITE, 7 ) ) );
		$this->assertFalse( Refs::is_cart_ref( 'shop:' . self::SITE . ':cart:abc' ) );
		$this->assertFalse( Refs::is_cart_ref( '' ) );
	}

	public function test_order_keys(): void {
		$this->assertSame( 'wc:' . self::SITE . ':item:42:reorder', Refs::reorder_key( self::SITE, 42 ) );
		$this->assertSame( 'wc:' . self::SITE . ':item:7', Refs::order_key( self::SITE, 7 ) );
	}

	public function test_refs_fit_an_external_ref(): void {
		$max = 256;
		$this->assertLessThanOrEqual( $max, strlen( Refs::cart_ref( self::SITE, Refs::line_key( array( 'front' => self::TOKEN ), self::OWNER ) ) ) );
		$this->assertLessThanOrEqual( $max, strlen( Refs::order_item_ref( self::SITE, PHP_INT_MAX, PHP_INT_MAX ) ) );
	}
}
