<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Connection;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreTextTolerance;
use Pixonaut\WooCommerce\Connection\PrintFileForm;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class PrintFileFormTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'number_format_i18n' )->alias( static fn ( float $number, int $decimals = 0 ): string => number_format( $number, $decimals ) );
	}

	public function test_each_form_is_sent_as_chosen_and_empty_follows_the_workspace(): void {
		$this->assertSame(
			array(
				'printFileForm'          => 'flat',
				'printFileTextTolerance' => null,
			),
			PrintFileForm::request( 'flat', '', 'px' )->toArray()
		);
		$this->assertSame(
			array(
				'printFileForm'          => 'layered',
				'printFileTextTolerance' => null,
			),
			PrintFileForm::request( 'layered', '', 'px' )->toArray()
		);
		$this->assertSame(
			array(
				'printFileForm'          => null,
				'printFileTextTolerance' => null,
			),
			PrintFileForm::request( '', '', 'px' )->toArray()
		);
	}

	public function test_live_text_sends_its_tolerance_in_either_unit(): void {
		$this->assertSame(
			array(
				'printFileForm'          => 'layered_live_text',
				'printFileTextTolerance' => array(
					'unit'  => 'px',
					'value' => 2.0,
				),
			),
			PrintFileForm::request( 'layered_live_text', '2', 'px' )->toArray()
		);
		$this->assertSame(
			array(
				'unit'  => 'mm',
				'value' => 0.25,
			),
			PrintFileForm::request( 'layered_live_text', '0,25', 'mm' )->toArray()['printFileTextTolerance']
		);
		$this->assertNull( PrintFileForm::request( 'layered_live_text', '', 'mm' )->toArray()['printFileTextTolerance'] );
	}

	public function test_a_tolerance_goes_with_live_text_only(): void {
		$this->assertNull( PrintFileForm::request( 'layered', '5', 'px' )->toArray()['printFileTextTolerance'] );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function refused(): array {
		return array(
			'unknown form'      => array( 'vector', '', 'px' ),
			'under a pixel'     => array( 'layered_live_text', '0.5', 'px' ),
			'over 100 pixels'   => array( 'layered_live_text', '101', 'px' ),
			'under the mm step' => array( 'layered_live_text', '0.001', 'mm' ),
			'over 5 mm'         => array( 'layered_live_text', '5.5', 'mm' ),
			'not a number'      => array( 'layered_live_text', 'wide', 'px' ),
			'unknown unit'      => array( 'layered_live_text', '1', 'pt' ),
		);
	}

	/**
	 * @dataProvider refused
	 *
	 * @param string $form  The form posted.
	 * @param string $value The tolerance posted.
	 * @param string $unit  Its unit.
	 */
	public function test_a_form_or_tolerance_pixonaut_does_not_take_is_refused( string $form, string $value, string $unit ): void {
		$this->expectException( InvalidArgumentException::class );

		PrintFileForm::request( $form, $value, $unit );
	}

	public function test_names_a_form_and_a_tolerance_for_the_merchant(): void {
		$this->assertSame( 'Layers with live text', PrintFileForm::name( 'layered_live_text' ) );
		$this->assertSame( 'engraved', PrintFileForm::name( 'engraved' ) );
		$this->assertSame( '1 px', PrintFileForm::tolerance_name( new ConnectedStoreTextTolerance( 'px', 1.0 ) ) );
		$this->assertSame( '0.5 mm', PrintFileForm::tolerance_name( new ConnectedStoreTextTolerance( 'mm', 0.5 ) ) );
		$this->assertSame( '0.25 mm', PrintFileForm::tolerance_name( new ConnectedStoreTextTolerance( 'mm', 0.25 ) ) );
		$this->assertSame( '1 to 100 px, or 0.01 to 5 mm', PrintFileForm::tolerance_limits() );
	}
}
