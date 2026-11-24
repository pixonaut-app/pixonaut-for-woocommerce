<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\OrderLines;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\DesignerVisits;
use Pixonaut\WooCommerce\Storefront\PersonaliseButton;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Order_Item_Product;

/**
 * A book designed a page at a time comes back from the hosted designer as a
 * proof set: each page is committed as a design of its own, the line keeps
 * each design with its page, and its order line does too.
 */
final class BookReturnTest extends PersonalisedCartTestCase {

	private const LINK_URL = 'http://fake-pixonaut.test/designer/dl_1';

	private const PROOF_SET = 'pset_book';

	/** The pages the book was designed at. */
	private const PAGES = 3;

	private PersonaliseButton $button;

	public function set_up(): void {
		parent::set_up();
		$this->button = $this->plugin->personalise_button();
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
		WC()->session->set( DesignerVisits::SESSION_KEY, null );
		WC()->session->set( DesignerVisits::RETURNS_SESSION_KEY, null );
		WC()->session->set( PersonalisedDesigns::SESSION_KEY, null );
	}

	/**
	 * Sends the customer to the designer, answering the trip's state.
	 */
	private function start(): string {
		$this->recorder->json(
			201,
			array(
				'id'             => 'shl_1',
				'token'          => basename( self::LINK_URL ),
				'url'            => self::LINK_URL,
				'expiresAt'      => null,
				'name'           => null,
				'brandId'        => null,
				'designLimit'    => null,
				'printAreaFrame' => false,
				'placementFrame' => false,
				'locale'         => null,
			)
		);
		$location = $this->button->start( $this->product_id, 0, 1 );
		$query    = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$state = $query[ PersonaliseButton::STATE_ARG ] ?? '';
		$this->assertIsString( $state );
		$this->assertNotSame( '', $state );
		$this->recorder->requests = array();
		return $state;
	}

	private static function token( int $page ): string {
		return 'eyJhdWQiOiJwcm9vZiJ9.cGFnZS' . $page;
	}

	private static function design_id( int $page ): string {
		return sprintf( '0000000%d-4e5f-4a6b-9c7d-8e9f0a1b2c3d', $page );
	}

	/**
	 * The book's pages by slot, as the line key takes them.
	 *
	 * @return array<string, string>
	 */
	private static function slots(): array {
		$slots = array();
		for ( $page = 1; $page <= self::PAGES; ++$page ) {
			$slots[ 'front#' . $page ] = self::token( $page );
		}
		return $slots;
	}

	/**
	 * Scripts the proof set's read, then each page's commit and the read of
	 * the design it made.
	 */
	private function script_book(): void {
		$proofs = array();
		for ( $page = 1; $page <= self::PAGES; ++$page ) {
			$proofs[] = array(
				'key'        => null,
				'page'       => $page,
				'proofToken' => self::token( $page ),
				'proofUrl'   => 'http://fake-pixonaut.test/proofs/' . $page,
				'expiresAt'  => ( time() + HOUR_IN_SECONDS ) * 1000,
			);
		}
		$this->recorder->json(
			200,
			array(
				'proofs'    => $proofs,
				'expiresAt' => ( time() + HOUR_IN_SECONDS ) * 1000,
			)
		);
		$ref = Refs::cart_ref( self::SITE, Refs::line_key( self::slots(), (string) WC()->session->get_customer_id() ) );
		for ( $page = 1; $page <= self::PAGES; ++$page ) {
			$this->recorder
				->json(
					201,
					array(
						'designId'      => self::design_id( $page ),
						'printFloorDpi' => 150,
						'lowResolution' => array(),
					)
				)
				->json(
					200,
					DesignFixture::design(
						array(
							'id'          => self::design_id( $page ),
							'externalRef' => $ref,
							'page'        => $page,
							'pageCount'   => self::PAGES,
						)
					)
				);
		}
	}

	private function waiting(): ?CartLine {
		foreach ( (array) WC()->session->get( PersonalisedDesigns::SESSION_KEY ) as $key => $item ) {
			if ( ( $item['product_id'] ?? null ) === $this->product_id ) {
				return $this->plugin->personalised_designs()->get( (string) $key )['line'] ?? null;
			}
		}
		return null;
	}

