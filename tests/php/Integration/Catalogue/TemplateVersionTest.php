<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;

/**
 * A side follows its template's latest published version, or sticks to the
 * one the store manager picked.
 */
final class TemplateVersionTest extends ProductPanelTestCase {

	private const BASE_ID = 'mini-tee';

	/**
	 * `TEMPLATE_ID`'s version, the latest one the search lists.
	 */
	private const SAVED_VERSION = 2;

	private const PUBLISHED_VERSION = self::SAVED_VERSION + 1;

	private const FIRST_VERSION = 1;

	private const ARRANGEMENT = array(
		'groups' => array(
			array(
				'id'    => 'text-front',
				'title' => 'Your words',
				'items' => array( array( 'field' => 'slogan:content' ) ),
			),
		),
	);

	public function test_a_followed_side_moves_to_a_published_version_and_keeps_its_setup(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array(), array( ProductPanel::FORM_GROUPS_FIELD => wp_slash( (string) wp_json_encode( self::ARRANGEMENT ) ) ) );
		$saved = $this->side( $product->get_id() );
		$this->assertFalse( $saved->pinned );

		$this->recheck( $product->get_id(), self::PUBLISHED_VERSION );

		$side = $this->side( $product->get_id() );
		$this->assertSame( array( self::PUBLISHED_VERSION, self::SAVED_VERSION ), array( $side->template_version, $side->previous_version ) );
		$this->assertEquals( $saved->print_sizes, $side->print_sizes );
		$this->assertSame( self::ARRANGEMENT['groups'], $side->form_groups?->groups );
		$this->assertSame( array( $saved->start_size, $saved->mode ), array( $side->start_size, $side->mode ) );
	}

	public function test_a_side_stuck_to_a_version_stays_on_it_when_another_is_published(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array(), self::pinned( self::FIRST_VERSION ) );
		$saved = $this->side( $product->get_id() );
		$this->assertSame( array( self::FIRST_VERSION, true, self::SAVED_VERSION ), array( $saved->template_version, $saved->pinned, $saved->latest_version ) );
		$this->assertSame( self::BASE_ID . '@' . self::FIRST_VERSION . '/constraints', substr( $this->path( 1 ), strlen( '/v1/templates/' ) ) );

		$this->recheck( $product->get_id(), self::PUBLISHED_VERSION );

		$side = $this->side( $product->get_id() );
		$this->assertSame( array( self::FIRST_VERSION, true, self::PUBLISHED_VERSION ), array( $side->template_version, $side->pinned, $side->latest_version ) );
		$this->assertEquals( $saved->print_sizes, $side->print_sizes );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->valid );
	}

	public function test_the_panel_offers_every_version_and_marks_the_one_picked(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array(), self::pinned( self::FIRST_VERSION ) );
		$this->recheck( $product->get_id(), self::PUBLISHED_VERSION );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$html = $this->panel->fields_html( $this->panel->panel( $this->reload( $product->get_id() ) ) );

		$this->assertMatchesRegularExpression( '/class="pixonaut-side-pinned"[^>]*checked/', $html );
		// The template field lists the template at its latest version.
		$this->assertStringContainsString( '<option value="' . self::BASE_ID . '@' . self::PUBLISHED_VERSION . '" selected="selected">', $html );
		$this->assertSame( 1, preg_match( '/<select[^>]*class="pixonaut-side-version"[^>]*>(.*?)<\/select>/s', $html, $picker ) );
		preg_match_all( '/<option value="(\d+)"\s*(selected=\'selected\')?>\s*([^<]*?)\s*<\/option>/', $picker[1] ?? '', $options, PREG_SET_ORDER );
		$this->assertSame(
			array( array( '3', '', 'Version 3 (current)' ), array( '2', '', 'Version 2' ), array( '1', "selected='selected'", 'Version 1' ) ),
			array_map( static fn ( array $option ): array => array( $option[1], $option[2], $option[3] ), $options )
		);
	}

	public function test_a_form_opened_before_a_version_was_published_does_not_move_the_side_back(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		$this->recheck( $product->get_id(), self::PUBLISHED_VERSION );
		$this->script_link( array( 'legal' => true ) );

		// The form still names the version it was opened on.
		$this->save( $product->get_id() );

		$side = $this->side( $product->get_id() );
		$this->assertSame( array( self::PUBLISHED_VERSION, self::SAVED_VERSION, false ), array( $side->template_version, $side->previous_version, $side->pinned ) );
	}

	public function test_unsticking_a_side_moves_it_to_the_latest_version_and_still_accepts_the_one_it_left(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id(), array(), self::pinned( self::FIRST_VERSION ) );
		$this->recheck( $product->get_id(), self::PUBLISHED_VERSION );
		$this->script_link( array( 'legal' => true ) );

		// The template field names the latest version; the box is left unticked.
		$this->save( $product->get_id(), array(), array( 'template' => self::BASE_ID . '@' . self::PUBLISHED_VERSION ) );

		$side = $this->side( $product->get_id() );
		$this->assertSame( array( self::PUBLISHED_VERSION, self::FIRST_VERSION, false ), array( $side->template_version, $side->previous_version, $side->pinned ) );
	}

	/**
	 * A side's fields sticking to a version.
	 *
	 * @param int $version The version picked.
	 * @return array<string, string>
	 */
	private static function pinned( int $version ): array {
		return array(
			'pinned'  => PostedSides::PINNED,
			'version' => (string) $version,
		);
	}

	/**
	 * Re-checks the product as a published version does, against the
	 * template's live version: the blank, the placement's default size and
	 * its verdict.
	 *
	 * @param int $product_id The product.
	 * @param int $live       The template's live version.
	 */
	private function recheck( int $product_id, int $live ): void {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$product = $this->reload( $product_id );
		$this->linker->recheck( ProductConfig::stored_on( $product ), array( self::BASE_ID => $live ) )->save_to( $product );
	}

	private function side( int $product_id ): Side {
		return ProductConfig::stored_on( $this->reload( $product_id ) )->sides[0];
	}

	/**
	 * The path of a request made.
	 *
	 * @param int $index Which request, from 0.
	 */
	private function path( int $index ): string {
		return rawurldecode( (string) wp_parse_url( $this->recorder->requests[ $index ]['url'], PHP_URL_PATH ) );
	}
}
