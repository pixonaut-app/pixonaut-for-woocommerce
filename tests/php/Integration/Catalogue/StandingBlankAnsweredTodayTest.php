<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;

/**
 * A standing side on a blank whose print areas change with Size, the blank
 * answered as Pixonaut answers it today: the side reads the blank's areas
 * for each row from a blank without a `photoSource`.
 */
final class StandingBlankAnsweredTodayTest extends StandingTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->blank_answered_today = true;
	}

	public function test_a_standing_side_gets_a_design_per_row_of_a_blank_without_a_photo_source(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ) );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertSame(
			array( array( 'S', 'des_row_s' ), array( 'M', 'des_row_m' ) ),
			array_map( static fn ( StandingRow $row ): array => array( $row->label(), $row->design_id ), $config->sides[0]->standing_rows )
		);
	}
}
