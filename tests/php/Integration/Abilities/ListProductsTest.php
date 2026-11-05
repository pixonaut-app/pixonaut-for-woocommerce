<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\ProductSetup;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * `list-products` as the Abilities API runs it: each product's Pixonaut
 * setup read from what the store keeps, Pixonaut not asked.
 */
final class ListProductsTest extends WP_UnitTestCase {

	private const LIST_PRODUCTS = Abilities::NAMESPACE . '/list-products';

	/**
	 * Any attachment id, for a picture record.
	 */
	private const PICTURE_ATTACHMENT = 4242;

	/**
	 * A variable product's variations on one page of the answer.
	 */
	private const VARIATIONS_PER_PAGE = 50;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		wp_set_current_user( $this->user( 'shop_manager' ) );
	}

	public function test_a_product_gives_its_templates_print_sizes_blank_and_variant(): void {
		$sign = $this->product( 'Student sign' );
		self::config( 'student-sign', 3, 500, 700, 'blank_board', 'front' )->save_to( $sign );
		( new ProductVariant( 'blank_board', 'v_white', 'v_white', 'White' ) )->save_to( $sign );

		$answer = $this->list( array( 'product_id' => $sign->get_id() ) );

		$this->assertSame( 1, $answer['total'] );
		$this->assertFalse( $answer['more'] );
		$product = $answer['products'][0];
		$this->assertSame( $sign->get_id(), $product['product_id'] );
		$this->assertSame( 'Student sign', $product['name'] );
		$this->assertSame( 'simple', $product['type'] );
		$this->assertTrue( $product['linked'] );
		$this->assertTrue( $product['personalised'] );
		$this->assertTrue( $product['valid'] );
		$this->assertNull( $product['invalid_reason'] );
		$this->assertSame( 'blank_board', $product['blank_id'] );
		$this->assertSame(
			array(
				'ref'     => 'v_white',
				'label'   => 'White',
				'picked'  => true,
				'problem' => null,
			),
			$product['variant']
		);
		$this->assertSame(
			array(
				array(
					'key'              => 'front',
					'label'            => 'Front',
					'mode'             => Side::PERSONALISED,
					'template_id'      => 'student-sign@3',
					'template_pinned'  => false,
					'placement_id'     => 'front',
					'print_sizes'      => array(
						array(
							'width_mm'          => 500.0,
							'height_mm'         => 700.0,
							'label'             => '500 × 700 mm',
							'placement_default' => false,
						),
					),
					'start_size'       => 0,
					'form_groups'      => null,
					'canvas_ratio'     => CanvasRatio::FOLLOW,
					'template_name'    => 'student-sign',
					'placement_name'   => 'Front',
					'standing_options' => array(),
				),
			),
			$product['sides']
		);
		$this->assertSame( array(), $product['variations'] );
	}

	public function test_a_product_gives_its_price_sku_image_and_page_and_scheduled_ones_are_listed(): void {
		$sign = $this->product( 'Student sign' );
		$sign->set_sku( 'SIGN-1' );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $image );
		$sign->set_image_id( $image );
		$sign->save();
		$scheduled = $this->product( 'Spring sign' );
		$scheduled->set_status( 'future' );
		$scheduled->set_date_created( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$scheduled->save();
		self::config( 'student-sign', 3, 500, 700 )->save_to( $scheduled );

		$listed = $this->list()['products'];

		$this->assertSame( array( $sign->get_id(), $scheduled->get_id() ), array_column( $listed, 'product_id' ) );
		$this->assertSame( '25', $listed[0]['price'] );
		$this->assertSame( 'SIGN-1', $listed[0]['sku'] );
		$this->assertSame( wp_get_attachment_image_url( $image, 'full' ), $listed[0]['image'] );
		$this->assertSame( get_permalink( $sign->get_id() ), $listed[0]['permalink'] );
		$this->assertNull( $listed[1]['image'] );
		$this->assertSame( 'future', $listed[1]['status'] );
		$this->assertSame( array( $scheduled->get_id() ), array_column( $this->list( array( 'linked_only' => true ) )['products'], 'product_id' ), 'a scheduled product is found by its link too' );
	}

	public function test_products_filter_by_link_and_template_any_version_a_page_at_a_time(): void {
		$plain = $this->product( 'Plain mug' );
		$sign  = $this->product( 'Student sign' );
		self::config( 'student-sign', 3, 500, 700 )->save_to( $sign );
		$tee = $this->product( 'Tee' );
		self::config( 'mini-tee', 2, 300, 300 )->save_to( $tee );
		$unlinked = $this->product( 'Old sign' );
		self::config( 'student-sign', 1, 500, 700, personalise: false )->save_to( $unlinked );
		$trashed = $this->product( 'Trashed sign' );
		self::config( 'student-sign', 3, 500, 700 )->save_to( $trashed );
		$trashed->delete();

		$all = $this->list();
		$this->assertSame( array( $plain->get_id(), $sign->get_id(), $tee->get_id(), $unlinked->get_id() ), array_column( $all['products'], 'product_id' ) );
		$this->assertFalse( $all['products'][0]['linked'] );
		$this->assertSame( array(), $all['products'][0]['sides'] );

		$linked = $this->list( array( 'linked_only' => true ) );
		$this->assertSame( array( $sign->get_id(), $tee->get_id(), $unlinked->get_id() ), array_column( $linked['products'], 'product_id' ) );
		$this->assertFalse( $linked['products'][2]['personalised'] );

		$this->assertSame( array( $sign->get_id(), $unlinked->get_id() ), array_column( $this->list( array( 'template_id' => 'student-sign@1' ) )['products'], 'product_id' ) );
		$this->assertSame( array( $tee->get_id() ), array_column( $this->list( array( 'template_id' => 'mini-tee' ) )['products'], 'product_id' ) );
		$this->assertSame( array(), $this->list( array( 'template_id' => 'gone' ) )['products'] );
		$this->assertSame(
			array(),
			$this->list(
				array(
					'product_id'  => $plain->get_id(),
					'linked_only' => true,
				)
			)['products']
		);

		$first = $this->list(
			array(
				'linked_only' => true,
				'per_page'    => 2,
			)
		);
		$this->assertSame( 3, $first['total'] );
		$this->assertTrue( $first['more'] );
		$last = $this->list(
			array(
				'linked_only' => true,
				'per_page'    => '2',
				'page'        => '2',
			)
		);
		$this->assertSame( array( $unlinked->get_id() ), array_column( $last['products'], 'product_id' ) );
		$this->assertFalse( $last['more'] );
	}

	public function test_a_variable_product_gives_each_enabled_variations_setup_and_is_found_by_a_variations_template(): void {
		$poster = new WC_Product_Variable();
		$poster->set_name( 'Poster' );
		$poster->save();
		self::config( 'poster', 1, 400, 400 )->save_to( $poster );
		$follows = $this->variation( $poster, 'publish' );
		$own     = $this->variation( $poster, 'publish' );
		self::config( 'big-poster', 2, 800, 800, override: true )->save_to( $own );
		self::config( 'hidden', 1, 100, 100, override: true )->save_to( $this->variation( $poster, 'private' ) );

		$answer = $this->list( array( 'template_id' => 'big-poster' ) );

		$this->assertSame( array( $poster->get_id() ), array_column( $answer['products'], 'product_id' ) );
		$variations = $answer['products'][0]['variations'];
		$this->assertSame( array( $follows->get_id(), $own->get_id() ), array_column( $variations, 'variation_id' ) );
		$this->assertFalse( $variations[0]['own_setup'] );
		$this->assertSame( 'poster@1', $variations[0]['sides'][0]['template_id'] );
		$this->assertTrue( $variations[1]['own_setup'] );
		$this->assertSame( 'big-poster@2', $variations[1]['sides'][0]['template_id'] );
		$this->assertSame( 800.0, $variations[1]['sides'][0]['print_sizes'][0]['width_mm'] );
	}

	public function test_a_stored_variant_of_another_blank_or_of_attributes_it_no_longer_has_is_none(): void {
		$other_blank = $this->product( 'Sign on another blank' );
		self::config( 'student-sign', 3, 500, 700, 'blank_board', 'front' )->save_to( $other_blank );
		( new ProductVariant( 'blank_old', 'v_white', 'v_white', 'White' ) )->save_to( $other_blank );
		$stale = $this->product( 'Sign matched on a colour it lost' );
		self::config( 'student-sign', 3, 500, 700, 'blank_board', 'front' )->save_to( $stale );
		( new ProductVariant( 'blank_board', null, 'v_red', 'Red', matched_on: array( 'Colour' => 'Red' ) ) )->save_to( $stale );

		foreach ( array( $other_blank, $stale ) as $product ) {
			$this->assertSame(
				array(
					'ref'     => null,
					'label'   => '',
					'picked'  => false,
					'problem' => ProductVariant::UNMAPPED,
				),
				$this->list( array( 'product_id' => $product->get_id() ) )['products'][0]['variant'],
				$product->get_name()
			);
		}
	}

	public function test_a_variable_products_variations_come_a_page_at_a_time(): void {
		$poster = new WC_Product_Variable();
		$poster->set_name( 'Poster' );
		$poster->save();
		self::config( 'poster', 1, 400, 400 )->save_to( $poster );
		$ids = array();
		for ( $made = 0; $made <= self::VARIATIONS_PER_PAGE; $made++ ) {
			$ids[] = $this->variation( $poster, 'publish' )->get_id();
		}

		$first = $this->list( array( 'product_id' => $poster->get_id() ) )['products'][0];
		$this->assertSame( self::VARIATIONS_PER_PAGE + 1, $first['variations_total'] );
		$this->assertTrue( $first['more_variations'] );
		$this->assertSame( array_slice( $ids, 0, self::VARIATIONS_PER_PAGE ), array_column( $first['variations'], 'variation_id' ) );
		$second = $this->list(
			array(
				'product_id'      => $poster->get_id(),
				'variations_page' => 2,
			)
		)['products'][0];
		$this->assertSame( array( $ids[ self::VARIATIONS_PER_PAGE ] ), array_column( $second['variations'], 'variation_id' ) );
		$this->assertFalse( $second['more_variations'] );
	}

	public function test_the_picture_says_where_it_stands(): void {
		$sign = $this->product( 'Student sign' );
		self::config( 'student-sign', 3, 500, 700 )->save_to( $sign );
		$this->assertSame( ProductSetup::PICTURE_PENDING, $this->picture( $sign ) );

		$this->record_picture( $sign, self::PICTURE_ATTACHMENT, null );
		$this->assertSame( ProductSetup::PICTURE_DRAWN, $this->picture( $sign ) );

		$this->record_picture( $sign, self::PICTURE_ATTACHMENT, time() );
		$this->assertSame( ProductSetup::PICTURE_FAILED, $this->picture( $sign ) );

		$this->record_picture( $sign, 0, null );
		$this->assertSame( ProductSetup::PICTURE_NONE, $this->picture( $sign ) );

		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );
		try {
			$this->assertSame( ProductSetup::PICTURE_OFF, $this->picture( $sign ) );
		} finally {
			$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, true );
		}
	}

	public function test_customers_may_not_list_products(): void {
		wp_set_current_user( $this->user( 'customer' ) );

		$this->assertInstanceOf( WP_Error::class, $this->ability()->execute() );
	}

	/**
	 * @param array<string, mixed> $input The ability's input.
	 * @return array{products: list<array<string, mixed>>, total: int, more: bool}
	 */
	private function list( array $input = array() ): array {
		$answer = $this->ability()->execute( $input );
		$this->assertIsArray( $answer );
		/** @var array{products: list<array<string, mixed>>, total: int, more: bool} $answer */
		return $answer;
	}

	private function picture( WC_Product $product ): string {
		$picture = $this->list( array( 'product_id' => $product->get_id() ) )['products'][0]['picture'];
		$this->assertIsString( $picture );
		return $picture;
	}

	/**
	 * Records a drawing of the product's picture as it is now.
	 *
	 * @param WC_Product $product    The product.
	 * @param int        $attachment The picture; 0 for none.
	 * @param int|null   $failed_at  When drawing it failed; null when it did not.
	 */
	private function record_picture( WC_Product $product, int $attachment, ?int $failed_at ): void {
		$saved = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $saved );
		$saved->update_meta_data(
			ProductPictures::META_KEY,
			(string) wp_json_encode(
				array(
					'basis'      => ProductPictures::basis( $saved ),
					'source'     => null,
					'attachment' => $attachment,
					'failed_at'  => $failed_at,
					'gallery'    => array(),
					'gone'       => array(),
				)
			)
		);
		$saved->save_meta_data();
	}

	private function ability(): WP_Ability {
		$ability = wp_get_ability( self::LIST_PRODUCTS );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	/**
	 * A one-sided configuration sold in one print size, on a blank's front or template-only.
	 *
	 * @param string $base_id     The template's base id.
	 * @param int    $version     Its version.
	 * @param float  $width_mm    Width in millimetres.
	 * @param float  $height_mm   Height in millimetres.
	 * @param string $blank_id    The blank; empty for a template-only product.
	 * @param string $placement   The blank's placement.
	 * @param bool   $personalise Whether it personalises the product.
	 * @param bool   $override    Whether a variation's own configuration applies.
	 */
	private static function config( string $base_id, int $version, float $width_mm, float $height_mm, string $blank_id = '', string $placement = '', bool $personalise = true, bool $override = false ): ProductConfig {
		$on_blank = '' !== $blank_id;
		return new ProductConfig(
			personalise: $personalise,
			override: $override,
			blank_id: $on_blank ? $blank_id : null,
			sides: array(
				new Side(
					$on_blank ? $placement : 'design',
					$on_blank ? 'Front' : 'Design',
					$on_blank ? $placement : null,
					$on_blank ? 'Front' : null,
					$base_id,
					$version,
					null,
					$base_id,
					array( new PrintSize( $width_mm, $height_mm, '' ) )
				),
			),
		);
	}

	private function product( string $name ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '25' );
		$product->save();
		return $product;
	}

	/**
	 * @param WC_Product_Variable $product The variable product.
	 * @param string              $status  `publish` for an enabled variation, `private` for a disabled one.
	 */
	private function variation( WC_Product_Variable $product, string $status ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '25' );
		$variation->set_status( $status );
		$variation->save();
		return $variation;
	}

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}
}
