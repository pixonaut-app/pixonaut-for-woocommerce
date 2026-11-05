<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\LayoutAdjustments;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Catalogue\StandingRows;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\StaticCredentials;
use RuntimeException;
use WC_Product_Simple;

/**
 * An option of a standing side, on a blank whose print areas change with
 * Size, laid out by hand on Pixonaut's hosted correction page, kept while
 * the side changes, and reset.
 */
final class LayoutAdjustmentTest extends StandingTestCase {

	private const HOSTED_PAGE = 'https://app.pixonaut.example/h/correct/tok_layout';

	private const PROOF_TOKEN = 'prf_tok_layout';

	private const ADJUSTED_ID = 'des_row_s_adjusted';

	private const ADJUSTED_PROOF = 'prf_adjusted';

	/**
	 * The template's next version, which only the options that follow the side take.
	 */
	private const NEXT_TEMPLATE_ID = 'mini-tee@3';

	private LayoutAdjustments $adjustments;

	public function set_up(): void {
		parent::set_up();
		$designs           = new Designs( new Client( self::BASE_URL, Operations::plugin(), new StaticCredentials(), new WpHttpTransport(), new WcLogger(), new Deprecations() ) );
		$this->adjustments = new LayoutAdjustments(
			$designs,
			new StandingRows( $this->catalogue, $designs, $this->logger ),
			$this->logger,
			static function ( string $location ): never {
				throw new RuntimeException( $location );
			}
		);
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_adjust_layout_opens_the_options_design_for_its_layout_on_pixonauts_page(): void {
		$product = $this->standing_product();
		$adjust  = $this->tile( $product, 'S' )['adjust'];
		$this->script_link_minted();

		$this->assertSame( self::HOSTED_PAGE, $this->follow( 'start', $adjust ) );

		$minted = $this->sent( 'POST', '/v1/designs/des_row_s/correction-links' );
		$this->assertTrue( $minted['adjustLayout'] );
		$this->assertSame( admin_url( 'admin-post.php?action=' . LayoutAdjustments::RETURN_ACTION ), $minted['returnUrl'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $minted['state'] );
	}

	public function test_approving_prints_the_option_from_its_own_design_marked_adjusted(): void {
		$product = $this->standing_product();

		$back = $this->adjust( $product, 'S' );

		$this->assertStringContainsString( LayoutAdjustments::DONE_ARG . '=' . LayoutAdjustments::ADJUSTED, $back );
		$this->assertSame(
			array(
				'templateId'  => self::TEMPLATE_ID,
				'approve'     => true,
				'blankId'     => self::BLANK_ID,
				'placementId' => self::PLACEMENT_ID,
				'variantRow'  => array( self::AREAS_VARY_BY => 'S' ),
				'proofToken'  => self::PROOF_TOKEN,
			),
			array_slice( $this->standing_requests(), -1 )[0],
			'named as the design laid out was asked for: S at its usual size'
		);
		$rows = $this->rows( $product->get_id() );
		$this->assertSame( array( self::ADJUSTED_ID, 'des_row_m' ), array_map( static fn ( StandingRow $row ): string => $row->design_id, $rows ) );
		$this->assertSame( self::ADJUSTED_PROOF, $rows[0]->proof_id );
		$this->assertSame( self::TEMPLATE_ID, $rows[0]->adjusted?->template_id );
		$this->assertNull( $rows[0]->adjusted->size );
		$this->assertNull( $rows[1]->adjusted );
		$this->cache->flush();
		$tiles = $this->option_tiles( $product->get_id() );
		$this->assertSame( array( true, false ), array_column( $tiles, 'adjusted' ) );
		$this->assertNotSame( '', $tiles[0]['reset'] );
		$this->assertSame( '', $tiles[1]['reset'] );
	}

	public function test_an_adjusted_option_keeps_its_design_when_the_side_gets_a_new_version(): void {
		$product = $this->standing_product();
		$this->adjust( $product, 'S' );
		$before = count( $this->standing_requests() );

		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_m3' ) );
		$this->save(
			$product->get_id(),
			array(),
			array(
				'mode'     => Side::STANDING,
				'template' => self::NEXT_TEMPLATE_ID,
			)
		);

		$this->assertSame( array( self::ADJUSTED_ID, 'des_row_m3' ), array_map( static fn ( StandingRow $row ): string => $row->design_id, $this->rows( $product->get_id() ) ) );
		$this->assertSame( array( self::AREAS_VARY_BY => 'M' ), array_slice( $this->standing_requests(), $before )[1]['variantRow'], 'only M is asked for' );
	}

	public function test_reset_puts_the_option_back_on_the_sides_design(): void {
		$product = $this->standing_product();
		$this->adjust( $product, 'S' );
		$reset = $this->tile( $product, 'S' )['reset'];
		$this->script_rows( array( 'des_row_s' ), 'read', false );
		$before = count( $this->standing_requests() );

		$back = $this->follow( 'reset', $reset );

		$this->assertStringContainsString( LayoutAdjustments::DONE_ARG . '=' . LayoutAdjustments::RESET, $back );
		$rows = $this->rows( $product->get_id() );
		$this->assertSame( array( 'des_row_s', 'des_row_m' ), array_map( static fn ( StandingRow $row ): string => $row->design_id, $rows ) );
		$this->assertNull( $rows[0]->adjusted );
		$asked = array_slice( $this->standing_requests(), $before );
		$this->assertCount( 1, $asked, 'M keeps its design without asking Pixonaut' );
		$this->assertArrayNotHasKey( 'proofToken', $asked[0] );
	}

	public function test_a_save_that_cannot_finish_keeps_the_adjusted_option_for_the_next(): void {
		$product = $this->standing_product();
		$this->adjust( $product, 'S' );

		// Pixonaut cannot be reached for the blank.
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );
		$this->assertFalse( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->valid );

		$this->script_link( array( 'legal' => true ) );
		// The failed save kept S alone; M's design is read again.
		$this->script_rows( array( 'des_row_m' ) );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$rows = $this->rows( $product->get_id() );
		$this->assertSame( array( self::ADJUSTED_ID, 'des_row_m' ), array_map( static fn ( StandingRow $row ): string => $row->design_id, $rows ) );
		$this->assertNotNull( $rows[0]->adjusted );
	}

	public function test_an_adjusted_options_preview_not_read_is_read_on_the_next_save(): void {
		$product = $this->standing_product();
		$this->adjust( $product, 'S', false );
		$this->assertNull( $this->rows( $product->get_id() )[0]->proof_id );

		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array(), 'read' );
		$this->script_adjusted_read();
		$this->script_standing( 'des_row_m' );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$rows = $this->rows( $product->get_id() );
		$this->assertSame( self::ADJUSTED_ID, $rows[0]->design_id );
		$this->assertSame( self::ADJUSTED_PROOF, $rows[0]->proof_id );
	}

