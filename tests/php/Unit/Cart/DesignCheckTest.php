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
use Pixonaut\WooCommerce\Catalogue\TemplateRenames;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class DesignCheckTest extends TestCase {

	use FakeOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
	}

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

	public function test_accepts_a_design_on_the_template_s_new_id_once_the_rename_is_known(): void {
		$moved = array( 'templateBaseId' => 'mini-tee-pandas' );
		$this->assertSame( DesignCheck::TEMPLATE, self::mismatch( $moved ) );

		$this->options[ TemplateRenames::OPTION ] = array(
			array(
				'from' => DesignFixture::TEMPLATE_BASE_ID,
				'to'   => 'mini-tee-pandas',
				'at'   => 1,
			),
		);

		$this->assertNull( self::mismatch( $moved ) );
		$this->assertNull( self::mismatch( array() ), 'A design still naming the old id is the same template.' );
		$this->assertSame( DesignCheck::TEMPLATE, self::mismatch( array( 'templateBaseId' => 'mug' ) ) );
	}

	/**
	 * @dataProvider pages
	 *
	 * @param int|null $page       The design's page.
	 * @param int|null $page_count The design's page count.
	 * @param int|null $slot_page         The page the line holds it as.
	 * @param int      $held       The pages the line holds.
	 * @param int|null $sold       The pages sold.
	 * @param bool     $matches    Whether it is what the line holds.
	 */
	public function test_a_design_is_the_page_the_line_holds_of_the_book_sold( ?int $page, ?int $page_count, ?int $slot_page, int $held, ?int $sold, bool $matches ): void {
		$design = Design::from_answer(
			DesignFixture::design(
				array(
					'page'      => $page,
					'pageCount' => $page_count,
				)
			)
		);

		$this->assertSame( $matches, DesignCheck::pages_match( $design, $slot_page, $held, $sold ) );
	}

	/**
	 * @return array<string, array{int|null, int|null, int|null, int, int|null, bool}>
	 */
	public static function pages(): array {
		return array(
			'no pages at all'                => array( null, null, null, 0, null, true ),
			'a product sold at no count'     => array( 2, 44, 2, 44, null, true ),
			'the count sold'                 => array( 2, 44, 2, 44, 44, true ),
			'another count than sold'        => array( 2, 60, 2, 60, 44, false ),
			'a page of a longer book'        => array( 2, 60, 2, 44, 44, false ),
			'another page'                   => array( 3, 44, 2, 44, 44, false ),
			'one page alone of a book'       => array( 1, 44, null, 0, 44, false ),
			'a whole design as a page'       => array( null, null, 1, 44, 44, false ),
			'a cover made for the count'     => array( null, 44, null, 0, 44, true ),
			'a cover made for another count' => array( null, 60, null, 0, 44, false ),
			'a side proofed for no count'    => array( null, null, null, 0, 44, true ),
		);
	}

	public function test_designs_are_made_for_the_count_sold_else_the_one_the_book_holds(): void {
		$this->assertSame( 44, DesignCheck::book_count( 44, array( 0, 60 ) ) );
		$this->assertSame( 60, DesignCheck::book_count( null, array( 0, 60, 60 ) ) );
		$this->assertNull( DesignCheck::book_count( null, array( 44, 60 ) ) );
		$this->assertNull( DesignCheck::book_count( null, array( 0 ) ) );
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
