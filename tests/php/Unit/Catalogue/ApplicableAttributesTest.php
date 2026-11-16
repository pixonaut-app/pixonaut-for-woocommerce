<?php
/**
 * Tests for the rule hiding the attributes that do not apply to a choice.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * The cases are shared with the storefront script's tests
 * (`applicable-attributes.test.ts`), so the server and the browser answer
 * alike.
 */
final class ApplicableAttributesTest extends TestCase {

	private const CASES = __DIR__ . '/../../../fixtures/applicable-attributes.json';

	/**
	 * @return array<string, array{0: list<string>, 1: list<array<string, string>>, 2: array<string, string>, 3: list<string>}>
	 */
	public static function cases(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A fixture of this repository, on disk.
		$shared = json_decode( (string) file_get_contents( self::CASES ), true, 512, JSON_THROW_ON_ERROR );
		$cases  = array();
		foreach ( $shared['cases'] as $case ) {
			$product                = $shared['products'][ $case['product'] ];
			$cases[ $case['name'] ] = array( $product['fields'], $product['variations'], $case['chosen'], $case['hidden'] );
		}
		return $cases;
	}

	/**
	 * @dataProvider cases
	 *
	 * @param string[]                    $fields     The product's fields.
	 * @param list<array<string, string>> $variations Its variations' values.
	 * @param array<string, string>       $chosen     The choice.
	 * @param string[]                    $hidden     The fields it hides.
	 * @phpstan-param list<string> $fields
	 * @phpstan-param list<string> $hidden
	 */
	public function test_hides_the_attributes_no_matching_variation_sets( array $fields, array $variations, array $chosen, array $hidden ): void {
		$this->assertSame( $hidden, ApplicableAttributes::hidden( $fields, $variations, $chosen ) );
	}

	public function test_only_an_attribute_some_variations_set_and_others_leave_unset_can_be_hidden(): void {
		$fields     = array( 'attribute_all', 'attribute_some', 'attribute_none' );
		$variations = array(
			array(
				'attribute_all'  => 'a',
				'attribute_some' => 'x',
				'attribute_none' => '',
			),
			array( 'attribute_all' => 'b' ),
		);

		$this->assertSame( array( 'attribute_some' ), ApplicableAttributes::dependent( $fields, $variations ) );
	}

	public function test_a_variation_matches_a_value_it_holds_or_leaves_unset(): void {
		$variation = array(
			'attribute_a' => 'x',
			'attribute_b' => '',
		);

		$this->assertTrue( ApplicableAttributes::matches( $variation, array() ) );
		$this->assertTrue(
			ApplicableAttributes::matches(
				$variation,
				array(
					'attribute_a' => 'x',
					'attribute_b' => 'any',
					'attribute_c' => 'missing',
				)
			)
		);
		$this->assertTrue( ApplicableAttributes::matches( $variation, array( 'attribute_a' => '' ) ) );
		$this->assertFalse( ApplicableAttributes::matches( $variation, array( 'attribute_a' => 'y' ) ) );
	}

	public function test_an_attribute_depends_on_the_attribute_whose_value_decides_whether_it_applies(): void {
		$fields     = array( 'attribute_pa_storlek', 'attribute_pa_inramning', 'attribute_pa_ramfarg' );
		$variations = array(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ingen',
			),
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			array(
				'attribute_pa_storlek'   => '50x70',
				'attribute_pa_inramning' => 'hangare',
				'attribute_pa_ramfarg'   => 'svart',
			),
			array(
				'attribute_pa_storlek'   => '50x70',
				'attribute_pa_inramning' => 'ingen',
			),
		);

		$this->assertSame(
			array(
				'attribute_pa_ramfarg' => array(
					'field'  => 'attribute_pa_inramning',
					'values' => array( 'ram', 'hangare' ),
				),
			),
			ApplicableAttributes::parents( $fields, $variations ),
			'the size does not decide it: both sizes come with and without a frame'
		);
	}

	public function test_of_several_deciding_attributes_the_nearest_before_it_is_its_parent(): void {
		$variations = array(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ingen',
			),
			array(
				'attribute_pa_storlek'   => '50x70',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
		);

		$this->assertSame( 'attribute_pa_inramning', ApplicableAttributes::parents( array( 'attribute_pa_storlek', 'attribute_pa_inramning', 'attribute_pa_ramfarg' ), $variations )['attribute_pa_ramfarg']['field'] ?? null );
		$this->assertSame( 'attribute_pa_storlek', ApplicableAttributes::parents( array( 'attribute_pa_inramning', 'attribute_pa_storlek', 'attribute_pa_ramfarg' ), $variations )['attribute_pa_ramfarg']['field'] ?? null );
		$this->assertSame( 'attribute_pa_inramning', ApplicableAttributes::parents( array( 'attribute_pa_ramfarg', 'attribute_pa_inramning', 'attribute_pa_storlek' ), $variations )['attribute_pa_ramfarg']['field'] ?? null, 'after it when none comes before' );
	}

	public function test_an_attribute_left_open_where_it_applies_still_depends_on_its_parent(): void {
		$fields     = array( 'attribute_pa_inramning', 'attribute_pa_ramfarg' );
		$variations = array(
			array( 'attribute_pa_inramning' => 'ingen' ),
			array(
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			array(
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => '',
			),
		);

		$this->assertSame( array( 'ram' ), ApplicableAttributes::parents( $fields, $variations )['attribute_pa_ramfarg']['values'] ?? null );
	}

	public function test_an_attribute_no_single_attribute_decides_has_no_parent(): void {
		$fields     = array( 'attribute_pa_inramning', 'attribute_pa_ramfarg' );
		$variations = array(
			array( 'attribute_pa_inramning' => 'ram' ),
			array(
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			array( 'attribute_pa_inramning' => '' ),
		);
		$this->assertSame( array(), ApplicableAttributes::parents( $fields, $variations ), 'a variation open on the framing' );
		$this->assertSame(
			array(),
			ApplicableAttributes::parents(
				array( 'attribute_pa_size', 'attribute_pa_colour' ),
				array(
					array( 'attribute_pa_size' => 's' ),
					array( 'attribute_pa_size' => 'm' ),
				)
			),
			'a free choice depends on nothing'
		);
	}
}