	public function test_a_store_manager_who_may_no_longer_edit_the_product_changes_nothing_on_return(): void {
		$product = $this->standing_product();
		$state   = $this->started( $product, 'S' );
		$user    = wp_get_current_user();
		$user->set_role( 'subscriber' );

		$back = $this->follow( 'finish', '?' . LayoutAdjustments::STATE_ARG . '=' . $state . '&' . LayoutAdjustments::TOKEN_ARG . '=' . self::PROOF_TOKEN );

		$this->assertStringContainsString( 'edit.php?post_type=product', $back );
		$this->assertCount( 3, $this->standing_requests(), 'no adjusted design was asked for' );
		$this->assertNull( $this->rows( $product->get_id() )[0]->adjusted );
	}

	public function test_cancelling_on_pixonauts_page_changes_nothing(): void {
		$product = $this->standing_product();
		$state   = $this->started( $product, 'S' );

		$back = $this->follow( 'finish', '?' . LayoutAdjustments::STATE_ARG . '=' . $state );

		$this->assertStringNotContainsString( LayoutAdjustments::DONE_ARG, $back );
		$this->assertStringContainsString( 'post=' . $product->get_id(), $back );
		$this->assertNull( $this->rows( $product->get_id() )[0]->adjusted );
	}

	public function test_a_trip_is_taken_back_once_and_by_its_own_user_only(): void {
		$product = $this->standing_product();
		$state   = $this->started( $product, 'S' );
		$query   = '?' . LayoutAdjustments::STATE_ARG . '=' . $state . '&' . LayoutAdjustments::TOKEN_ARG . '=' . self::PROOF_TOKEN;

		wp_set_current_user( $this->user( 'shop_manager' ) );
		$elsewhere = $this->follow( 'finish', $query );
		wp_set_current_user( $this->user( 'administrator' ) );
		$again = $this->follow( 'finish', $query );

		$this->assertStringContainsString( 'edit.php?post_type=product', $elsewhere );
		$this->assertStringContainsString( 'edit.php?post_type=product', $again, 'the other user\'s return took the trip' );
		$this->assertCount( 3, $this->standing_requests(), 'no adjusted design was asked for' );
	}

