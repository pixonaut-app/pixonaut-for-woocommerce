<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\Store\ResourceAbility;
use Pixonaut\WooCommerce\Abilities\Store\StoreResources;
use Pixonaut\WooCommerce\Plugin;
use WC_Shipping_Zone;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * The abilities that manage the store's own things through its REST routes,
 * as the Abilities API runs them for Pixel: each refused to a user without
 * its capability, each run as the user chatting.
 */
final class StoreResourcesTest extends WP_UnitTestCase {

	/**
	 * A one-pixel PNG, as a media file's URL answers it.
	 */
	private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	private const MEDIA_URL = 'https://cdn.example.test/pictures/summer-mug';

	private const OK = 200;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request', 1 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	public function test_every_store_ability_is_refused_to_a_user_without_its_capability(): void {
		wp_set_current_user( $this->user( 'subscriber' ) );
		$abilities = StoreResources::abilities();
		$this->assertGreaterThan( 50, count( $abilities ) );
		foreach ( $abilities as $definition ) {
			$name    = Abilities::name( $definition );
			$ability = $this->ability( $name );
			$this->assertFalse( $ability->check_permissions( array( 'resource' => 'coupon' ) ), $name );
		}
		$refused = $this->ability( 'pixonaut-for-woocommerce/create-product-category' )->execute( array( 'fields' => array( 'name' => 'Mugs' ) ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame(
			array(),
			get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'name'       => 'Mugs',
					'hide_empty' => false,
				)
			)
		);
	}

	public function test_an_editor_may_write_a_page_but_not_a_coupon_nor_a_shipping_zone(): void {
		wp_set_current_user( $this->user( 'editor' ) );

		$page = $this->ability( 'pixonaut-for-woocommerce/create-page' )->execute(
			array(
				'fields' => array(
					'title'  => 'About our mugs',
					'status' => 'draft',
				),
			)
		);
		$this->assertIsArray( $page );
		$this->assertSame( 'About our mugs', get_the_title( $page['id'] ) );

		$this->assertFalse( $this->ability( 'pixonaut-for-woocommerce/create-coupon' )->check_permissions( array() ) );
		$this->assertFalse( $this->ability( 'pixonaut-for-woocommerce/create-shipping-zone' )->check_permissions( array() ) );
	}

	public function test_an_author_may_change_their_own_post_but_not_anothers(): void {
		$author = $this->user( 'author' );
		$own    = $this->post( $author );
		$other  = $this->post( $this->user( 'author' ) );
		wp_set_current_user( $author );
		$update = $this->ability( 'pixonaut-for-woocommerce/update-post' );
		$change = static fn ( int $id ): array => array(
			'id'     => $id,
			'fields' => array( 'title' => 'Changed by Pixel' ),
		);

		$this->assertTrue( $update->check_permissions( $change( $own ) ) );
		$this->assertFalse( $update->check_permissions( $change( $other ) ) );
		$refused = $update->execute( $change( $other ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'ability_invalid_permissions', $refused->get_error_code() );
		$this->assertNotSame( 'Changed by Pixel', get_the_title( $other ) );
		// An item that is not there is the route's to say, not a refusal of the user.
		$this->assertTrue( $update->check_permissions( $change( PHP_INT_MAX ) ) );
	}

	public function test_a_shop_manager_creates_reads_updates_and_deletes_a_product_category(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );

		$created = $this->ability( 'pixonaut-for-woocommerce/create-product-category' )->execute( array( 'fields' => array( 'name' => 'Mugs' ) ) );
		$this->assertIsArray( $created );
		$id   = $created['id'];
		$term = get_term( $id, 'product_cat' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'Mugs', $term->name );

		$updated = $this->ability( 'pixonaut-for-woocommerce/update-product-category' )->execute(
			array(
				'id'     => $id,
				'fields' => array( 'description' => 'Every mug we print.' ),
			)
		);
		$this->assertSame( 'Every mug we print.', $updated['description'] );

		$listed = $this->ability( 'pixonaut-for-woocommerce/list-product-categories' )->execute( array( 'query' => array( 'search' => 'Mugs' ) ) );
		$this->assertSame( array( $id ), array_column( $listed['items'], 'id' ) );
		$this->assertSame( 1, $listed['total'] );

		$deleted = $this->ability( 'pixonaut-for-woocommerce/delete-product-category' )->execute(
			array(
				'id'    => $id,
				'force' => true,
			)
		);
		$this->assertIsArray( $deleted );
		$this->assertNull( get_term( $id, 'product_cat' ) );
	}

	public function test_a_shipping_zone_gets_its_countries_and_a_flat_rate(): void {
		wp_set_current_user( $this->user( 'administrator' ) );

		$zone = $this->ability( 'pixonaut-for-woocommerce/create-shipping-zone' )->execute( array( 'fields' => array( 'name' => 'Nordics' ) ) );
		$this->assertArrayNotHasKey( '_links', $zone );
		$this->ability( 'pixonaut-for-woocommerce/update-shipping-zone-locations' )->execute(
			array(
				'zone_id' => $zone['id'],
				'items'   => array(
					array(
						'code' => 'SE',
						'type' => 'country',
					),
					array(
						'code' => 'NO',
						'type' => 'country',
					),
				),
			)
		);
		$this->ability( 'pixonaut-for-woocommerce/create-shipping-zone-method' )->execute(
			array(
				'zone_id' => $zone['id'],
				'fields'  => array( 'method_id' => 'flat_rate' ),
			)
		);

		$saved = new WC_Shipping_Zone( $zone['id'] );
		$this->assertSame( array( 'SE', 'NO' ), array_column( $saved->get_zone_locations(), 'code' ) );
		$this->assertSame( array( 'flat_rate' ), array_values( array_map( static fn ( $method ): string => $method->id, $saved->get_shipping_methods() ) ) );
	}

	public function test_the_zone_for_locations_no_other_zone_covers_gets_a_flat_rate(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$rest_of_the_world = 0;

		$zone = $this->ability( 'pixonaut-for-woocommerce/get-shipping-zone' )->execute( array( 'id' => $rest_of_the_world ) );
		$this->assertIsArray( $zone );
		$this->assertSame( $rest_of_the_world, $zone['id'] );
		$this->assertSame( 0, $this->ability( 'pixonaut-for-woocommerce/list-shipping-zone-methods' )->execute( array( 'zone_id' => $rest_of_the_world ) )['total'] );

		$method = $this->ability( 'pixonaut-for-woocommerce/create-shipping-zone-method' )->execute(
			array(
				'zone_id' => (string) $rest_of_the_world,
				'fields'  => array( 'method_id' => 'flat_rate' ),
			)
		);
		$this->assertIsArray( $method );
		$this->assertSame( array( 'flat_rate' ), array_column( $this->ability( 'pixonaut-for-woocommerce/list-shipping-zone-methods' )->execute( array( 'zone_id' => $rest_of_the_world ) )['items'], 'method_id' ) );

		$deleted = $this->ability( 'pixonaut-for-woocommerce/delete-shipping-zone-method' )->execute(
			array(
				'zone_id' => $rest_of_the_world,
				'id'      => $method['instance_id'],
				'force'   => true,
			)
		);
		$this->assertIsArray( $deleted );
		$this->assertSame( array(), ( new WC_Shipping_Zone( $rest_of_the_world ) )->get_shipping_methods() );
	}

	public function test_a_store_setting_is_read_and_changed_in_its_group(): void {
		wp_set_current_user( $this->user( 'administrator' ) );

		$this->ability( 'pixonaut-for-woocommerce/update-store-setting' )->execute(
			array(
				'group_id' => 'general',
				'id'       => 'woocommerce_store_city',
				'fields'   => array( 'value' => 'Malmö' ),
			)
		);

		$this->assertSame( 'Malmö', get_option( 'woocommerce_store_city' ) );
		$read = $this->ability( 'pixonaut-for-woocommerce/get-store-setting' )->execute(
			array(
				'group_id' => 'general',
				'id'       => 'woocommerce_store_city',
			)
		);
		$this->assertSame( 'Malmö', $read['value'] );
	}

	public function test_a_media_file_is_downloaded_from_its_url_into_the_library(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		add_filter(
			'pre_http_request',
			static function ( $response, array $args, string $url ) {
				if ( self::MEDIA_URL !== $url ) {
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
			1,
			3
		);

		$media = $this->ability( 'pixonaut-for-woocommerce/create-media' )->execute(
			array(
				'fields' => array(
					'source_url' => self::MEDIA_URL,
					'alt_text'   => 'A summer mug',
				),
			)
		);

		$this->assertIsArray( $media );
		$this->assertSame( 'image/png', get_post_mime_type( $media['id'] ) );
		// Named from the URL, with the extension its type gives; the uploads folder outlives a test, so a number may follow.
		$this->assertMatchesRegularExpression( '/summer-mug(-[0-9]+)?\.png$/', (string) get_attached_file( $media['id'] ) );
		$this->assertSame( 'A summer mug', get_post_meta( $media['id'], '_wp_attachment_image_alt', true ) );
	}

	public function test_a_call_missing_its_parent_is_refused_saying_what_to_name(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$ability = StoreResources::ability( 'pixonaut-for-woocommerce/get-product-variation' );
		$this->assertInstanceOf( ResourceAbility::class, $ability );

		$refused = $ability->execute( array( 'id' => 3 ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'Name product_id, id.', $refused->get_error_message() );
	}

	public function test_a_resource_is_described_with_only_the_fields_a_create_may_set(): void {
		wp_set_current_user( $this->user( 'administrator' ) );

		$described = $this->ability( 'pixonaut-for-woocommerce/describe-store-resource' )->execute( array( 'resource' => 'coupon' ) );

		$this->assertSame( 'wc/v3/coupons', $described['route'] );
		$this->assertArrayHasKey( 'code', $described['fields'] );
		$this->assertArrayHasKey( 'discount_type', $described['fields'] );
		$this->assertArrayNotHasKey( 'usage_count', $described['fields'] );
		$this->assertArrayNotHasKey( 'context', $described['fields']['code'] );
	}

	public function test_a_create_names_the_describe_tool_as_mcp_serves_it(): void {
		$description = $this->ability( 'pixonaut-for-woocommerce/create-coupon' )->get_description();

		$this->assertStringContainsString( 'pixonaut-for-woocommerce-describe-store-resource gives every field', $description );
	}

	public function test_every_store_ability_declares_what_it_answers(): void {
		foreach ( StoreResources::abilities() as $definition ) {
			$name = Abilities::name( $definition );
			$this->assertNotSame( array(), $this->ability( $name )->get_output_schema(), $name );
		}
	}

	public function test_dates_are_answered_with_their_offset(): void {
		update_option( 'timezone_string', 'Europe/Stockholm' );
		wp_set_current_user( $this->user( 'administrator' ) );
		$post = self::factory()->post->create(
			array(
				'post_date'  => '2026-10-31 14:00:00',
				'post_title' => '2026-10-31T09:00:00',
			)
		);

		$read   = $this->ability( 'pixonaut-for-woocommerce/get-post' )->execute( array( 'id' => $post ) );
		$listed = $this->ability( 'pixonaut-for-woocommerce/list-posts' )->execute( array( 'query' => array( 'include' => array( $post ) ) ) );

		$this->assertIsArray( $read );
		$this->assertSame( '2026-10-31T14:00:00+01:00', $read['date'] );
		$this->assertSame( '2026-10-31T13:00:00+00:00', $read['date_gmt'] );
		$this->assertSame( '2026-10-31T14:00:00+01:00', $listed['items'][0]['date'] );
		// Only the fields the route declares dates: a title that looks like one is kept as it is.
		$this->assertSame( '2026-10-31T09:00:00', $read['title']['rendered'] );
		delete_option( 'timezone_string' );
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	private function post( int $author ): int {
		$id = self::factory()->post->create( array( 'post_author' => $author ) );
		return is_int( $id ) ? $id : 0;
	}

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}
}
