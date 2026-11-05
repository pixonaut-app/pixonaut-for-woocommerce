<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\PictureView;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\LinePictures;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use WC_Order;
use WC_Order_Item_Product;

final class LinePicturesTest extends PersonalisedCartTestCase {

	private const CHECKOUT = array(
		'payment_method'     => 'cod',
		'billing_first_name' => 'Alexandra',
		'billing_last_name'  => 'Lind',
		'billing_email'      => 'alexandra@example.org',
		'billing_country'    => 'SE',
		'billing_address_1'  => 'Storgatan 1',
		'billing_city'       => 'Stockholm',
		'billing_postcode'   => '11122',
	);

	private const PICTURE_CLASS = 'pixonaut-line-picture';

	/**
	 * A hosted proof page's address, on the workspace's own domain.
	 */
	private const PROOF_PAGE = 'https://proofs.example-shop.com/s/lnk_proof';

	/**
	 * An email image width neither WooCommerce's defaults nor the order pages use.
	 */
	private const EMAIL_IMAGE_WIDTH = 64;

	private WC_Order $order;

	private WC_Order_Item_Product $item;

	public function set_up(): void {
		parent::set_up();
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		add_action(
			'woocommerce_checkout_order_created',
			function ( WC_Order $order ): void {
				$this->recorder->json(
					200,
					array(
						'designId'    => DesignFixture::ID,
						'externalRef' => Refs::order_item_ref( self::SITE, $order->get_id(), self::personalised_item( $order )->get_id() ),
					)
				);
			},
			1
		);
		$order_id = WC()->checkout()->create_order( self::CHECKOUT );
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->order = $order;
		$this->item  = self::personalised_item( $order );
	}