	public function test_a_refused_adjustment_is_logged_and_said(): void {
		$product = $this->standing_product();
		$state   = $this->started( $product, 'S' );
		$this->recorder->json( 422, array( 'error' => ErrorCodes::LAYOUT_PROOF_MISMATCH ) );

		$back = $this->follow( 'finish', '?' . LayoutAdjustments::STATE_ARG . '=' . $state . '&' . LayoutAdjustments::TOKEN_ARG . '=' . self::PROOF_TOKEN );

		$this->assertStringContainsString( LayoutAdjustments::DONE_ARG . '=' . LayoutAdjustments::FAILED, $back );
		$this->assertSame( ErrorCodes::LAYOUT_PROOF_MISMATCH, $this->logger->entries[0]['context']['code'] ?? null );
		$this->assertNull( $this->rows( $product->get_id() )[0]->adjusted );
	}

	public function test_a_request_without_the_panels_nonce_opens_nothing(): void {
		$product = $this->standing_product();
		$adjust  = remove_query_arg( '_wpnonce', $this->tile( $product, 'S' )['adjust'] );

		$this->assertStringContainsString( 'edit.php?post_type=product', $this->follow( 'start', $adjust ) );
		$this->assertSame( array(), $this->sent_to( '/correction-links' ) );
	}

