<?php
/**
 * Tests for the amounts a product's attribute choices show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Pixonaut\WooCommerce\Storefront\ChoicePriceTable;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * The cases are shared with the storefront script's tests
 * (`choice-prices.test.ts`), so the server and the browser answer alike.
 */
final class ChoicePriceTableTest extends TestCase {

	private const CASES = __DIR__ . '/../../../fixtures/choice-prices.json';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A fixture of this repository, on disk.
		$shared = json_decode( (string) file_get_contents( self::CASES ), true, 512, JSON_THROW_ON_ERROR );
		$cases  = array();
		foreach ( $shared['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $shared['products'][ $case['product'] ], $case['chosen'], $case['amounts'] );
		}
		return $cases;
	}

	/**
	 * @dataProvider cases
	 *
	 * @param array<string, mixed>  $product The product's fields, roles, decimals and variations.
	 * @param array<string, string> $chosen  The choice.
	 * @param array<string, mixed>  $amounts The amounts each field's values show.
	 */
	public function test_shows_the_amounts_the_shared_cases_expect( array $product, array $chosen, array $amounts ): void {
		$variations = array();
		foreach ( $product['variations'] as $variation ) {
			$variations[] = array(
				'values' => array_map( 'strval', $variation['values'] ),
				'price'  => (float) $variation['price'],
			);
		}
		$this->assertEquals( $amounts, ChoicePriceTable::amounts( $product['fields'], $product['roles'], $variations, $chosen, $product['decimals'] ) );
	}
}
