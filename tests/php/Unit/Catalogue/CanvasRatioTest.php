<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class CanvasRatioTest extends TestCase {

	public function test_each_choice_is_the_value_it_posts(): void {
		foreach ( CanvasRatio::CHOICES as $choice ) {
			$this->assertSame( $choice, CanvasRatio::posted( CanvasRatio::from_posted( $choice ) ) );
		}
		$this->assertTrue( CanvasRatio::from_posted( CanvasRatio::TEMPLATE ) );
		$this->assertFalse( CanvasRatio::from_posted( CanvasRatio::PRINT_SIZE ) );
		$this->assertNull( CanvasRatio::from_posted( CanvasRatio::FOLLOW ) );
	}

	public function test_anything_else_follows_the_template(): void {
		$this->assertNull( CanvasRatio::from_posted( 'square' ) );
		$this->assertNull( CanvasRatio::from_posted( null ) );
		$this->assertNull( CanvasRatio::from_stored( 'true' ) );
		$this->assertNull( CanvasRatio::from_stored( 1 ) );
		$this->assertTrue( CanvasRatio::from_stored( true ) );
	}

	public function test_names_each_choice_with_what_following_follows(): void {
		Functions\stubTranslationFunctions();

		$names = CanvasRatio::names( 'As the product says' );

		$this->assertSame( CanvasRatio::CHOICES, array_keys( $names ) );
		$this->assertSame( 'As the product says', $names[ CanvasRatio::FOLLOW ] );
	}
}
