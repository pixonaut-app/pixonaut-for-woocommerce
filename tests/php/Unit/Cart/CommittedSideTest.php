<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Cart;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Cart\CommittedSide;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class CommittedSideTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
	}

	private static function side( ?string $proof_id ): CommittedSide {
		return new CommittedSide( 'front', DesignFixture::ID, 1, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, 'commit-key', $proof_id );
	}

	public function test_the_proof_id_survives_the_cart_session(): void {
		$this->assertEquals( self::side( DesignFixture::PROOF_ID ), CommittedSide::from_array( self::side( DesignFixture::PROOF_ID )->to_array() ) );
	}

	public function test_the_field_values_survive_the_cart_session(): void {
		$side = new CommittedSide( 'front', DesignFixture::ID, 1, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, 'commit-key', DesignFixture::PROOF_ID, array( new FieldValue( 'Name', FieldValue::TEXT, 'Anna' ) ) );

		$this->assertEquals( $side, CommittedSide::from_array( $side->to_array() ) );
	}

	public function test_a_side_stored_before_field_values_were_kept_has_none(): void {
		$stored = self::side( DesignFixture::PROOF_ID )->to_array();
		unset( $stored['fields'] );

		$this->assertSame( array(), CommittedSide::from_array( $stored )?->fields );
	}

	public function test_a_correction_takes_the_values_it_reported_and_drops_the_old_ones(): void {
		$side = new CommittedSide( 'front', DesignFixture::ID, 1, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, 'commit-key', DesignFixture::PROOF_ID, array( new FieldValue( 'Name', FieldValue::TEXT, 'Anna' ) ) );
		$bo   = array( new FieldValue( 'Name', FieldValue::TEXT, 'Bo' ) );

		$this->assertEquals( $bo, $side->corrected( 2, null, $bo )->fields );
		$this->assertSame( array(), $side->corrected( 2, null )->fields );
	}

	public function test_a_side_stored_without_a_proof_id_still_reads(): void {
		$stored = self::side( DesignFixture::PROOF_ID )->to_array();
		unset( $stored['proofId'] );

		$side = CommittedSide::from_array( $stored );

		$this->assertNotNull( $side );
		$this->assertNull( $side->proof_id );
	}
}
