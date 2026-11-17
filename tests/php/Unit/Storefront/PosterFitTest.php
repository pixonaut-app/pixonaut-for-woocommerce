<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Pixonaut\WooCommerce\Storefront\PosterFit;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * The rule that lets a side's poster cover a box of nearly its shape.
 */
final class PosterFitTest extends TestCase {

	public function test_a_poster_covers_a_box_within_a_fraction_of_its_shape(): void {
		$style = PosterFit::style( 'front', 825, 1024 );

		$this->assertSame(
			'<style>@container pixonaut-poster (aspect-ratio >= 0.80405) and (aspect-ratio <= 0.80728){#pixonaut-preview-front .pixonaut-preview__poster{object-fit:cover}}</style>',
			$style
		);
	}

	public function test_a_poster_of_no_size_or_a_side_no_selector_can_name_has_none(): void {
		$this->assertSame( '', PosterFit::style( 'front', 0, 1024 ) );
		$this->assertSame( '', PosterFit::style( 'front', 825, 0 ) );
		$this->assertSame( '', PosterFit::style( 'front"]{}', 825, 1024 ) );
	}
}
