<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Proofs;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ProofUrlsTest extends TestCase {

	private const KEY = 'site-salt';

	private const REST_ROOT = 'https://shop.test/wp-json/';

	private ProofUrls $urls;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'rest_url' )->alias( static fn ( string $path ): string => self::REST_ROOT . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		$this->urls = new ProofUrls( self::KEY );
	}

	/**
	 * The query of a minted URL.
	 *
	 * @param int $width Width asked for.
	 *
	 * @return array{v: string, w: int, sig: string}
	 */
	private function minted( int $width ): array {
		parse_str( (string) parse_url( $this->urls->url( DesignFixture::ID, DesignFixture::PROOF_ID, $width ), PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress in the unit suite.
		return array(
			'v'   => is_string( $query['v'] ?? null ) ? $query['v'] : '',
			'w'   => is_string( $query['w'] ?? null ) ? (int) $query['w'] : 0,
			'sig' => is_string( $query['sig'] ?? null ) ? $query['sig'] : '',
		);
	}

	public function test_a_minted_url_points_at_the_plugin_route(): void {
		$url = $this->urls->url( DesignFixture::ID, DesignFixture::PROOF_ID, 320 );

		$this->assertStringStartsWith( self::REST_ROOT . 'pixonaut/v1/proof/' . DesignFixture::ID . '?', $url );
	}

	public function test_a_minted_url_verifies(): void {
		$query = $this->minted( 320 );

		$this->assertSame( DesignFixture::PROOF_ID, $query['v'] );
		$this->assertTrue( $this->urls->verify( DesignFixture::ID, $query['v'], $query['w'], $query['sig'] ) );
	}

	public function test_a_url_changed_in_any_part_does_not_verify(): void {
		$query = $this->minted( 320 );

		$this->assertFalse( $this->urls->verify( 'another-design', $query['v'], $query['w'], $query['sig'] ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, 'prf_other', $query['w'], $query['sig'] ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, $query['v'], 640, $query['sig'] ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, $query['v'], $query['w'], str_repeat( '0', 64 ) ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, $query['v'], $query['w'], '' ) );
	}

	public function test_a_url_of_a_picture_on_the_blank_names_it_and_verifies_only_for_it(): void {
		$picture = ProofPicture::on_blank( 'view_front' );
		parse_str( (string) parse_url( $this->urls->url( DesignFixture::ID, DesignFixture::PROOF_ID, 320, $picture ), PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress in the unit suite.
		$sig = is_string( $query['sig'] ?? null ) ? $query['sig'] : '';

		// Decoded once more: the stand-in for add_query_arg() encodes what the class encoded already.
		$this->assertSame( 'blank:view_front', rawurldecode( is_string( $query['p'] ?? null ) ? $query['p'] : '' ) );
		$this->assertSame( $picture->query(), ProofPicture::from_query( 'blank:view_front' )?->query() );
		$this->assertTrue( $this->urls->verify( DesignFixture::ID, DesignFixture::PROOF_ID, 320, $sig, $picture ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, DesignFixture::PROOF_ID, 320, $sig, ProofPicture::on_blank() ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, DesignFixture::PROOF_ID, 320, $sig ) );
	}

	public function test_a_flat_proofs_url_carries_no_picture_and_signs_as_before(): void {
		$query = $this->minted( 320 );
		parse_str( (string) parse_url( $this->urls->url( DesignFixture::ID, DesignFixture::PROOF_ID, 320 ), PHP_URL_QUERY ), $all ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress in the unit suite.

		$this->assertArrayNotHasKey( 'p', $all );
		$this->assertSame( hash_hmac( 'sha256', DesignFixture::ID . "\n" . DesignFixture::PROOF_ID . "\n320", self::KEY ), $query['sig'] );
	}

	public function test_only_a_picture_the_route_knows_is_read_from_a_query(): void {
		$this->assertFalse( ProofPicture::from_query( null )?->on_blank );
		$this->assertNull( ProofPicture::from_query( 'blank:' ) );
		$this->assertNull( ProofPicture::from_query( 'blank:../x' ) );
		$this->assertNull( ProofPicture::from_query( 'flat' ) );
	}

	public function test_a_picture_on_a_variants_photo_round_trips_with_or_without_a_view(): void {
		foreach ( array( ProofPicture::on_blank( null, 'v_tee_s' ), ProofPicture::on_blank( 'view_front', 'v_tee_s' ) ) as $picture ) {
			$read = ProofPicture::from_query( $picture->query() );

			$this->assertSame( array( $picture->view_id, 'v_tee_s' ), array( $read?->view_id, $read?->variant ) );
		}
		$this->assertSame( 'blank::v_tee_s', ProofPicture::on_blank( null, 'v_tee_s' )->query() );
		$this->assertNull( ProofPicture::from_query( 'blank:view_front:' ) );
		$this->assertNull( ProofPicture::from_query( 'blank::' ) );
		$this->assertNull( ProofPicture::from_query( 'blank:view_front:v_1:x' ) );
		$this->assertNull( ProofPicture::from_query( 'blank::../x' ) );
	}

	public function test_another_sites_key_does_not_verify(): void {
		$query = $this->minted( 320 );

		$this->assertFalse( ( new ProofUrls( 'other-salt' ) )->verify( DesignFixture::ID, $query['v'], $query['w'], $query['sig'] ) );
	}

	public function test_a_width_is_rounded_up_to_a_served_width(): void {
		$this->assertSame( 160, ProofUrls::served_width( 1 ) );
		$this->assertSame( 160, ProofUrls::served_width( 160 ) );
		$this->assertSame( 320, ProofUrls::served_width( 161 ) );
		$this->assertSame( 320, ProofUrls::served_width( 300 ) );
		$this->assertSame( ProofUrls::LARGEST_WIDTH, ProofUrls::served_width( 5000 ) );
		$this->assertSame( 320, $this->minted( 300 )['w'] );
	}

	public function test_a_width_off_the_list_is_refused_even_when_signed(): void {
		$off_list = 300;
		// Signed as the class signs, so only the allowlist can refuse it.
		$signature = hash_hmac( 'sha256', DesignFixture::ID . "\n" . DesignFixture::PROOF_ID . "\n" . $off_list, self::KEY );

		$this->assertFalse( ProofUrls::is_served_width( $off_list ) );
		$this->assertFalse( $this->urls->verify( DesignFixture::ID, DesignFixture::PROOF_ID, $off_list, $signature ) );
	}
}
