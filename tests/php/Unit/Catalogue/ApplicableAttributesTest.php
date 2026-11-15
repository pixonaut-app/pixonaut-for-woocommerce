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
}