	/**
	 * A product with a standing side on the blank whose areas vary: S at its
	 * usual size, M at the side's.
	 */
	private function standing_product(): WC_Product_Simple {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ) );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );
		return $product;
	}

	/**
	 * Adjusts an option's layout: Adjust layout, then the hosted page's
	 * return with the adjustment's proof.
	 *
	 * @param WC_Product_Simple $product The product.
	 * @param string            $value   The option's value.
	 * @param bool              $read    Whether Pixonaut answers the adjusted design's read.
	 * @return string Where the store manager was sent back to.
	 */
	private function adjust( WC_Product_Simple $product, string $value, bool $read = true ): string {
		$state = $this->started( $product, $value );
		$this->recorder->raw(
			201,
			OpenApiAssert::example(
				'POST',
				'/v1/designs/standing',
				201,
				array(
					'designId' => self::ADJUSTED_ID,
					'adjusted' => true,
				)
			),
			'application/json'
		);
		if ( $read ) {
			$this->script_adjusted_read();
		} else {
			$this->recorder->json( 500, array( 'error' => 'db_error' ) );
		}
		return $this->follow( 'finish', '?' . LayoutAdjustments::STATE_ARG . '=' . $state . '&' . LayoutAdjustments::TOKEN_ARG . '=' . self::PROOF_TOKEN );
	}

	/**
	 * Starts an option's trip to the hosted page.
	 *
	 * @param WC_Product_Simple $product The product.
	 * @param string            $value   The option's value.
	 * @return string The trip's state.
	 */
	private function started( WC_Product_Simple $product, string $value ): string {
		$adjust = $this->tile( $product, $value )['adjust'];
		$this->script_link_minted();
		$this->follow( 'start', $adjust );
		return (string) array_slice( $this->sent_to( '/correction-links' ), -1 )[0]['state'];
	}

	/**
	 * Scripts the read of the adjusted design, which names its preview.
	 */
	private function script_adjusted_read(): void {
		$this->recorder->json(
			200,
			DesignFixture::design(
				array(
					'id'        => self::ADJUSTED_ID,
					'proofId'   => self::ADJUSTED_PROOF,
					'printSize' => array(
						'widthMm'  => 150,
						'heightMm' => 190,
					),
				)
			)
		);
	}

	private function script_link_minted(): void {
		$this->recorder->raw(
			201,
			OpenApiAssert::example( 'POST', '/v1/designs/{id}/correction-links', 201, array( 'url' => self::HOSTED_PAGE ) ),
			'application/json'
		);
	}

	/**
	 * The panel's tile of an option, read as an administrator.
	 *
	 * @param WC_Product_Simple $product The product.
	 * @param string            $value   The option's value.
	 * @return array{value: string, image: string, usual: bool, adjusted: bool, adjust: string, reset: string}
	 */
	private function tile( WC_Product_Simple $product, string $value ): array {
		wp_set_current_user( $this->user( 'administrator' ) );
		// The panel reads the blank, its views and its printshop anew, as on a new page.
		$this->cache->flush();
		foreach ( $this->option_tiles( $product->get_id() ) as $tile ) {
			if ( $tile['value'] === $value ) {
				return $tile;
			}
		}
		$this->fail( "No tile for {$value}." );
	}

	/**
	 * Runs one of the handlers with a request's query, and answers where it sent the browser.
	 *
	 * @param string $handler `start`, `finish` or `reset`.
	 * @param string $url     The request's address, or its query alone.
	 */
	private function follow( string $handler, string $url ): string {
		$query = array();
		wp_parse_str( html_entity_decode( (string) wp_parse_url( $url, PHP_URL_QUERY ) ), $query );
		$_GET = $query;
		try {
			$this->adjustments->$handler();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		} finally {
			$_GET = array();
		}
		$this->fail( 'The handler sent the browser nowhere.' );
	}

	/**
	 * The body of the last request sent to a route.
	 *
	 * @param string $method The method.
	 * @param string $path   The path.
	 * @return array<string, mixed>
	 */
	private function sent( string $method, string $path ): array {
		foreach ( array_reverse( $this->recorder->requests ) as $request ) {
			if ( $method === $request['method'] && wp_parse_url( $request['url'], PHP_URL_PATH ) === $path ) {
				return json_decode( (string) $request['body'], true );
			}
		}
		$this->fail( "Nothing was sent to {$method} {$path}." );
	}

	/**
	 * The bodies of the requests whose path ends so, in order.
	 *
	 * @param string $suffix The path's end.
	 * @return list<array<string, mixed>>
	 */
	private function sent_to( string $suffix ): array {
		$bodies = array();
		foreach ( $this->recorder->requests as $request ) {
			if ( str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), $suffix ) ) {
				$bodies[] = json_decode( (string) $request['body'], true );
			}
		}
		return $bodies;
	}

	/**
	 * The product's first side's rows, as stored.
	 *
	 * @param int $product_id The product.
	 * @return list<StandingRow>
	 */
	private function rows( int $product_id ): array {
		return array_values( ProductConfig::stored_on( $this->reload( $product_id ) )->sides[0]->standing_rows );
	}
}
