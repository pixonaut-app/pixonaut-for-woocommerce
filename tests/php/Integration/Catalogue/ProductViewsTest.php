<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;

final class ProductViewsTest extends ProductPanelTestCase {

	private const BACK_ID = 'back';

	private const VIEWS_FIELD = 'pixonaut_views';

	public function test_the_product_is_shown_in_the_views_ticked_each_once(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front', 'view-back', 'view-front', '' ) ) ) );

		$this->assertSame( array( 'view-front', 'view-back' ), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->views );
	}

	public function test_no_view_ticked_shows_every_view(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front' ) ) ) );
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array() ) ) );

		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->views );
	}

	public function test_views_not_listed_keep_the_views_chosen_before(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front' ) ) ) );
		$this->script_link( array( 'legal' => true ) );

		// Pixonaut could not list them, so the panel posted none.
		$this->save( $product->get_id() );

		$this->assertSame( array( 'view-front' ), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->views );
	}

	public function test_views_ticked_under_another_blank_are_not_the_new_blanks(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front' ) ) ) );
		$this->script_link( array( 'legal' => true ) );

		$this->save(
			$product->get_id(),
			array(
				'pixonaut_blank'  => 'blank_other',
				self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front' ) ),
			)
		);

		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->views );
	}

	public function test_the_designer_starts_on_the_view_chosen_among_those_ticked(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front', 'view-back' ), 'view-back' ) ) );

		$this->assertSame( 'view-back', ProductConfig::stored_on( $this->reload( $product->get_id() ) )->initial_view );
	}

	public function test_a_starting_view_not_ticked_starts_on_the_first(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front' ), 'view-back' ) ) );

		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->initial_view );
	}

	public function test_with_no_view_ticked_any_view_can_start(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array(), 'view-back' ) ) );

		$this->assertSame( 'view-back', ProductConfig::stored_on( $this->reload( $product->get_id() ) )->initial_view );
	}

	public function test_another_blank_drops_the_starting_view(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array(), 'view-back' ) ) );
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( 'pixonaut_blank' => 'blank_other' ) );

		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->initial_view );
	}

	public function test_the_panel_lists_the_blanks_views_by_placement_with_the_chosen_ones_ticked(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-back' ), 'view-back' ) ) );
		$placements = array(
			self::PLACEMENT_ID => 'Front',
			self::BACK_ID      => 'Back',
		);
		$this->recorder->json( 200, $this->blank( $placements ) );
		$this->recorder->json( 200, $this->mockups( $placements ) );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->render( $product->get_id() );

		$this->assertStringContainsString( 'class="pixonaut-with-blank pixonaut-views" data-blank="' . self::BLANK_ID . '"', $html );
		$this->assertStringContainsString( 'name="' . self::VIEWS_FIELD . '[' . ProductPanel::VIEWS_BLANK_KEY . ']" value="' . self::BLANK_ID . '"', $html );
		$this->assertStringContainsString( '<legend>Back print</legend>', $html );
		$this->assertMatchesRegularExpression( '#name="pixonaut_views\[ticked\]\[\]" value="view-front"\s+/>#', $html );
		$this->assertMatchesRegularExpression( '#name="pixonaut_views\[ticked\]\[\]" value="view-back"\s+checked=\'checked\'#', $html );
		$this->assertStringContainsString( 'src="' . self::BASE_URL . '/i/thumb-front"', $html );
		$this->assertStringContainsString( 'name="' . self::VIEWS_FIELD . '[' . ProductPanel::VIEWS_INITIAL_KEY . ']" class="pixonaut-initial-view"', $html );
		$this->assertMatchesRegularExpression( '#<option value="view-back"\s+selected=\'selected\'>#', $html );
		// Pixonaut's Design view follows the blank's, to tick and to start on like them.
		$this->assertMatchesRegularExpression( '#name="pixonaut_views\[ticked\]\[\]" value="design"\s+/>#', $html );
		$this->assertMatchesRegularExpression( '#<option value="design"\s+>Design</option>#', $html );
	}

	public function test_the_view_the_designer_opens_on_is_badged_and_named_in_the_summary(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front', 'view-back' ), 'view-back' ) ) );
		$placements = array(
			self::PLACEMENT_ID => 'Front',
			self::BACK_ID      => 'Back',
		);
		$this->recorder->json( 200, $this->blank( $placements ) );
		$this->recorder->json( 200, $this->mockups( $placements ) );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->render( $product->get_id() );

		$this->assertMatchesRegularExpression( self::badged( 'view-back' ), $html );
		$this->assertDoesNotMatchRegularExpression( self::badged( 'view-front' ), $html );
	}

	public function test_an_opening_view_the_blank_no_longer_has_badges_the_first_view(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array(), 'view-back' ) ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->render( $product->get_id() );

		$this->assertMatchesRegularExpression( self::badged( 'view-front' ), $html );
		$this->assertSame( 1, preg_match_all( '#class="pixonaut-view__opens"\s*>#', $html ) );
	}

	/**
	 * Matches a view's tile carrying the Opens first badge, shown.
	 *
	 * @param string $view The view's id.
	 */
	private static function badged( string $view ): string {
		return '#class="pixonaut-view__opens"\s*>[^<]*</span>\s*</span>\s*<span class="pixonaut-view__name">\s*<input type="checkbox" name="pixonaut_views\[ticked\]\[\]" value="' . preg_quote( $view, '#' ) . '"#';
	}

	public function test_the_design_view_can_be_ticked_and_started_on(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id(), array( self::VIEWS_FIELD => self::views( self::BLANK_ID, array( 'view-front', ProductConfig::DESIGN_VIEW ), ProductConfig::DESIGN_VIEW ) ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( array( 'view-front', ProductConfig::DESIGN_VIEW ), $config->views );
		$this->assertSame( ProductConfig::DESIGN_VIEW, $config->initial_view );
	}

	public function test_the_panel_says_when_pixonaut_cannot_list_the_views(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->render( $product->get_id() );

		$this->assertStringContainsString( 'could not be reached to list the blank’s views', $html );
		$this->assertStringNotContainsString( 'name="' . self::VIEWS_FIELD . '[', $html );
	}

	/**
	 * The views field as the panel posts it.
	 *
	 * @param string   $blank_id The blank the views were listed for.
	 * @param string[] $ticked   The ids ticked.
	 * @param string   $initial  The starting view; empty for the first.
	 * @return array<string, mixed>
	 */
	private static function views( string $blank_id, array $ticked, string $initial = '' ): array {
		return array(
			ProductPanel::VIEWS_BLANK_KEY   => $blank_id,
			ProductPanel::VIEWS_TICKED_KEY  => $ticked,
			ProductPanel::VIEWS_INITIAL_KEY => $initial,
		);
	}

	/**
	 * The panel as the product screen prints it.
	 *
	 * @param int $product_id The product.
	 */
	private function render( int $product_id ): string {
		ob_start();
		$GLOBALS['product_object'] = $this->reload( $product_id );
		$this->panel->render();
		unset( $GLOBALS['product_object'] );
		return (string) ob_get_clean();
	}
}
