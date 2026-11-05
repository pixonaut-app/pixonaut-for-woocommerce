<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;

final class CanvasRatioPanelTest extends ProductPanelTestCase {

	private const LEGAL = array( 'legal' => true );

	/**
	 * A version of the tee template published after the one the product was saved on.
	 */
	private const PUBLISHED_VERSION = 3;

	private const CANVAS_RATIO_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::CANVAS_RATIO;

	public function test_the_product_and_each_side_save_their_canvas_ratio(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );

		$this->save( $product->get_id(), array( self::CANVAS_RATIO_FIELD => CanvasRatio::TEMPLATE ), array( PostedSides::CANVAS_RATIO => CanvasRatio::PRINT_SIZE ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertTrue( $config->draw_at_template_ratio );
		$this->assertFalse( $config->sides[0]->draw_at_template_ratio );
	}

	public function test_following_the_template_stores_no_choice(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id(), array( self::CANVAS_RATIO_FIELD => CanvasRatio::TEMPLATE ), array( PostedSides::CANVAS_RATIO => CanvasRatio::TEMPLATE ) );
		$this->script_link( self::LEGAL );

		$this->save( $product->get_id(), array( self::CANVAS_RATIO_FIELD => CanvasRatio::FOLLOW ), array( PostedSides::CANVAS_RATIO => CanvasRatio::FOLLOW ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertNull( $config->draw_at_template_ratio );
		$this->assertNull( $config->sides[0]->draw_at_template_ratio );
	}

	public function test_a_side_keeps_its_canvas_ratio_when_its_template_is_checked_again(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id(), array(), array( PostedSides::CANVAS_RATIO => CanvasRatio::TEMPLATE ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );

		$rechecked = $this->linker->recheck( ProductConfig::stored_on( $this->reload( $product->get_id() ) ), array( 'mini-tee' => self::PUBLISHED_VERSION ) );

		$this->assertTrue( $rechecked->valid );
		$this->assertSame( self::PUBLISHED_VERSION, $rechecked->sides[0]->template_version );
		$this->assertTrue( $rechecked->sides[0]->draw_at_template_ratio );
	}

	public function test_the_fields_show_the_choices_saved(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id(), array( self::CANVAS_RATIO_FIELD => CanvasRatio::PRINT_SIZE ), array( PostedSides::CANVAS_RATIO => CanvasRatio::TEMPLATE ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->panel->fields_html( $this->panel->panel( $this->reload( $product->get_id() ) ) );

		$this->assertMatchesRegularExpression( '/name="' . self::CANVAS_RATIO_FIELD . '"[^>]*>(?:(?!<\/select>).)*?<option value="' . CanvasRatio::PRINT_SIZE . '"\s+selected=.selected./s', $html );
		$this->assertMatchesRegularExpression( '/\[0\]\[' . PostedSides::CANVAS_RATIO . '\]"[^>]*>(?:(?!<\/select>).)*?<option value="' . CanvasRatio::TEMPLATE . '"\s+selected=.selected./s', $html );
	}
}
