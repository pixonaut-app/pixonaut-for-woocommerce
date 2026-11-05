<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Approval;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Approval\ProofState;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ProofStateTest extends TestCase {

	private const KEY = 'state-signing-key';

	private const ORDER_ID = 7;

	private const ITEM_ID = 42;

	private const DESIGN_ID = '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c';

	private const REVISION = 3;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_a_signed_state_verifies_to_what_it_was_signed_over(): void {
		$state = ( new ProofState( self::KEY ) )->sign( self::ORDER_ID, self::ITEM_ID, self::DESIGN_ID, self::REVISION );

		$this->assertSame(
			array(
				'order_id'  => self::ORDER_ID,
				'item_id'   => self::ITEM_ID,
				'design_id' => self::DESIGN_ID,
				'revision'  => self::REVISION,
			),
			( new ProofState( self::KEY ) )->verify( $state )
		);
		$this->assertLessThanOrEqual( ProofState::MAX_LENGTH, strlen( $state ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $state, 'safe in a query string as is' );
	}

	public function test_a_state_signed_with_another_key_is_refused(): void {
		$state = ( new ProofState( 'another-key' ) )->sign( self::ORDER_ID, self::ITEM_ID, self::DESIGN_ID, self::REVISION );

		$this->assertNull( ( new ProofState( self::KEY ) )->verify( $state ) );
	}

	public function test_a_state_whose_values_were_changed_is_refused(): void {
		$signer = new ProofState( self::KEY );
		$state  = $signer->sign( self::ORDER_ID, self::ITEM_ID, self::DESIGN_ID, self::REVISION );
		$other  = $signer->sign( self::ORDER_ID, self::ITEM_ID + 1, self::DESIGN_ID, self::REVISION );
		// The other line's values under this line's signature.
		$forged = explode( '.', $other )[0] . '.' . explode( '.', $state )[1];

		$this->assertNull( $signer->verify( $forged ) );
	}

	public function test_a_malformed_state_is_refused(): void {
		$signer = new ProofState( self::KEY );

		$this->assertNull( $signer->verify( '' ) );
		$this->assertNull( $signer->verify( 'no-signature' ) );
		$this->assertNull( $signer->verify( 'a.b.c' ) );
		// Signed, but not the four values.
		$payload = self::base64url( '{"o":"7"}' );
		$this->assertNull( $signer->verify( $payload . '.' . self::base64url( hash_hmac( 'sha256', $payload, self::KEY, true ) ) ) );
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url, as the state is written.
	}
}
