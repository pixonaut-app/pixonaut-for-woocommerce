<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Pricing;

use Pixonaut\WooCommerce\Pricing\ItemRule;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\RuleSource;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ItemRuleTest extends TestCase {

	private const FRAME_TERM = 11;

	private const SIZE_TERM = 22;

	public function test_each_part_comes_from_the_most_specific_level_that_sets_it(): void {
		$rule = ItemRule::resolve(
			array(
				array( self::variation(), array( PriceRule::MAX => '1999' ) ),
				array( self::product(), array( PriceRule::STEP => '50' ) ),
				array( self::frame(), array( PriceRule::MARGIN => '30' ) ),
				array(
					self::store(),
					array(
						PriceRule::MARGIN => '50',
						PriceRule::STEP   => '10',
						PriceRule::ENDING => '9',
						PriceRule::MAX    => '999',
					),
				),
			)
		);

		$this->assertSame( '30', $rule->parts[ PriceRule::MARGIN ], 'the attribute value over the store' );
		$this->assertSame( '50', $rule->parts[ PriceRule::STEP ], 'the product over the store' );
		$this->assertSame( '1999', $rule->parts[ PriceRule::MAX ], 'the variation over the store' );
		$this->assertSame( '9', $rule->parts[ PriceRule::ENDING ], 'the store where nothing else sets it' );
		$this->assertSame( '', $rule->parts[ PriceRule::FIXED ], 'no level sets it' );
		$this->assertSame( RuleSource::ATTRIBUTE, $rule->sources[ PriceRule::MARGIN ]->level );
		$this->assertSame( RuleSource::PRODUCT, $rule->sources[ PriceRule::STEP ]->level );
		$this->assertSame( RuleSource::VARIATION, $rule->sources[ PriceRule::MAX ]->level );
		$this->assertSame( RuleSource::STORE, $rule->sources[ PriceRule::ENDING ]->level );
		$this->assertArrayNotHasKey( PriceRule::FIXED, $rule->sources );
		$this->assertSame( 30.0, $rule->rule->margin );
		$this->assertSame( array( RuleSource::VARIATION, RuleSource::PRODUCT, RuleSource::ATTRIBUTE, RuleSource::STORE ), array_map( static fn ( RuleSource $source ): string => $source->level, $rule->levels() ) );
	}

	public function test_the_variation_wins_over_the_product_over_the_attribute_value_over_the_store_part_by_part(): void {
		foreach ( PriceRule::PARTS as $part ) {
			$layers = array(
				array( self::variation(), array( $part => '4' ) ),
				array( self::product(), array( $part => '3' ) ),
				array( self::frame(), array( $part => '2' ) ),
				array( self::store(), array( $part => '1' ) ),
			);
			$levels = count( $layers );
			for ( $level = 0; $level < $levels; $level++ ) {
				$rule = ItemRule::resolve( array_slice( $layers, $level ) );
				$this->assertSame( (string) ( $levels - $level ), $rule->parts[ $part ], $part . ' from ' . $layers[ $level ][0]->level );
				$this->assertSame( $layers[ $level ][0]->level, $rule->sources[ $part ]->level );
			}
		}
	}

	public function test_an_empty_part_follows_the_level_after_it(): void {
		$rule = ItemRule::resolve(
			array(
				array( self::variation(), array( PriceRule::MARGIN => '' ) ),
				array( self::product(), array( PriceRule::MARGIN => ' ' ) ),
				array( self::store(), array( PriceRule::MARGIN => '50' ) ),
			)
		);

		$this->assertSame( '50', $rule->parts[ PriceRule::MARGIN ] );
		$this->assertSame( RuleSource::STORE, $rule->sources[ PriceRule::MARGIN ]->level );
	}

	public function test_of_two_attribute_values_setting_a_part_the_first_in_the_stores_order_wins(): void {
		$rule = ItemRule::resolve(
			array(
				array( self::product(), array() ),
				array(
					self::frame(),
					array(
						PriceRule::FIXED  => '200',
						PriceRule::MARGIN => '30',
					),
				),
				array( self::size(), array( PriceRule::FIXED => '400' ) ),
				array( self::store(), array( PriceRule::MARGIN => '50' ) ),
			)
		);

		$this->assertSame( '200', $rule->parts[ PriceRule::FIXED ] );
		$this->assertSame( self::FRAME_TERM, $rule->sources[ PriceRule::FIXED ]->term_id );
		$this->assertCount( 1, $rule->conflicts );
		$this->assertSame( PriceRule::FIXED, $rule->conflicts[0]['part'] );
		$this->assertSame( self::FRAME_TERM, $rule->conflicts[0]['winner']->term_id );
		$this->assertSame( self::SIZE_TERM, $rule->conflicts[0]['loser']->term_id );
	}

	public function test_a_part_the_variation_or_product_sets_is_no_conflict_of_its_attribute_values(): void {
		$rule = ItemRule::resolve(
			array(
				array( self::variation(), array( PriceRule::FIXED => '100' ) ),
				array( self::product(), array() ),
				array( self::frame(), array( PriceRule::FIXED => '200' ) ),
				array( self::size(), array( PriceRule::FIXED => '400' ) ),
				array( self::store(), array() ),
			)
		);

		$this->assertSame( '100', $rule->parts[ PriceRule::FIXED ] );
		$this->assertSame( array(), $rule->conflicts );
	}

	public function test_without_a_margin_at_any_level_there_is_no_rule(): void {
		$rule = ItemRule::resolve(
			array(
				array( self::frame(), array( PriceRule::FIXED => '200' ) ),
				array( self::store(), array() ),
			)
		);

		$this->assertFalse( $rule->rule->is_set() );
	}

	private static function variation(): RuleSource {
		return new RuleSource( RuleSource::VARIATION );
	}

	private static function product(): RuleSource {
		return new RuleSource( RuleSource::PRODUCT );
	}

	private static function store(): RuleSource {
		return new RuleSource( RuleSource::STORE );
	}

	private static function frame(): RuleSource {
		return new RuleSource( RuleSource::ATTRIBUTE, self::FRAME_TERM, 'pa_frame', 'Frame', 'Ram', 'ram' );
	}

	private static function size(): RuleSource {
		return new RuleSource( RuleSource::ATTRIBUTE, self::SIZE_TERM, 'pa_size', 'Size', '70×100 cm', '70x100' );
	}
}