	private static function personalised_item( WC_Order $order ): WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				return $item;
			}
		}
		self::fail( 'The order has no product line.' );
	}

	/**
	 * What an order page prints under the line's name.
	 */
	private function page_html(): string {
		ob_start();
		do_action( 'woocommerce_order_item_meta_start', $this->item->get_id(), $this->item, $this->order, false );
		return (string) ob_get_clean();
	}

	/**
	 * The `src` of the first picture in some markup.
	 *
	 * @param string $html The markup.
	 */
	private static function src( string $html ): string {
		preg_match( '/<img[^>]+class="' . self::PICTURE_CLASS . '"[^>]+src="([^"]+)"/', $html, $src );
		return html_entity_decode( $src[1] ?? '' );
	}

	/**
	 * Whether a URL is the plugin's proof route for the fixture's design as the picture.
	 *
	 * @param string       $url     The URL.
	 * @param ProofPicture $picture The picture it must name.
	 */
	private function assert_picture_url( string $url, ProofPicture $picture ): void {
		$this->assertStringStartsWith( rest_url( Plugin::REST_NAMESPACE . ProofUrls::ROUTE . '/' . DesignFixture::ID ), rawurldecode( $url ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$text = static fn ( mixed $value ): string => is_string( $value ) ? $value : '';
		$this->assertTrue( $this->plugin->proof_urls()->verify( DesignFixture::ID, $text( $query['v'] ?? null ), (int) $text( $query['w'] ?? null ), $text( $query['sig'] ?? null ), $picture ) );
	}

	public function test_an_order_page_shows_the_design_on_its_blank_under_the_lines_name(): void {
		$html = $this->page_html();

		$this->assert_picture_url( self::src( $html ), ProofPicture::on_blank() );
		$this->assertStringContainsString( 'width="' . LinePictures::PAGE_WIDTH . '"', $html );
		$this->assertStringNotContainsString( Hosts::server_api(), $html );
	}

	public function test_the_setting_shows_the_placements_first_view_on_the_order_page(): void {
		$this->plugin->settings()->set( Settings::LINE_PICTURE_VIEW, PictureView::FIRST );

		$this->assert_picture_url( self::src( $this->page_html() ), ProofPicture::on_blank( BlankFixture::VIEW_ID ) );
	}

	public function test_an_email_shows_the_picture_in_place_of_the_product_image_once(): void {
		$html = wc_get_email_order_items( $this->order, array( 'show_image' => false ) );

		$this->assertSame( 1, substr_count( $html, self::PICTURE_CLASS ), 'shown as the line\'s image, not again under its name' );
		$this->assert_picture_url( self::src( $html ), ProofPicture::on_blank() );
	}

	public function test_a_plain_text_email_shows_no_picture(): void {
		$text = wc_get_email_order_items( $this->order, array( 'plain_text' => true ) );

		$this->assertStringNotContainsString( '<img', $text );
	}

	/**
	 * A fulfilment email's items table for the order's personalised line.
	 *
	 * @param array<string, mixed> $args The table's arguments.
	 */
	private function fulfilment_items( array $args ): string {
		// WooCommerce 10.6 keeps the class under Internal.
		if ( ! class_exists( Fulfillment::class ) ) {
			class_alias( 'Automattic\\WooCommerce\\Internal\\Fulfillments\\Fulfillment', Fulfillment::class );
		}
		$fulfilment = new Fulfillment();
		$fulfilment->set_items(
			array(
				array(
					'item_id' => $this->item->get_id(),
					'qty'     => $this->item->get_quantity(),
				),
			)
		);
		return wc_get_email_fulfillment_items( $this->order, $fulfilment, $args );
	}

	public function test_a_fulfilment_email_shows_the_picture_in_place_of_the_product_image_once(): void {
		$html = $this->fulfilment_items(
			array(
				'show_image' => false,
				'image_size' => array( self::EMAIL_IMAGE_WIDTH, self::EMAIL_IMAGE_WIDTH ),
			)
		);

		$this->assertSame( 1, substr_count( $html, self::PICTURE_CLASS ), 'shown as the line\'s image, not again under its name' );
		// The line's image cell, which WooCommerce 10.6 gives no class.
		$this->assertMatchesRegularExpression( '/<td(?: class="email-order-item-thumbnail"[^>]*)?><img[^>]+class="' . self::PICTURE_CLASS . '"/', $html );
		$this->assertStringContainsString( 'width="' . self::EMAIL_IMAGE_WIDTH . '"', $html );
		$this->assert_picture_url( self::src( $html ), ProofPicture::on_blank() );
	}

	public function test_a_plain_text_fulfilment_email_shows_no_picture(): void {
		$text = $this->fulfilment_items( array( 'plain_text' => true ) );

		$this->assertStringNotContainsString( '<img', $text );
	}

	public function test_an_order_page_after_an_email_shows_the_picture_again(): void {
		wc_get_email_order_items( $this->order );

		$this->assertStringContainsString( self::PICTURE_CLASS, $this->page_html() );
	}

	public function test_an_erased_line_shows_no_picture_and_its_email_keeps_the_product_image(): void {
		do_action( 'pixonaut_wc_webhook_design_personal_data_erased', array( 'designId' => DesignFixture::ID ) );

		$this->assertSame( '', $this->page_html() );
		$this->assertSame( '<img src="product.jpg" />', apply_filters( 'woocommerce_order_item_thumbnail', '<img src="product.jpg" />', $this->item ) );
	}

	/**
	 * Puts the line where it waits for the customer on its proof page.
	 */
	private function await_customer(): LineState {
		$line = LineState::of( $this->item );
		$side = $line->sides()[0];
		$line->set_side_ref( $side, LineState::SIDE_PROOF_URL, self::PROOF_PAGE );
		$this->assertTrue( $line->save() );
		$this->assertTrue( $line->transition( LineState::AWAITING_CUSTOMER ) );
		return $line;
	}

	public function test_an_order_page_links_the_proof_page_while_the_line_waits_for_the_customer(): void {
		$this->await_customer();

		$html = $this->page_html();

		$this->assertStringContainsString( 'href="' . self::PROOF_PAGE . '"', $html );
		$this->assertStringContainsString( 'Review and approve your design', $html );
		$this->assertStringNotContainsString( Hosts::server_api() . '/p/', $html );
	}

	public function test_an_order_page_links_no_proof_page_before_one_was_sent(): void {
		$this->assertStringNotContainsString( '<a ', $this->page_html() );
	}

	public function test_an_order_page_links_no_proof_page_once_its_side_is_approved(): void {
		$line = $this->await_customer();
		$line->set_side_ref( $line->sides()[0], LineState::SIDE_APPROVED, true );
		$this->assertTrue( $line->save() );

		$this->assertStringNotContainsString( self::PROOF_PAGE, $this->page_html() );
	}

	public function test_an_email_order_table_does_not_link_the_proof_page(): void {
		$this->await_customer();

		$html = wc_get_email_order_items( $this->order );

		$this->assertStringNotContainsString( self::PROOF_PAGE, $html );
	}
}
