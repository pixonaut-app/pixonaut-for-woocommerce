<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Cart\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WP_REST_Request;

final class CartProofsTest extends PersonalisedCartTestCase {

	/**
	 * Text longer than any line of a cart, shown in full.
	 */
	private const LONG_TEXT = 'Grattis på födelsedagen, med kärlek från oss alla här hemma i Stockholm och i Göteborg!';

	/**
	 * The personalised line, added through the product page's form.
	 *
	 * @return array{0: string, 1: array<string, mixed>} Its key and the item.
	 */
	private function added(): array {
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		$cart = WC()->cart->get_cart();
		$key  = (string) array_key_first( $cart );
		return array( $key, $cart[ $key ] );
	}

	/**
	 * Whether a URL is the plugin's proof route for the fixture's proof, signed,
	 * as the picture expected.
	 *
	 * @param string            $url     The URL.
	 * @param ProofPicture|null $picture The picture it must name; null for the design on its blank in the previewed view.
	 */
	private function assert_proof_url( string $url, ?ProofPicture $picture = null ): void {
		$picture ??= ProofPicture::on_blank();
		// Decoded: on plain permalinks the route is itself a query value.
		$this->assertStringStartsWith( rest_url( Plugin::REST_NAMESPACE . ProofUrls::ROUTE . '/' . DesignFixture::ID ), rawurldecode( $url ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( $picture->query(), isset( $query['p'] ) ? self::text( $query['p'] ) : null );
		$this->assertTrue( $this->plugin->proof_urls()->verify( DesignFixture::ID, self::text( $query['v'] ?? null ), (int) self::text( $query['w'] ?? null ), self::text( $query['sig'] ?? null ), $picture ) );
	}

	/**
	 * The `src` of the classic cart's image for a line.
	 *
	 * @param string               $key  The line's key.
	 * @param array<string, mixed> $item The line.
	 */
	private static function thumbnail_src( string $key, array $item ): string {
		$html = (string) apply_filters( 'woocommerce_cart_item_thumbnail', '<img src="product.jpg" />', $item, $key );
		preg_match( '/<img[^>]+src="([^"]+)"/', $html, $src );
		return html_entity_decode( $src[1] ?? '' );
	}

	public function test_the_line_shows_the_design_on_its_blank_in_the_view_the_customer_previewed(): void {
		[ $key, $item ] = $this->added();

		$this->assert_proof_url( self::thumbnail_src( $key, $item ), ProofPicture::on_blank() );
	}

	public function test_the_setting_can_show_the_placements_first_view_instead(): void {
		$this->plugin->settings()->set( Settings::LINE_PICTURE_VIEW, PictureView::FIRST );
		[ $key, $item ] = $this->added();

		$this->assert_proof_url( self::thumbnail_src( $key, $item ), ProofPicture::on_blank( BlankFixture::VIEW_ID ) );
	}

	public function test_the_first_view_is_the_first_the_product_is_shown_in(): void {
		$this->plugin->settings()->set( Settings::LINE_PICTURE_VIEW, PictureView::FIRST );
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		( new ProductVariant(
			DesignFixture::BLANK_ID,
			null,
			BlankFixture::VARIANT_REF,
			'',
			null,
			array(
				array(
					'id'        => 'view_flat',
					'placement' => DesignFixture::PLACEMENT_ID,
					'label'     => 'Flat',
				),
				array(
					'id'        => 'view_back',
					'placement' => 'back',
					'label'     => 'Back',
				),
				BlankFixture::VARIANT_VIEWS[0],
			),
			ProductVariants::attributes( $product )
		) )->save_to( $product );
		self::config()->with_views( array( 'view_back', BlankFixture::VIEW_ID ) )->save_to( $product );
		[ $key, $item ] = $this->added();

		$this->assert_proof_url( self::thumbnail_src( $key, $item ), ProofPicture::on_blank( BlankFixture::VIEW_ID ) );
	}

	public function test_a_product_on_no_blank_shows_the_flat_proof(): void {
		[ $key, $item ] = $this->added();
		$product        = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		( new ProductConfig( personalise: true, sides: array( new Side( 'front', 'Front', null, null, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, null, 'Mini tee' ) ) ) )->save_to( $product );
		$item['data'] = wc_get_product( $this->product_id );

		$this->assert_proof_url( self::thumbnail_src( $key, $item ), ProofPicture::flat() );
	}

	private static function text( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}

	public function test_the_classic_cart_shows_the_proof_in_place_of_the_product_image(): void {
		[ $key, $item ] = $this->added();
		$widths         = array();
		add_filter(
			'pixonaut_wc_cart_proof_width',
			static function ( int $width ) use ( &$widths ): int {
				$widths[] = $width;
				return $width;
			}
		);

		$html = (string) apply_filters( 'woocommerce_cart_item_thumbnail', '<img src="product.jpg" />', $item, $key );

		$this->assertSame( 1, preg_match( '/<img[^>]+src="([^"]+)"/', $html, $src ) );
		$this->assert_proof_url( html_entity_decode( $src[1] ?? '' ) );
		$this->assertStringContainsString( 'alt="' . esc_attr( $this->plugin->labels()->get( Labels::PROOF_ALT ) ) . '"', $html );
		$this->assertStringNotContainsString( 'product.jpg', $html );
		$this->assertStringNotContainsString( Hosts::server_api(), $html );
		$this->assertNotSame( array(), $widths );
	}

	public function test_a_line_stored_before_proofs_were_kept_shows_the_product_image(): void {
		[ $key, $item ] = $this->added();
		$data           = $item[ CartLine::KEY ];
		unset( $data['sides'][0]['proofId'] );
		$item[ CartLine::KEY ] = $data;

		$this->assertSame( '<img src="product.jpg" />', apply_filters( 'woocommerce_cart_item_thumbnail', '<img src="product.jpg" />', $item, $key ) );
	}

	public function test_a_product_that_is_not_personalised_keeps_its_image(): void {
		$this->assertSame( 'thumb', apply_filters( 'woocommerce_cart_item_thumbnail', 'thumb', array( 'key' => 'plain' ), 'plain' ) );
	}

	public function test_a_line_added_without_field_values_has_no_details(): void {
		[ , $item ] = $this->added();

		$this->assertSame( array(), apply_filters( 'woocommerce_get_item_data', array(), $item ) );
	}

	/**
	 * The personalised line, added with the field values the storefront
	 * read from the designer.
	 *
	 * @param list<array{label: string, type: string, value: string}>|null $fields The front's fields; null for a name, a photo and an unlabelled colour.
	 *
	 * @return array<string, mixed> The cart item.
	 */
	private function added_with_fields( ?array $fields = null ): array {
		$fields                         ??= array(
			array(
				'label' => 'Name',
				'type'  => FieldValue::TEXT,
				'value' => "<b>Anna</b>\n" . self::LONG_TEXT,
			),
			array(
				'label' => 'Photo',
				'type'  => FieldValue::IMAGE,
				'value' => 'https://example.org/photo.jpg',
			),
			array(
				'label' => '',
				'type'  => FieldValue::COLOR,
				'value' => '#ff0000',
			),
		);
		$_POST[ AddToCart::FIELDS_FIELD ] = array( 'front' => wp_slash( (string) wp_json_encode( $fields ) ) );
		return $this->added()[1];
	}

	private function list_fields(): void {
		$this->plugin->settings()->set( Settings::SHOW_FIELD_VALUES, true );
	}

	public function test_by_default_the_item_details_list_no_fields(): void {
		$item = $this->added_with_fields();

		$this->assertSame( array(), apply_filters( 'woocommerce_get_item_data', array(), $item ) );
	}

	public function test_two_fields_with_the_same_label_and_value_show_once(): void {
		$this->list_fields();
		$colour = array(
			'label' => 'Colour',
			'type'  => FieldValue::COLOR,
			'value' => 'Black',
		);
		$item   = $this->added_with_fields( array( $colour, $colour, array( 'value' => 'White' ) + $colour ) );

		$details = apply_filters( 'woocommerce_get_item_data', array(), $item );

		$this->assertSame( array( 'Black', 'White' ), array_column( $details, 'value' ) );
		$this->assertSame( array( 0, 1 ), array_keys( $details ) );
	}

	public function test_rows_with_only_a_display_are_told_apart_by_it(): void {
		[ , $item ] = $this->added();
		$extra      = static fn ( string $display ): array => array(
			'key'     => 'Gift note',
			'display' => $display,
		);

		$details = apply_filters( 'woocommerce_get_item_data', array( $extra( 'First' ), $extra( 'Second' ), $extra( 'First' ) ), $item );

		$this->assertSame( array( 'First', 'Second' ), array_column( $details, 'display' ) );
	}

	public function test_the_item_details_list_each_field_s_value_and_no_side_rows(): void {
		$this->list_fields();
		$item = $this->added_with_fields();

		$details = apply_filters( 'woocommerce_get_item_data', array(), $item );

		$labels = $this->plugin->labels();
		$this->assertSame( array( 'Name', 'Photo', $labels->get( Labels::FIELD_COLOUR ) ), array_column( $details, 'key' ) );
		$this->assertSame( "<b>Anna</b>\n" . self::LONG_TEXT, $details[0]['value'] );
		$this->assertSame( '&lt;b&gt;Anna&lt;/b&gt;<br>' . "\n" . self::LONG_TEXT, $details[0]['display'] );
		$this->assertSame( $labels->get( Labels::FIELD_IMAGE ), $details[1]['value'] );
		$this->assertSame( esc_html( $labels->get( Labels::FIELD_IMAGE ) ), $details[1]['display'] );
		$this->assertStringNotContainsString( 'example.org', (string) wp_json_encode( $details ) );
		$this->assertSame( '#ff0000', $details[2]['value'] );
		$this->assertSame( '#ff0000', $details[2]['display'] );
		$this->assertStringNotContainsString( '<a ', (string) wp_json_encode( $details ), 'No proof or edit link in the details.' );
	}

	public function test_an_erased_line_lists_no_field_values(): void {
		$this->list_fields();
		$item = $this->added_with_fields();
		self::erase_design();

		$this->assertSame( array( 'Front' ), array_column( apply_filters( 'woocommerce_get_item_data', array(), $item ), 'key' ) );
	}

	public function test_an_erased_design_s_values_leave_the_cart_as_it_loads(): void {
		$item = $this->added_with_fields();
		self::erase_design();

		$loaded = apply_filters( 'woocommerce_get_cart_item_from_session', $item, $item, $item['key'] );

		$line = CartLine::of( $loaded );
		$this->assertNotNull( $line );
		$this->assertSame( array(), $line->sides[0]->fields );
		$this->assertSame( DesignFixture::ID, $line->sides[0]->design_id );
	}

	public function test_a_filter_changes_the_item_details_and_keeps_other_plugins_rows(): void {
		[ , $item ] = $this->added();
		add_filter(
			'pixonaut_wc_cart_item_display',
			static function ( array $rows, array $cart_item ) use ( $item ): array {
				return $cart_item['key'] === $item['key'] ? array(
					array(
						'key'     => 'Design',
						'value'   => 'v',
						'display' => 'd',
					),
				) : $rows;
			},
			10,
			2
		);

		$details = apply_filters(
			'woocommerce_get_item_data',
			array(
				array(
					'key'   => 'Gift wrap',
					'value' => 'yes',
				),
			),
			$item
		);

		$this->assertSame( array( 'Gift wrap', 'Design' ), array_column( $details, 'key' ) );
	}

	public function test_the_store_api_shows_the_proof_as_the_line_image(): void {
		$this->added();
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );

		$this->assertSame( 200, $response->get_status() );
		$data   = $response->get_data();
		$images = $data['items'][0]['images'] ?? null;
		$this->assertIsArray( $images );
		$this->assertCount( 1, $images );
		$image = (array) $images[0];
		$this->assertSame( 0, $image['id'] );
		$this->assert_proof_url( (string) $image['src'] );
		$this->assert_proof_url( (string) $image['thumbnail'] );
		$this->assertSame( $this->plugin->labels()->get( Labels::PROOF_ALT ), $image['alt'] );
		$this->assertStringNotContainsString( Hosts::server_api(), (string) wp_json_encode( $data['items'][0] ) );
		$this->assertSame( array(), $data['items'][0]['item_data'], 'The Cart and Checkout blocks show no side rows.' );
	}

	private static function erase_design(): void {
		do_action( 'pixonaut_wc_webhook_design_personal_data_erased', array( 'designId' => DesignFixture::ID ) );
	}

	public function test_an_erased_line_keeps_the_product_image_in_the_classic_cart(): void {
		[ $key, $item ] = $this->added();
		self::erase_design();

		$this->assertSame( '<img src="product.jpg" />', apply_filters( 'woocommerce_cart_item_thumbnail', '<img src="product.jpg" />', $item, $key ) );
	}

	public function test_an_erased_line_says_its_personalisation_was_removed_with_no_proof_link(): void {
		[ , $item ] = $this->added();
		self::erase_design();

		$details = apply_filters( 'woocommerce_get_item_data', array(), $item );

		$this->assertCount( 1, $details );
		$this->assertSame( 'Front', $details[0]['key'] );
		$removed = $this->plugin->labels()->get( Labels::PERSONALISATION_REMOVED );
		$this->assertStringContainsStringIgnoringCase( "removed after the shop's retention period", $removed );
		$this->assertSame( $removed, $details[0]['value'] );
		$this->assertSame( esc_html( $removed ), $details[0]['display'] );
		$this->assertStringNotContainsString( 'sig=', $details[0]['display'] );
	}

	public function test_the_store_api_shows_an_erased_lines_product_images(): void {
		$this->added();
		self::erase_design();
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) )->get_data();

		// Every proof URL carries its signature.
		$this->assertStringNotContainsString( 'sig=', (string) wp_json_encode( $data['items'][0] ?? null ) );
	}

	/**
	 * As when the short-lived mark expired, or an object cache evicted it.
	 */
	private static function drop_short_mark(): void {
		delete_transient( Schema::PROOF_ERASED_PREFIX . md5( DesignFixture::ID ) );
	}

	public function test_a_cart_line_stays_erased_once_the_short_lived_mark_is_gone(): void {
		[ $key, $item ] = $this->added();
		self::erase_design();
		self::drop_short_mark();

		$this->assertTrue( ProofCache::is_erased( DesignFixture::ID ) );
		$this->assertSame( '<img src="product.jpg" />', apply_filters( 'woocommerce_cart_item_thumbnail', '<img src="product.jpg" />', $item, $key ) );
	}

	public function test_deleting_an_erased_cart_design_forgets_its_erasure(): void {
		$this->added();
		self::erase_design();
		self::drop_short_mark();

		$this->plugin->proof_cache()->purge( DesignFixture::ID );

		$this->assertFalse( ProofCache::is_erased( DesignFixture::ID ) );
	}
}
