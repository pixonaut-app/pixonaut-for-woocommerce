<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Api\DesignerLinks;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\DesignerLinkChanges;
use Pixonaut\WooCommerce\Storefront\DesignerLinkWithdrawals;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

final class KeptDesignerLinksTest extends PersonalisedCartTestCase {

	private const LINK_ID = 'shl_1';

	private const LINK_URL = 'http://fake-pixonaut.test/designer/dl_1';

	private KeptDesignerLinks $links;

	public function set_up(): void {
		parent::set_up();
		$this->links = $this->plugin->designer_links();
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function script_link( string $id = self::LINK_ID, string $url = self::LINK_URL ): void {
		$this->recorder->json(
			201,
			array(
				'id'             => $id,
				'token'          => basename( $url ),
				'url'            => $url,
				'expiresAt'      => null,
				'name'           => null,
				'brandId'        => null,
				'designLimit'    => null,
				'printAreaFrame' => false,
				'placementFrame' => false,
				'locale'         => null,
			)
		);
	}

	/**
	 * The method and path of each request sent.
	 *
	 * @return list<string>
	 */
	private function sent(): array {
		return array_map(
			static fn ( array $sent ): string => $sent['method'] . ' ' . (string) wp_parse_url( $sent['url'], PHP_URL_PATH ),
			$this->recorder->requests
		);
	}

	/**
	 * @param int $product_id The product or variation.
	 * @return array<string, mixed>
	 */
	private function kept( int $product_id ): array {
		$kept = get_post_meta( $product_id, KeptDesignerLinks::META, true );
		return is_array( $kept ) ? $kept : array();
	}

	public function test_saving_a_product_mints_its_link_once(): void {
		$this->script_link();

		$this->links->saved( $this->product_id );
		$this->links->saved( $this->product_id );

		$this->assertSame( array( 'POST /v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '/designer-links' ), $this->sent() );
		$this->assertSame( self::LINK_URL, $this->kept( $this->product_id )['en']['url'] );
		$this->assertSame( self::LINK_URL, $this->links->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_refused_link_says_why_on_the_product_until_a_save_mints_it(): void {
		$this->recorder->json(
			422,
			array(
				'error'  => 'print_size_illegal',
				'side'   => 'front',
				'index'  => 0,
				'reason' => 'too_large',
			)
		);

		$this->links->saved( $this->product_id );

		$key = KeptDesignerLinks::notice_key( $this->product_id );
		$this->assertTrue( $this->plugin->notices()->has( $key ) );
		$this->assertStringContainsString( 'print sizes', $this->plugin->notices()->all()[ $key ]['message'] );
		$this->assertSame( array(), $this->kept( $this->product_id ) );

		$this->script_link();
		$this->links->saved( $this->product_id );

		$this->assertFalse( $this->plugin->notices()->has( $key ) );
	}

	public function test_turning_personalisation_off_withdraws_the_link(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		( new ProductConfig() )->save_to( $this->product() );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->links->saved( $this->product_id );

		$this->assertSame( 'DELETE /v1/share-links/' . self::LINK_ID, $this->sent()[1] );
		$this->assertSame( array(), $this->kept( $this->product_id ) );
	}

	public function test_a_renamed_product_renames_its_link(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$product = $this->product();
		$product->set_name( 'Renamed tee' );
		$product->save();
		$this->recorder->json( 200, PersonaliseButtonTest::listed( self::LINK_ID, 'Renamed tee (#' . $this->product_id . ')' ) );

		$this->links->saved( $this->product_id );

		$this->assertSame( 'PATCH /v1/share-links/' . self::LINK_ID, $this->sent()[1] );
		$this->assertSame( array( 'name' => 'Renamed tee (#' . $this->product_id . ')' ), json_decode( (string) $this->recorder->requests[1]['body'], true ) );
		$this->assertSame( 'Renamed tee (#' . $this->product_id . ')', $this->kept( $this->product_id )['en']['name'] );
	}

	public function test_each_variation_of_a_variable_product_gets_its_own_link(): void {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'M' ) );
		$attribute->set_variation( true );
		$parent->set_name( 'Personalised hoodie' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->save_to( $parent );
		$variations = array();
		foreach ( array( 'S', 'M' ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$variations[] = $variation;
		}
		DesignFixture::sell_variant( $parent );
		$this->script_link();
		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );

		$this->links->saved( $parent->get_id() );

		$this->assertSame( array(), $this->kept( $parent->get_id() ) );
		$this->assertSame( array( self::LINK_ID, 'shl_2' ), array( $this->kept( $variations[0]->get_id() )['en']['id'], $this->kept( $variations[1]->get_id() )['en']['id'] ) );
		$body = json_decode( (string) $this->recorder->requests[0]['body'], true );
		$this->assertStringContainsString( 'pixonaut_variation=' . $variations[0]->get_id(), $body['returnUrl'] );
		$this->assertStringContainsString( 'pixonaut_product=' . $parent->get_id(), $body['returnUrl'] );
	}

	public function test_the_print_area_frame_setting_reaches_the_link_and_mints_it_again(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->assertArrayNotHasKey( 'printAreaFrame', json_decode( (string) $this->recorder->requests[0]['body'], true ) );

		$this->plugin->settings()->set( Settings::PRINT_AREA_FRAME, true );
		$this->recorder->json( 200, PersonaliseButtonTest::listed( self::LINK_ID, null ) );
		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );
		$this->recorder->json( 204, array() );
		$this->links->saved( $this->product_id );

		$this->assertSame( 'shl_2', $this->kept( $this->product_id )['en']['id'] );
		$minted = array_values( array_filter( $this->recorder->requests, static fn ( array $sent ): bool => 'POST' === $sent['method'] ) );
		$this->assertCount( 2, $minted );
		$this->assertTrue( json_decode( (string) $minted[1]['body'], true )['printAreaFrame'] );
	}

	public function test_the_form_layout_setting_and_a_products_override_reach_the_link(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->assertArrayNotHasKey( 'layout', json_decode( (string) $this->recorder->requests[0]['body'], true ) );

		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );
		$this->recorder->json( 200, PersonaliseButtonTest::listed( self::LINK_ID, null ) );
		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );
		$this->recorder->json( 204, array() );
		$this->links->saved( $this->product_id );

		$product = $this->product();
		ProductConfig::config_for( $product )->with_form_layout( FormLayout::PAGE )->save_to( $product );
		$this->recorder->json( 200, PersonaliseButtonTest::listed( 'shl_2', null ) );
		$this->script_link( 'shl_3', 'http://fake-pixonaut.test/designer/dl_3' );
		$this->recorder->json( 204, array() );
		$this->links->saved( $this->product_id );

		$minted = array_values( array_filter( $this->recorder->requests, static fn ( array $sent ): bool => 'POST' === $sent['method'] ) );
		$this->assertCount( 3, $minted );
		$this->assertSame( FormLayout::STEPS, json_decode( (string) $minted[1]['body'], true )['layout'] );
		$this->assertSame( FormLayout::PAGE, json_decode( (string) $minted[2]['body'], true )['layout'] );
		$this->assertSame( 'shl_3', $this->kept( $this->product_id )['en']['id'] );
	}

	public function test_the_link_offers_the_variant_sold_alone_and_views_only_when_narrowed(): void {
		$this->script_link();

		$this->links->saved( $this->product_id );
		$body = json_decode( (string) $this->recorder->requests[0]['body'], true );

		$this->assertSame( array( BlankFixture::VARIANT_REF ), $body['variants'] );
		$this->assertSame( BlankFixture::VARIANT_REF, $body['initialVariant'] );
		$this->assertArrayNotHasKey( 'views', $body );

		$views = array_merge(
			BlankFixture::VARIANT_VIEWS,
			array(
				array(
					'id'        => 'view_model',
					'placement' => DesignFixture::PLACEMENT_ID,
					'label'     => 'On model',
				),
			)
		);
		( new ProductVariant( DesignFixture::BLANK_ID, 'v_red', 'v_red', 'Red', null, $views ) )->save_to( $this->product() );
		self::config()->with_views( array( BlankFixture::VIEW_ID ) )->save_to( $this->product() );
		$this->recorder->json( 200, PersonaliseButtonTest::listed( self::LINK_ID, null ) );
		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->links->saved( $this->product_id );
		$this->assertSame( 'POST /v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '/designer-links', $this->sent()[2] );
		$body = json_decode( (string) $this->recorder->requests[2]['body'], true );

		$this->assertSame( array( 'v_red' ), $body['variants'] );
		$this->assertSame( 'v_red', $body['initialVariant'] );
		$this->assertSame( array( BlankFixture::VIEW_ID ), $body['views'] );
	}

	public function test_a_product_that_sells_no_variant_of_its_blank_mints_no_link(): void {
		ProductVariant::delete_from( $this->product() );

		$this->links->saved( $this->product_id );

		$this->assertSame( array(), $this->sent() );
		$this->assertNull( $this->links->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );
	}

	public function test_a_link_kept_for_another_connection_is_not_used(): void {
		update_post_meta(
			$this->product_id,
			KeptDesignerLinks::META,
			array(
				'en' => array(
					'id'         => 'shl_old',
					'url'        => 'http://fake-pixonaut.test/designer/dl_old',
					'hash'       => 'any',
					'name'       => '',
					'template'   => DesignFixture::TEMPLATE_BASE_ID,
					'connection' => 'conn_other',
				),
			)
		);
		$this->script_link();

		$this->assertSame( self::LINK_URL, $this->links->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( array( 'en' ), array_keys( $this->kept( $this->product_id ) ) );
		$this->assertSame( self::LINK_ID, $this->kept( $this->product_id )['en']['id'] );
	}

	public function test_connecting_to_the_same_workspace_again_mints_a_new_link(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->plugin->connection_store()->save( self::reconnected( 'conn_again' ) );
		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );

		$this->assertSame( 'http://fake-pixonaut.test/designer/dl_2', $this->links->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertSame( 'shl_2', $this->kept( $this->product_id )['en']['id'] );
	}

	public function test_a_link_another_request_is_minting_is_waited_for_and_used(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$minted = get_post_meta( $this->product_id, KeptDesignerLinks::META, true );
		delete_post_meta( $this->product_id, KeptDesignerLinks::META );
		$product_id = $this->product_id;
		$naps       = 0;
		$waiting    = new KeptDesignerLinks(
			new DesignerLinks( $this->plugin->sdk(), new WcLogger() ),
			$this->plugin->designer_link_withdrawals(),
			$this->plugin->connection(),
			$this->plugin->settings(),
			$this->plugin->notices(),
			new WcLogger(),
			static function () use ( &$naps, $product_id, $minted ): void {
				// The other request keeps the link while this one waits.
				if ( 1 === ++$naps ) {
					update_post_meta( $product_id, KeptDesignerLinks::META, $minted );
				}
			}
		);
		$this->assertTrue( $this->links->lock( $this->product_id ) );

		$this->assertSame( self::LINK_URL, $waiting->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );

		$this->assertCount( 1, $this->recorder->requests );
		$this->links->unlock( $this->product_id );
	}

	public function test_a_link_pixonaut_could_not_be_asked_to_withdraw_is_asked_again(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		( new ProductConfig() )->save_to( $this->product() );
		$this->recorder->fail();

		$this->links->saved( $this->product_id );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
		$this->assertSame( array( self::LINK_ID ), DesignerLinkWithdrawals::pending_withdrawals() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( DesignerLinkWithdrawals::WITHDRAW_ACTION ) );

		$this->recorder->raw( 204, '', 'application/json' );
		ActionSchedulerRunner::run_once( DesignerLinkWithdrawals::WITHDRAW_ACTION );

		$this->assertSame( 'DELETE /v1/share-links/' . self::LINK_ID, $this->sent()[2] );
		$this->assertSame( array(), DesignerLinkWithdrawals::pending_withdrawals() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( DesignerLinkWithdrawals::WITHDRAW_ACTION ) );
	}

	public function test_a_replaced_link_that_could_not_be_withdrawn_waits_to_be(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		add_filter(
			'pixonaut_wc_storefront_designer_link',
			static function ( array $body ): array {
				$body['locale'] = 'sv';
				return $body;
			}
		);
		$this->recorder->json( 200, PersonaliseButtonTest::listed( self::LINK_ID, null ) );
		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );
		$this->recorder->fail();

		$this->links->saved( $this->product_id );

		$this->assertSame( 'shl_2', $this->kept( $this->product_id )['en']['id'] );
		$this->assertSame( array( self::LINK_ID ), DesignerLinkWithdrawals::pending_withdrawals() );

		$this->recorder->fail();
		ActionSchedulerRunner::run_once( DesignerLinkWithdrawals::WITHDRAW_ACTION );

		$this->assertSame( array( self::LINK_ID ), DesignerLinkWithdrawals::pending_withdrawals() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( DesignerLinkWithdrawals::WITHDRAW_ACTION ) );
	}

	public function test_disconnecting_forgets_every_link(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		update_option( DesignerLinkWithdrawals::WITHDRAWALS_OPTION, array( 'shl_withdrawn' ), false );

		do_action( 'pixonaut_wc_connection_disconnected', true );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
		$this->assertSame( array(), DesignerLinkWithdrawals::pending_withdrawals() );
	}

	/**
	 * The store's connection after connecting to the same workspace again.
	 *
	 * @param string $connection_id Pixonaut's id for the new connection.
	 */
	private static function reconnected( string $connection_id ): StoredConnection {
		$current = self::connection();
		return new StoredConnection(
			$current->site,
			$current->home_url,
			$current->connected_at,
			$current->tenant_id,
			$current->tenant_name,
			$current->environment,
			$connection_id,
			$current->integration_key,
			$current->integration_key_id,
			$current->integration_key_label,
			$current->publishable_key,
			$current->publishable_key_id,
			$current->allowed_origins,
			$current->webhook_id,
			$current->webhook_url,
			$current->webhook_secret
		);
	}

	public function test_a_link_pixonaut_no_longer_lists_is_forgotten(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->recorder->json( 200, self::page( array( PersonaliseButtonTest::listed( self::LINK_ID, null ) ) ) );

		$this->links->prune( $this->product_id );

		$this->assertSame( 'GET /v1/share-links', $this->sent()[1] );
		$this->assertStringContainsString( 'subjectId=' . DesignFixture::TEMPLATE_BASE_ID, $this->recorder->requests[1]['url'] );
		$this->assertSame( array( 'en' ), array_keys( $this->kept( $this->product_id ) ) );

		$this->recorder->json( 200, self::page( array() ) );
		$this->links->prune( $this->product_id );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
	}

	public function test_a_link_withdrawn_in_the_studio_is_forgotten_and_minted_again_when_next_used(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$other = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$other = is_int( $other ) ? $other : 0;
		update_post_meta( $other, KeptDesignerLinks::META, array( 'en' => array( 'id' => 'shl_10' ) + $this->kept( $this->product_id )['en'] ) );

		$this->changes()->handle( self::link_event( DesignerLinkChanges::REVOKED, self::LINK_ID ) );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
		$this->assertSame( 'shl_10', $this->kept( $other )['en']['id'], 'another link is kept' );
		$this->assertCount( 1, $this->recorder->requests, 'a withdrawal asks Pixonaut nothing' );

		$this->script_link( 'shl_2', 'http://fake-pixonaut.test/designer/dl_2' );
		$this->assertSame( 'http://fake-pixonaut.test/designer/dl_2', $this->links->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );
		$this->assertSame( 'POST /v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '/designer-links', $this->sent()[1] );
	}

	public function test_a_regenerated_link_keeps_its_new_address(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$regenerated = array( 'url' => 'http://fake-pixonaut.test/designer/dl_new' ) + PersonaliseButtonTest::listed( self::LINK_ID, null );
		$this->recorder->json( 200, self::page( array( $regenerated ) ) );

		$this->changes()->handle( self::link_event( DesignerLinkChanges::REGENERATED, self::LINK_ID ) );

		$this->assertStringContainsString( 'subjectId=' . DesignFixture::TEMPLATE_BASE_ID, $this->recorder->requests[1]['url'] );
		$this->assertStringContainsString( 'q=' . self::LINK_ID, $this->recorder->requests[1]['url'], 'only the links holding its id are read' );
		$kept = $this->kept( $this->product_id )['en'];
		$this->assertSame( self::LINK_ID, $kept['id'] );
		$this->assertSame( 'http://fake-pixonaut.test/designer/dl_new', $kept['url'] );
		$this->assertSame( 'http://fake-pixonaut.test/designer/dl_new', $this->links->url_for( $this->product(), ProductConfig::config_for( $this->product() ), 'en_US' ) );
		$this->assertCount( 2, $this->recorder->requests, 'the kept link is used, not minted again' );
	}

	public function test_a_regenerated_link_no_longer_listed_is_forgotten(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->recorder->json( 200, self::page( array() ) );

		$this->changes()->handle( self::link_event( DesignerLinkChanges::REGENERATED, self::LINK_ID ) );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
	}

	public function test_a_regenerated_link_whose_address_cannot_be_read_is_withdrawn_and_minted_again(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->changes()->handle( self::link_event( DesignerLinkChanges::REGENERATED, self::LINK_ID ) );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
		$this->assertSame( 'DELETE /v1/share-links/' . self::LINK_ID, $this->sent()[2] );
	}

	public function test_a_link_kept_by_a_product_in_the_bin_is_forgotten_too(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		wp_trash_post( $this->product_id );

		$this->changes()->handle( self::link_event( DesignerLinkChanges::REVOKED, self::LINK_ID ) );

		$this->assertSame( array(), $this->kept( $this->product_id ) );
	}

	public function test_a_change_waits_for_a_mint_in_progress(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->assertTrue( $this->links->lock( $this->product_id ) );

		try {
			$this->changes()->handle( self::link_event( DesignerLinkChanges::REVOKED, self::LINK_ID ) );
			$this->fail( 'The change did not wait for the mint.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( self::LINK_ID, $this->kept( $this->product_id )['en']['id'], 'nothing is changed meanwhile' );
		} finally {
			$this->links->unlock( $this->product_id );
		}
	}

	public function test_an_event_about_a_link_no_product_keeps_asks_pixonaut_nothing(): void {
		$this->changes()->handle( self::link_event( DesignerLinkChanges::REGENERATED, 'shl_unknown' ) );

		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_an_event_about_another_kind_of_link_changes_nothing(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );

		$this->changes()->handle( self::link_event( DesignerLinkChanges::REVOKED, self::LINK_ID, 'proof-page' ) );

		$this->assertSame( self::LINK_ID, $this->kept( $this->product_id )['en']['id'] );
	}

	private function changes(): DesignerLinkChanges {
		return new DesignerLinkChanges( $this->links, new DesignerLinks( $this->plugin->sdk(), new WcLogger() ), new WcLogger() );
	}

	private static function link_event( string $type, string $link_id, string $kind = DesignerLinks::KIND ): Event {
		return Payload::event(
			'evt_link',
			$type,
			Milliseconds::of( time() ),
			array(
				'linkId'    => $link_id,
				'kind'      => $kind,
				'subjectId' => DesignFixture::TEMPLATE_BASE_ID,
				'name'      => null,
				'by'        => 'member',
			)
		);
	}

	public function test_deleting_a_product_withdraws_its_link(): void {
		$this->script_link();
		$this->links->saved( $this->product_id );
		$this->recorder->raw( 204, '', 'application/json' );

		wp_delete_post( $this->product_id, true );

		$this->assertSame( 'DELETE /v1/share-links/' . self::LINK_ID, $this->sent()[1] );
	}

	/**
	 * @param list<array<string, mixed>> $links The page's links.
	 * @return array<string, mixed>
	 */
	private static function page( array $links ): array {
		return array(
			'links'    => $links,
			'total'    => count( $links ),
			'page'     => 1,
			'pageSize' => 100,
			'counts'   => array(
				'kinds'        => array(),
				'sources'      => array(),
				'carriesVisit' => 0,
			),
		);
	}
}
