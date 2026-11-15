<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and set which of a product's values are listed
 * as their own products, and each value's card, called on the shop tool
 * route as Pixonaut calls them.
 */
final class SplitListingShopToolsTest extends WP_UnitTestCase {

	use SplitPosters;

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const SET = 'set-product-split-listing';

	private const GET = 'get-product-split-listing';

	private const UNPROCESSABLE = 422;

	private const OK = 200;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	/**
	 * The attribute whose values are listed as their own products: with and without a photo.
	 */
	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private const CARD_URL = 'https://pictures.example/cards/med-bild.png';

	/**
	 * A card picture's URL that answers nothing.
	 */
	private const GONE_URL = 'https://pictures.example/cards/gone.png';

	private const GONE = 404;

	/**
	 * A one-pixel PNG, as a card's picture's URL answers it.
	 */
	private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	/**
	 * Before any other answer to a request, so the fixture's picture never leaves the test.
	 */
	private const FIRST = 1;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save( self::connection() );
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => self::connection()->site,
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
		$this->set_up_posters();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request', self::FIRST );
		$this->tear_down_posters();
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_reads_each_global_attribute_with_its_choice_and_its_values_cards(): void {
		$poster = $this->poster();

		$read = $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) );

		$this->assertSame( $poster->get_id(), $read['product_id'] );
		$this->assertNull( $read['listed_by'], 'listed once until a choice or the attribute says otherwise' );
		$this->assertSame( array( $this->attributes[ self::PICTURE ], $this->attributes[ self::SIZE ] ), array_column( $read['attributes'], 'attribute_id' ) );
		$picture = $read['attributes'][0];
		$this->assertSame( self::PICTURE, $picture['name'] );
		$this->assertSame( SplitListing::AUTO, $picture['choice'] );
		$this->assertFalse( $picture['attribute_default'] );
		$this->assertFalse( $picture['own_products'] );
		$this->assertSame(
			array(
				'term_id' => $this->term( 'med-bild' ),
				'name'    => 'Med bild',
				'title'   => '',
				'image'   => null,
			),
			$picture['values'][0]
		);
	}

	public function test_it_follows_the_attributes_own_setting_on_auto(): void {
		$poster = $this->poster();
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );

		$read = $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) );

		$this->assertSame( $this->attributes[ self::PICTURE ], $read['listed_by'] );
		$this->assertSame( SplitListing::AUTO, $read['attributes'][0]['choice'] );
		$this->assertTrue( $read['attributes'][0]['attribute_default'] );
		$this->assertTrue( $read['attributes'][0]['own_products'] );
	}

	public function test_a_product_is_listed_by_every_attribute_it_turned_on(): void {
		$poster = $this->poster();
		ProductConfig::stored_on( $poster )->with_split_listing(
			array(
				$this->attributes[ self::PICTURE ] => SplitListing::ON,
				$this->attributes[ self::SIZE ]    => SplitListing::ON,
			)
		)->save_to( $poster );

		$read = $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) );

		$this->assertSame( $this->attributes[ self::PICTURE ], $read['listed_by'], 'the first in the product\'s order' );
		$this->assertSame( array( $this->attributes[ self::PICTURE ], $this->attributes[ self::SIZE ] ), $read['listed_by_all'] );
		$this->assertSame( array( SplitListing::ON, SplitListing::ON ), array_column( $read['attributes'], 'choice' ) );
		$this->assertSame( array( true, true ), array_column( $read['attributes'], 'own_products' ) );
	}

	public function test_an_attribute_on_auto_lists_nothing_while_the_product_turned_another_on(): void {
		$poster = $this->poster();
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		ProductConfig::stored_on( $poster )->with_split_listing( array( $this->attributes[ self::SIZE ] => SplitListing::ON ) )->save_to( $poster );

		$read = $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) );

		$this->assertSame( array( $this->attributes[ self::SIZE ] ), $read['listed_by_all'] );
		$this->assertSame( array( false, true ), array_column( $read['attributes'], 'own_products' ) );
	}

	public function test_it_sets_the_choice_and_the_cards_and_answers_what_the_product_now_has(): void {
		$poster = $this->poster();
		$image  = $this->image();

		$set = $this->succeed(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'choice'       => SplitListing::ON,
						'values'       => array(
							array(
								'term_id' => $this->term( 'med-bild' ),
								'title'   => 'Safari med din bild',
								'image'   => array( 'id' => $image ),
							),
							array(
								'term_id' => $this->term( 'utan-bild' ),
								'title'   => 'Safari',
							),
						),
					),
				),
			)
		);

		$this->assertSame( $this->attributes[ self::PICTURE ], $set['listed_by'] );
		$this->assertSame( SplitListing::ON, $set['attributes'][0]['choice'] );
		$this->assertTrue( $set['attributes'][0]['own_products'] );
		$this->assertSame( 'Safari med din bild', $set['attributes'][0]['values'][0]['title'] );
		$this->assertSame(
			array(
				'id'  => $image,
				'src' => wp_get_attachment_url( $image ),
			),
			$set['attributes'][0]['values'][0]['image']
		);
		$this->assertSame( $set, $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) ), 'the read answers the same' );
		$this->assertSame( array( $this->attributes[ self::PICTURE ] => SplitListing::ON ), ProductConfig::stored_on( $this->reload( $poster ) )->split_listing );
		$this->assertSame(
			array(
				'title' => 'Safari med din bild',
				'image' => $image,
			),
			SplitCardLooks::of( $poster->get_id(), $this->term( 'med-bild' ) )
		);
	}

	public function test_it_changes_only_what_is_named_and_clears_a_card_back_to_the_products(): void {
		$poster = $this->poster();
		$image  = $this->image();
		$with   = $this->term( 'med-bild' );
		$none   = $this->term( 'utan-bild' );
		ProductConfig::stored_on( $poster )->with_split_listing( array( $this->attributes[ self::PICTURE ] => SplitListing::ON ) )->save_to( $poster );
		SplitCardLooks::save(
			$poster->get_id(),
			array(
				$with => array(
					'title' => 'Med',
					'image' => $image,
				),
				$none => array(
					'title' => 'Utan',
					'image' => $image,
				),
			)
		);

		$this->succeed(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'values'       => array(
							array(
								'term_id' => $with,
								'title'   => '',
							),
							array(
								'term_id' => $none,
								'image'   => null,
							),
						),
					),
					array(
						'attribute_id' => $this->attributes[ self::SIZE ],
						'choice'       => SplitListing::OFF,
					),
				),
			)
		);

		$this->assertSame(
			array(
				$this->attributes[ self::PICTURE ] => SplitListing::ON,
				$this->attributes[ self::SIZE ]    => SplitListing::OFF,
			),
			ProductConfig::stored_on( $this->reload( $poster ) )->split_listing,
			'the attribute not given a choice keeps its own'
		);
		$this->assertSame(
			array(
				'title' => '',
				'image' => $image,
			),
			SplitCardLooks::of( $poster->get_id(), $with ),
			'its image is kept'
		);
		$this->assertSame(
			array(
				'title' => 'Utan',
				'image' => 0,
			),
			SplitCardLooks::of( $poster->get_id(), $none ),
			'its title is kept'
		);

		$this->succeed(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'choice'       => SplitListing::AUTO,
					),
				),
			)
		);
		$this->assertSame( array( $this->attributes[ self::SIZE ] => SplitListing::OFF ), ProductConfig::stored_on( $this->reload( $poster ) )->split_listing, 'auto follows the attribute again' );
	}

	public function test_it_copies_a_cards_image_from_its_url_into_the_media_library(): void {
		$poster = $this->poster();
		$this->serve_card_picture();

		$set = $this->succeed(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'values'       => array(
							array(
								'term_id' => $this->term( 'med-bild' ),
								'image'   => array( 'src' => self::CARD_URL ),
							),
						),
					),
				),
			)
		);

		$image = $set['attributes'][0]['values'][0]['image'];
		$this->assertIsArray( $image );
		$this->assertTrue( wp_attachment_is_image( $image['id'] ) );
		$this->assertSame( $poster->get_id(), wp_get_post_parent_id( $image['id'] ) );
		$this->assertSame( $image['id'], SplitCardLooks::of( $poster->get_id(), $this->term( 'med-bild' ) )['image'] );
	}

	public function test_an_image_copied_by_a_refused_call_is_deleted_again(): void {
		$poster = $this->poster();
		$this->serve_card_picture();
		$before = $this->attachments();

		$answer = $this->call(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'choice'       => SplitListing::ON,
						'values'       => array(
							array(
								'term_id' => $this->term( 'med-bild' ),
								'image'   => array( 'src' => self::CARD_URL ),
							),
							array(
								'term_id' => $this->term( 'utan-bild' ),
								'image'   => array( 'src' => self::GONE_URL ),
							),
						),
					),
				),
			)
		);

		$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$this->assertSame( $before, $this->attachments(), 'the first image is not left in the media library' );
		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $poster ) )->split_listing );
		$this->assertSame( array(), SplitCardLooks::stored_on( $poster->get_id() ) );
	}

	public function test_a_configuration_from_a_newer_plugin_version_is_refused_before_an_image_is_copied(): void {
		$poster = $this->poster();
		$this->serve_card_picture();
		$newer = (string) wp_json_encode( array( 'v' => ProductConfig::SCHEMA_VERSION + 1 ) );
		update_post_meta( $poster->get_id(), ProductConfig::META_KEY, wp_slash( $newer ) );
		$before = $this->attachments();

		$answer = $this->call(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'choice'       => SplitListing::ON,
						'values'       => array(
							array(
								'term_id' => $this->term( 'med-bild' ),
								'image'   => array( 'src' => self::CARD_URL ),
							),
						),
					),
				),
			)
		);

		$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$this->assertSame( $before, $this->attachments() );
		$this->assertSame( $newer, get_post_meta( $poster->get_id(), ProductConfig::META_KEY, true ) );
		$this->assertSame( array(), SplitCardLooks::stored_on( $poster->get_id() ) );
	}

	public function test_it_saves_nothing_when_one_row_does_not_fit(): void {
		$poster  = $this->poster();
		$picture = $this->attributes[ self::PICTURE ];
		$on      = array(
			'attribute_id' => $picture,
			'choice'       => SplitListing::ON,
			'values'       => array(
				array(
					'term_id' => $this->term( 'med-bild' ),
					'title'   => 'Med',
				),
			),
		);
		$refused = array(
			'an attribute the product does not have' => array( $on, array( 'attribute_id' => PHP_INT_MAX ) ),
			'an attribute listed twice'              => array( $on, $on ),
			'a value of another attribute'           => array(
				$on,
				array(
					'attribute_id' => $this->attributes[ self::SIZE ],
					'values'       => array(
						array(
							'term_id' => $this->term( 'med-bild' ),
							'title'   => 'Med',
						),
					),
				),
			),
			'an image the library does not have'     => array(
				array(
					'attribute_id' => $picture,
					'choice'       => SplitListing::ON,
					'values'       => array(
						array(
							'term_id' => $this->term( 'med-bild' ),
							'title'   => 'Med',
							'image'   => array( 'id' => PHP_INT_MAX ),
						),
					),
				),
			),
			'an image by both id and src'            => array(
				array(
					'attribute_id' => $picture,
					'choice'       => SplitListing::ON,
					'values'       => array(
						array(
							'term_id' => $this->term( 'med-bild' ),
							'image'   => array(
								'id'  => 1,
								'src' => self::CARD_URL,
							),
						),
					),
				),
			),
			'a choice that is none'                  => array(
				array(
					'attribute_id' => $picture,
					'choice'       => 'sometimes',
				),
			),
		);
		foreach ( $refused as $why => $attributes ) {
			$answer = $this->call(
				self::SET,
				array(
					'product_id' => $poster->get_id(),
					'attributes' => $attributes,
				)
			);
			$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), $why );
			$this->assertSame( 'invalid_input', $answer->get_data()['code'], $why );
		}
		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $poster ) )->split_listing );
		$this->assertSame( array(), SplitCardLooks::stored_on( $poster->get_id() ) );
	}

	public function test_it_refuses_a_product_that_is_none(): void {
		$attributes = array(
			array(
				'attribute_id' => 1,
				'choice'       => SplitListing::ON,
			),
		);
		$inputs     = array(
			self::GET => array( 'product_id' => PHP_INT_MAX ),
			self::SET => array(
				'product_id' => PHP_INT_MAX,
				'attributes' => $attributes,
			),
		);
		foreach ( $inputs as $tool => $input ) {
			$answer = $this->call( $tool, $input );
			$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), $tool );
			$this->assertSame( 'invalid_input', $answer->get_data()['code'], $tool );
		}
	}

	/**
	 * Answers the card picture's URL with a one-pixel PNG.
	 */
	private function serve_card_picture(): void {
		add_filter(
			'pre_http_request',
			static function ( $response, array $args, string $url ) {
				if ( self::GONE_URL === $url ) {
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => self::GONE,
							'message' => 'Not Found',
						),
						'cookies'  => array(),
						'filename' => $args['filename'] ?? null,
					);
				}
				if ( self::CARD_URL !== $url ) {
					return $response;
				}
				$body = base64_decode( self::PIXEL_PNG ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixture picture.
				// A streamed download is written to its file, as WordPress's transport writes it.
				if ( ! empty( $args['stream'] ) ) {
					file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the download's temporary file.
					$body = '';
				}
				return array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => $body,
					'response' => array(
						'code'    => self::OK,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'] ?? null,
				);
			},
			self::FIRST,
			3
		);
	}

	/**
	 * The ids of every attachment the media library has.
	 *
	 * @return list<int>
	 */
	private function attachments(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * An image of the media library.
	 */
	private function image(): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Calls a tool with the credential.
	 *
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 */
	private function call( string $tool, array $input ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $tool );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( (string) wp_json_encode( (object) $input ) );
		return rest_do_request( $request );
	}

	/**
	 * Calls a tool that is to answer.
	 *
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $tool, array $input ): array {
		$answer = $this->call( $tool, $input );
		$this->assertSame( self::OK, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$data = $answer->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['result'] );
		return $data['result'];
	}

	private function reload( WC_Product $product ): WC_Product {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'site_fixture',
			home_url(),
			self::CONNECTED_AT,
			'tenant_fixture',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_main',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}
}
