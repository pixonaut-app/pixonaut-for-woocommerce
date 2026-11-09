<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Cart;

use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Cart\DesignCheck;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class DesignCheckTest extends TestCase {

	private const PREVIOUS_VERSION = 1;

	private static function side( ?int $previous_version = self::PREVIOUS_VERSION ): Side {
		return new Side(
			'front',
			'Front',
			DesignFixture::PLACEMENT_ID,
			'Front',
			DesignFixture::TEMPLATE_BASE_ID,
			DesignFixture::LIVE_VERSION,
			$previous_version,
			'Mini tee',
			array(
				new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ),
				new PrintSize( 200.0, 257.14, 'Small' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $overrides Design fields.
	 * @param Side|null            $side      The side; the default one when null.
	 */
	private static function mismatch( array $overrides, ?Side $side = null ): ?string {
		return DesignCheck::mismatch( Design::from_answer( DesignFixture::design( $overrides ) ), $side ?? self::side(), DesignFixture::BLANK_ID );
	}

	public function test_accepts_a_design_made_for_the_side(): void {
		$this->assertNull( self::mismatch( array() ) );
	}

	public function test_accepts_any_size_on_the_menu(): void {
		$this->assertNull(
			self::mismatch(
				array(
					'printSize' => array(
						'widthMm'  => 200,
						'heightMm' => 257.14,
					),
				)
			)
		);
	}

	public function test_accepts_a_size_within_a_hundredth_of_a_millimetre(): void {
		$this->assertNull(
			self::mismatch(
				array(
					'printSize' => array(
						'widthMm'  => DesignFixture::WIDTH_MM + DesignCheck::SIZE_TOLERANCE_MM,
						'heightMm' => DesignFixture::HEIGHT_MM - DesignCheck::SIZE_TOLERANCE_MM,
					),
				)
			)
		);
	}

	public function test_refuses_a_size_beyond_the_tolerance(): void {
		$this->assertSame(
			DesignCheck::PRINT_SIZE,
			self::mismatch(
				array(
					'printSize' => array(
						'widthMm'  => DesignFixture::WIDTH_MM + 0.02,
						'heightMm' => DesignFixture::HEIGHT_MM,
					),
				)
			)
		);
	}

	public function test_refuses_a_design_without_a_size(): void {
		$this->assertSame( DesignCheck::PRINT_SIZE, self::mismatch( array( 'printSize' => null ) ) );
	}

	public function test_refuses_every_size_when_the_menu_is_empty(): void {
		$side = new Side( 'front', 'Front', DesignFixture::PLACEMENT_ID, null, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, null, null );

		$this->assertSame( DesignCheck::PRINT_SIZE, self::mismatch( array(), $side ) );
	}

	public function test_accepts_the_version_before_the_live_one(): void {
		$this->assertNull( self::mismatch( array( 'templateVersion' => self::PREVIOUS_VERSION ) ) );
	}

	public function test_refuses_a_version_two_back_as_outdated(): void {
		$side = new Side( 'front', 'Front', DesignFixture::PLACEMENT_ID, null, DesignFixture::TEMPLATE_BASE_ID, 3, 2, null, self::side()->print_sizes );

		$this->assertSame( DesignCheck::TEMPLATE_OUTDATED, self::mismatch( array( 'templateVersion' => 1 ), $side ) );
	}

	public function test_refuses_the_previous_version_when_the_side_holds_none(): void {
		$this->assertSame( DesignCheck::TEMPLATE_OUTDATED, self::mismatch( array( 'templateVersion' => self::PREVIOUS_VERSION ), self::side( null ) ) );
	}

	public function test_refuses_a_design_without_a_version(): void {
		$this->assertSame( DesignCheck::TEMPLATE_OUTDATED, self::mismatch( array( 'templateVersion' => null ) ) );
	}

	public function test_refuses_another_template(): void {
		$this->assertSame( DesignCheck::TEMPLATE, self::mismatch( array( 'templateBaseId' => 'mug' ) ) );
	}

	public function test_refuses_another_blank_or_placement(): void {
		$this->assertSame( DesignCheck::BLANK, self::mismatch( array( 'blankId' => 'blank_hoodie' ) ) );
		$this->assertSame( DesignCheck::BLANK, self::mismatch( array( 'blankId' => null ) ) );
		$this->assertSame( DesignCheck::PLACEMENT, self::mismatch( array( 'placementId' => 'back' ) ) );
	}

	public function test_a_template_only_product_wants_neither_blank_nor_placement(): void {
		$side   = new Side( 'design', 'Design', null, null, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, null, null, self::side()->print_sizes );
		$design = Design::from_answer(
			DesignFixture::design(
				array(
					'blankId'     => null,
					'placementId' => null,
				)
			)
		);

		$this->assertNull( DesignCheck::mismatch( $design, $side, null ) );
		$this->assertSame( DesignCheck::BLANK, DesignCheck::mismatch( Design::from_answer( DesignFixture::design() ), $side, null ) );
	}
}