	public function test_each_page_of_a_returned_proof_set_is_committed_and_kept_with_its_page(): void {
		$state = $this->start();
		$this->script_book();

		$this->button->finish( $this->product_id, 0, null, $state, self::PROOF_SET );

		$this->assertSame( array(), self::errors() );
		$this->assertSame( 'GET', $this->recorder->requests[0]['method'] );
		$this->assertStringEndsWith( '/v1/proof-sets/' . self::PROOF_SET, $this->recorder->requests[0]['url'] );
		$commits = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && str_ends_with( $request['url'], '/v1/designs' ) ) );
		$this->assertSame( array_values( self::slots() ), array_map( static fn ( array $request ): string => json_decode( (string) $request['body'], true )['proofToken'], $commits ) );
		$this->assertCount( self::PAGES, array_unique( array_map( static fn ( array $request ): string => $request['headers']['Idempotency-Key'], $commits ) ) );

		$line = $this->waiting();
		$this->assertNotNull( $line );
		$this->assertSame( array( 1, 2, 3 ), array_map( static fn ( $side ): ?int => $side->page, $line->sides ) );
		$this->assertSame( array( self::design_id( 1 ), self::design_id( 2 ), self::design_id( 3 ) ), array_map( static fn ( $side ): string => $side->design_id, $line->sides ) );
		$this->assertSame( self::PAGES, $line->page_count( 'front' ) );
		$this->assertEquals( $line, CartLine::from_array( $line->to_array() ) );
		// Each page is changed on its own; the side's key alone names its first page.
		$this->assertSame( self::design_id( 2 ), $line->side( 'front#2' )?->design_id );
		$this->assertSame( self::design_id( 1 ), $line->side( 'front' )?->design_id );

		// Checkout keeps each design with its page on the order line.
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$item    = new WC_Order_Item_Product();
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$item->set_product( $product );
		$order->add_item( $item );
		$order->save();
		( new OrderLines( $this->plugin->connection(), $this->plugin->sdk(), new WcLogger() ) )->copy_to_line( $item, '', array( CartLine::KEY => $line->to_array() ) );
		$state = LineState::of( $item );
		$this->assertSame( array_keys( self::slots() ), $state->sides() );
		$this->assertSame( 2, $state->side_ref( 'front#2', LineState::SIDE_PAGE ) );
		$this->assertSame( self::design_id( 2 ), $state->side_ref( 'front#2', LineState::SIDE_DESIGN_ID ) );
		$this->assertSame(
			array(
				1 => 'front#1',
				2 => 'front#2',
				3 => 'front#3',
			),
			$state->slots_of( 'front' )
		);
	}

	public function test_a_proof_set_pixonaut_does_not_know_commits_nothing(): void {
		$state = $this->start();
		$this->recorder->json( 404, array( 'error' => 'unknown_proof_set' ) );

		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->button->finish( $this->product_id, 0, null, $state, self::PROOF_SET ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGNER_VISIT_UNKNOWN ) ), self::errors() );
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertNull( $this->waiting() );
	}

	public function test_an_expired_proof_set_asks_the_customer_to_design_again_and_keeps_no_retry(): void {
		$this->recorder->json( 410, array( 'error' => 'proof_set_expired' ) );

		// A return of the kept link, with no trip this session opened.
		$back = $this->button->finish( $this->product_id, 0, null, '', self::PROOF_SET );

		$this->assertSame( get_permalink( $this->product_id ), $back );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_EXPIRED, array( 'product_id' => $this->product_id ) ) ), self::errors() );
		$this->assertNull( ( new DesignerVisits() )->waiting_for( $this->product_id ) );
		$this->assertNull( $this->waiting() );
	}

	public function test_a_proof_set_that_cannot_be_read_now_is_kept_and_read_again_on_retry(): void {
		$this->start();
		$this->recorder->fail();

		$this->assertSame( get_permalink( $this->product_id ), $this->button->finish( $this->product_id, 0, null, '', self::PROOF_SET ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::PERSONALISATION_UNAVAILABLE, array( 'product_id' => $this->product_id ) ) ), self::errors() );
		$kept = ( new DesignerVisits() )->waiting_for( $this->product_id );
		$this->assertNotNull( $kept );
		wc_clear_notices();
		$this->script_book();

		$this->button->retry( $kept );

		$this->assertSame( array(), self::errors() );
		$this->assertSame( self::PAGES, $this->waiting()?->page_count( 'front' ) );
	}

	public function test_an_expired_proof_set_leaves_a_visit_it_was_not_kept_for(): void {
		$this->start();
		$this->recorder->json( 410, array( 'error' => 'proof_set_expired' ) );

		$this->button->finish( $this->product_id, 0, null, '', 'pset_old' );

		$this->assertNotNull( ( new DesignerVisits() )->take( DesignerVisits::key( $this->product_id, 0 ) ) );
	}
}
