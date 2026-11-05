<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Connection;

use Pixonaut\WooCommerce\Connection\Pkce;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class PkceTest extends TestCase {

	/**
	 * RFC 7636, appendix B.
	 */
	private const RFC_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public function test_challenge_is_the_s256_of_the_verifier(): void {
		$this->assertSame( self::RFC_CHALLENGE, Pkce::challenge( self::RFC_VERIFIER ) );
	}

	public function test_verifier_is_43_unreserved_characters_and_new_each_time(): void {
		$first = Pkce::verifier();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $first );
		$this->assertNotSame( $first, Pkce::verifier() );
	}
}
