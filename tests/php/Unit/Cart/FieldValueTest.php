<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Cart;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class FieldValueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'esc_html' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES ) );
	}

	/**
	 * @param string $label The label.
	 * @param string $type  The type.
	 * @param string $value The value.
	 *
	 * @return array{label: string, type: string, value: string}
	 */
	private static function entry( string $label, string $type, string $value ): array {
		return array(
			'label' => $label,
			'type'  => $type,
			'value' => $value,
		);
	}

	public function test_a_posted_list_keeps_each_field_with_a_value(): void {
		$fields = FieldValue::list_from_json(
			(string) wp_json_encode(
				array(
					self::entry( ' Name ', FieldValue::TEXT, "Anna\r\nAndersson\u{0007}" ),
					self::entry( 'Photo', FieldValue::IMAGE, 'https://example.org/photo.jpg' ),
					self::entry( 'Empty', FieldValue::TEXT, '  ' ),
					self::entry( 'Crop', 'crop', 'x' ),
					array( 'label' => 'Broken' ),
					'not an entry',
				)
			)
		);

		$this->assertEquals(
			array(
				new FieldValue( 'Name', FieldValue::TEXT, "Anna\nAndersson" ),
				new FieldValue( 'Photo', FieldValue::IMAGE, '' ),
			),
			$fields
		);
	}

	public function test_anything_but_a_json_list_has_no_fields(): void {
		$this->assertSame( array(), FieldValue::list_from_json( '{nope' ) );
		$this->assertSame( array(), FieldValue::list_from_json( array( 'x' ) ) );
		$this->assertSame( array(), FieldValue::list_from_json( '"text"' ) );
	}

	public function test_a_side_keeps_at_most_the_most_fields_and_long_text_cut(): void {
		$entries = array_fill( 0, FieldValue::MAX_FIELDS + 1, self::entry( str_repeat( 'l', FieldValue::MAX_LABEL_LENGTH + 1 ), FieldValue::TEXT, str_repeat( 'å', FieldValue::MAX_VALUE_LENGTH + 1 ) ) );

		$fields = FieldValue::list_from( $entries );

		$this->assertCount( FieldValue::MAX_FIELDS, $fields );
		$this->assertSame( FieldValue::MAX_LABEL_LENGTH, mb_strlen( $fields[0]->label ) );
		$this->assertSame( FieldValue::MAX_VALUE_LENGTH, mb_strlen( $fields[0]->value ) );
	}

	public function test_a_field_shows_by_its_label_or_its_types(): void {
		$labels = new Labels();

		$this->assertSame( 'Name', ( new FieldValue( 'Name', FieldValue::TEXT, 'Anna' ) )->shown_label( $labels, array() ) );
		$this->assertSame( 'Colour', ( new FieldValue( '', FieldValue::COLOR, '#ff0000' ) )->shown_label( $labels, array() ) );
	}

	public function test_a_picture_shows_as_image_and_text_in_full_escaped(): void {
		$labels = new Labels();
		$long   = str_repeat( 'x', FieldValue::MAX_VALUE_LENGTH - 20 );

		$this->assertSame( 'Image', ( new FieldValue( 'Photo', FieldValue::IMAGE, '' ) )->shown_value( $labels, array() ) );
		$this->assertSame( '&lt;b&gt;Anna&lt;/b&gt;<br>' . "\n" . $long, ( new FieldValue( 'Name', FieldValue::TEXT, "<b>Anna</b>\n" . $long ) )->shown_html( $labels, array() ) );
	}
}
