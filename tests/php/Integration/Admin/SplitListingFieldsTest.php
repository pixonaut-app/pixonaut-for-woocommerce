<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\SplitListingFields;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WP_UnitTestCase;

final class SplitListingFieldsTest extends WP_UnitTestCase {

	/**
	 * The attribute whose values are listed as their own products in the
	 * owner's shop: with and without a photo.
	 */
	private const PICTURE = 'Bild';

	private const SIZE = 'Posterstorlek';

	private SplitListingFields $fields;

	/**
	 * Attribute ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $attributes = array();

	public function set_up(): void {
		parent::set_up();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$this->fields = new SplitListingFields( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ) );
		$this->fields->register();
		SplitListing::register();
		$this->attributes[ self::PICTURE ] = $this->attribute( self::PICTURE, array( 'Med bild', 'Utan bild' ) );
		$this->attributes[ self::SIZE ]    = $this->attribute( self::SIZE, array( '30x40', '50x70' ) );
	}

	public function tear_down(): void {
		foreach ( array_keys( $this->attributes ) as $name ) {
			$taxonomy = wc_attribute_taxonomy_name( $name );
			unregister_taxonomy( $taxonomy );
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( SplitListing::OPTION );
		$_POST = array();
		$_GET  = array();
		unset( $GLOBALS['product_object'] );
		parent::tear_down();
	}

	public function test_the_attribute_screens_turn_its_default_on_and_off(): void {
		$_POST = $this->nonced( array( SplitListingFields::ATTRIBUTE_FIELD => '1' ) );
		$id    = $this->attribute( 'Ram', array( 'Svart' ) );
		$this->assertTrue( SplitListing::attribute_default( $id ), 'added with the box ticked' );
		$this->assertFalse( SplitListing::attribute_default( $this->attributes[ self::PICTURE ] ), 'another attribute keeps its own' );

		$_POST = $this->nonced( array() );
		wc_update_attribute( $id, array( 'name' => 'Ram' ) );
		$this->assertFalse( SplitListing::attribute_default( $id ), 'saved with the box cleared' );

		$_POST = array( SplitListingFields::ATTRIBUTE_FIELD => '1' );
		wc_update_attribute( $id, array( 'name' => 'Ram' ) );
		$this->assertFalse( SplitListing::attribute_default( $id ), 'an update not from the screen leaves it' );

		$_POST = $this->nonced( array( SplitListingFields::ATTRIBUTE_FIELD => '1' ) );
		wc_update_attribute( $id, array( 'name' => 'Ram' ) );
		$this->assertTrue( SplitListing::attribute_default( $id ) );

		wc_delete_attribute( $id );
		$this->assertFalse( SplitListing::attribute_default( $id ), 'a deleted attribute is forgotten' );
		$this->assertFalse( get_option( SplitListing::OPTION ), 'the option goes with the last one' );
	}

	public function test_the_attribute_screens_show_the_checkbox_as_set(): void {
		ob_start();
		$this->fields->render_add();
		$added = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . SplitListingFields::ATTRIBUTE_FIELD . '"', $added );
		$this->assertStringNotContainsString( 'checked', $added );
		$this->assertStringContainsString( 'name="' . SplitListingFields::NONCE_FIELD . '"', $added );

		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$_GET['edit'] = (string) $this->attributes[ self::PICTURE ];
		ob_start();
		$this->fields->render_edit();
		$edited = (string) ob_get_clean();
		$this->assertStringContainsString( '<tr class="form-field pixonaut-split-attribute">', $edited );
		$this->assertStringContainsString( "checked='checked'", $edited );
	}

	public function test_a_product_saves_each_global_attributes_choice(): void {
		$product = $this->poster();
		$_POST   = $this->nonced(
			array(
				'attribute_names'                 => array( 'pa_bild', 'pa_posterstorlek', 'Ram' ),
				SplitListingFields::PRODUCT_FIELD => array( 'on', 'auto', 'off' ),
			)
		);

		do_action( 'woocommerce_process_product_meta', $product->get_id() );

		$this->assertSame( array( $this->attributes[ self::PICTURE ] => SplitListing::ON ), $this->stored( $product )->split_listing, 'Auto and a custom attribute store nothing' );

		$_POST[ SplitListingFields::PRODUCT_FIELD ] = array( 'auto', 'off', 'on' );
		do_action( 'woocommerce_process_product_meta', $product->get_id() );
		$this->assertSame( array( $this->attributes[ self::SIZE ] => SplitListing::OFF ), $this->stored( $product )->split_listing, 'a row back on Auto loses its choice' );
	}

	public function test_a_product_save_without_the_rows_or_their_nonce_keeps_the_choices(): void {
		$product = $this->poster();
		ProductConfig::stored_on( $product )->with_split_listing( array( $this->attributes[ self::PICTURE ] => SplitListing::ON ) )->save_to( $product );

		$_POST = array(
			'attribute_names'                 => array( 'pa_bild' ),
			SplitListingFields::PRODUCT_FIELD => array( 'off' ),
		);
		do_action( 'woocommerce_process_product_meta', $product->get_id() );
		$_POST = $this->nonced( array( 'attribute_names' => array( 'pa_bild' ) ) );
		do_action( 'woocommerce_process_product_meta', $product->get_id() );

		$this->assertSame( array( $this->attributes[ self::PICTURE ] => SplitListing::ON ), $this->stored( $product )->split_listing );
	}

	public function test_a_product_saved_without_an_attribute_forgets_its_choice(): void {
		$product = $this->poster();
		$this->choose(
			$product,
			array(
				$this->attributes[ self::PICTURE ] => SplitListing::ON,
				$this->attributes[ self::SIZE ]    => SplitListing::OFF,
			)
		);
		$fresh = $this->fresh( $product );
		$fresh->set_attributes( array( $this->product_attribute( self::SIZE, true ) ) );
		$fresh->save();
		// Its last row for Bild removed: no row of it, and no nonce with it, is posted.
		$_POST = array();

		do_action( 'woocommerce_process_product_meta', $product->get_id() );

		$this->assertSame( array( $this->attributes[ self::SIZE ] => SplitListing::OFF ), $this->stored( $product )->split_listing );
	}

	public function test_saving_unchanged_choices_stores_no_configuration(): void {
		$product = $this->poster();
		$_POST   = $this->nonced(
			array(
				'attribute_names'                 => array( 'pa_bild' ),
				SplitListingFields::PRODUCT_FIELD => array( 'auto' ),
			)
		);

		do_action( 'woocommerce_process_product_meta', $product->get_id() );

		$this->assertFalse( ProductConfig::is_stored_on( $this->fresh( $product ) ) );
	}

	public function test_the_save_attributes_button_saves_the_choices(): void {
		$product = $this->poster();
		remove_all_actions( 'wp_ajax_woocommerce_save_attributes' );
		$this->fields->register();
		$form                 = $this->nonced(
			array(
				'attribute_names'                 => array( 'pa_bild' ),
				SplitListingFields::PRODUCT_FIELD => array( 'off' ),
			)
		);
		$_POST                = array(
			'data'    => http_build_query( $form ),
			'post_id' => (string) $product->get_id(),
		);
		$_REQUEST['security'] = wp_create_nonce( 'save-attributes' );

		do_action( 'wp_ajax_woocommerce_save_attributes' );
		unset( $_REQUEST['security'] );

		$this->assertSame( array( $this->attributes[ self::PICTURE ] => SplitListing::OFF ), $this->stored( $product )->split_listing );
	}

	public function test_a_product_saves_each_values_card_title_and_image(): void {
		$product = $this->poster();
		$image   = $this->image();
		$with    = $this->term( 'med-bild' );
		$without = $this->term( 'utan-bild' );
		// As WordPress posts a form: slashed.
		$_POST = wp_slash(
			$this->nonced(
				array(
					'attribute_names'                 => array( 'pa_bild' ),
					SplitListingFields::PRODUCT_FIELD => array( 'on' ),
					SplitListingFields::CARD_FIELD    => array(
						$with    => array(
							'title' => " Safari's <b>photo</b> ",
							'image' => (string) $image,
						),
						$without => array(
							'title' => '',
							'image' => '',
						),
					),
				)
			)
		);

		do_action( 'woocommerce_process_product_meta', $product->get_id() );

		$this->assertSame(
			array(
				$with => array(
					'title' => "Safari's photo",
					'image' => $image,
				),
			),
			SplitCardLooks::stored_on( $product->get_id() ),
			'plain text, and a value with neither is left out'
		);

		$_POST[ SplitListingFields::CARD_FIELD ] = array(
			$with => array(
				'title' => '',
				'image' => '',
			),
		);
		do_action( 'woocommerce_process_product_meta', $product->get_id() );
		$this->assertSame( array(), get_post_meta( $product->get_id(), SplitCardLooks::META, false ), 'nothing is kept once every card has neither' );
	}

	public function test_the_save_attributes_button_saves_the_cards(): void {
		$product = $this->poster();
		remove_all_actions( 'wp_ajax_woocommerce_save_attributes' );
		$this->fields->register();
		$with                 = $this->term( 'med-bild' );
		$form                 = $this->nonced(
			array(
				'attribute_names'                 => array( 'pa_bild' ),
				SplitListingFields::PRODUCT_FIELD => array( 'auto' ),
				SplitListingFields::CARD_FIELD    => array(
					$with => array(
						'title' => 'Safari\\s photo',
						'image' => '',
					),
				),
			)
		);
		$_POST                = wp_slash(
			array(
				'data'    => http_build_query( $form ),
				'post_id' => (string) $product->get_id(),
			)
		);
		$_REQUEST['security'] = wp_create_nonce( 'save-attributes' );

		do_action( 'wp_ajax_woocommerce_save_attributes' );
		unset( $_REQUEST['security'] );

		$this->assertSame( 'Safari\\s photo', SplitCardLooks::of( $product->get_id(), $with )['title'], 'a backslash typed is kept' );
	}

	public function test_a_rows_cards_show_while_it_lists_its_values_as_their_own_products(): void {
		$product = $this->poster();
		$image   = $this->image();
		$with    = $this->term( 'med-bild' );
		SplitCardLooks::save(
			$product->get_id(),
			array(
				$with => array(
					'title' => 'Safari med bild',
					'image' => $image,
				),
			)
		);
		$GLOBALS['product_object'] = $product;
		$attributes                = $product->get_attributes();

		$hidden = $this->row( $attributes['pa_bild'], 0 );
		$this->assertStringContainsString( '<tr class="pixonaut-split-cards" data-attribute-default="0" hidden>', $hidden, 'Auto, with the attribute off' );
		$this->assertStringContainsString( 'name="' . SplitListingFields::CARD_FIELD . '[' . $with . '][title]" value="Safari med bild" placeholder="Safari"', $hidden );
		$this->assertStringContainsString( 'name="' . SplitListingFields::CARD_FIELD . '[' . $with . '][image]" value="' . $image . '"', $hidden );
		$this->assertStringContainsString( 'Title for “Utan bild”', $hidden );

		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$this->assertStringContainsString( '<tr class="pixonaut-split-cards" data-attribute-default="1">', $this->row( $attributes['pa_bild'], 0 ) );
	}

	public function test_auto_follows_the_attribute_and_a_products_own_choice_comes_first(): void {
		$product = $this->poster();
		$picture = $this->attributes[ self::PICTURE ];
		$size    = $this->attributes[ self::SIZE ];
		$this->assertNull( $this->split_by( $product ), 'neither attribute is on' );

		SplitListing::set_attribute_default( $picture, true );
		$this->assertSame( $picture, $this->split_by( $product ), 'Auto follows the attribute' );
		$this->assertTrue( SplitListing::splits( $this->stored( $product ), $picture ) );

		$this->choose( $product, array( $size => SplitListing::ON ) );
		$this->assertSame( $size, $this->split_by( $product ), 'a product turning one on itself lists by it alone' );

		$this->choose( $product, array( $picture => SplitListing::OFF ) );
		$this->assertNull( $this->split_by( $product ), 'Off whatever the attribute says' );
		$this->assertFalse( SplitListing::splits( $this->stored( $product ), $picture ) );

		SplitListing::set_attribute_default( $picture, false );
		$this->choose( $product, array( $picture => SplitListing::ON ) );
		$this->assertSame( $picture, $this->split_by( $product ), 'On whatever the attribute says' );
	}

	public function test_only_a_variable_products_variation_attribute_splits(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$simple = new WC_Product_Simple();
		$simple->set_attributes( array( $this->product_attribute( self::PICTURE, false ) ) );
		$simple->save();
		$this->assertNull( $this->split_by( $simple ) );

		$shown = new WC_Product_Variable();
		$shown->set_attributes( array( $this->product_attribute( self::PICTURE, false ) ) );
		$shown->save();
		$this->assertNull( $this->split_by( $shown ), 'an attribute only shown on the product page lists nothing' );
	}

	public function test_a_global_attributes_row_shows_the_products_choice(): void {
		$product                   = $this->poster();
		$GLOBALS['product_object'] = $product;
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$attributes = $product->get_attributes();

		$auto = $this->row( $attributes['pa_bild'], 0 );
		$this->assertStringContainsString( 'name="' . SplitListingFields::PRODUCT_FIELD . '[0]" value="auto"', $auto );
		$this->assertStringContainsString( 'role="checkbox"', $auto );
		$this->assertStringContainsString( 'aria-checked="mixed"', $auto, 'Auto reads as mixed' );
		$this->assertStringContainsString( 'Auto: on, as the attribute is', $auto );

		$this->choose( $product, array( $this->attributes[ self::SIZE ] => SplitListing::OFF ) );
		$GLOBALS['product_object'] = $this->fresh( $product );
		$off                       = $this->row( $attributes['pa_posterstorlek'], 1 );
		$this->assertStringContainsString( 'aria-checked="false"', $off );
		$this->assertStringContainsString( 'Off for this product', $off );

		$custom = new WC_Product_Attribute();
		$custom->set_name( 'Ram' );
		$custom->set_options( array( 'Svart' ) );
		$this->assertSame( '', $this->row( $custom, 2 ), 'a custom attribute has no control' );
	}

	/**
	 * A variable poster using both attributes for its variations.
	 */
	private function poster(): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( 'Safari' );
		$product->set_attributes( array( $this->product_attribute( self::PICTURE, true ), $this->product_attribute( self::SIZE, true ) ) );
		$product->save();
		return $this->fresh( $product );
	}

	private function product_attribute( string $name, bool $variation ): WC_Product_Attribute {
		$taxonomy  = wc_attribute_taxonomy_name( $name );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $this->attributes[ $name ] );
		$attribute->set_name( $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$attribute->set_options( is_array( $terms ) ? $terms : array() );
		$attribute->set_variation( $variation );
		return $attribute;
	}

	/**
	 * Adds a global attribute with its taxonomy and terms.
	 *
	 * @param string   $name   The attribute's name.
	 * @param string[] $values Its terms.
	 */
	private function attribute( string $name, array $values ): int {
		$id = wc_create_attribute( array( 'name' => $name ) );
		$this->assertIsInt( $id );
		$taxonomy = wc_attribute_taxonomy_name( $name );
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ $taxonomy ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
		foreach ( $values as $value ) {
			wp_insert_term( $value, $taxonomy );
		}
		$this->attributes[ $name ] = $id;
		return $id;
	}

	/**
	 * A global attribute value's term id.
	 *
	 * @param string $slug The value's slug.
	 */
	private function term( string $slug ): int {
		$term = get_term_by( 'slug', $slug, wc_attribute_taxonomy_name( self::PICTURE ) );
		$this->assertInstanceOf( \WP_Term::class, $term );
		return $term->term_id;
	}

	/**
	 * An image in the media library.
	 */
	private function image(): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => 'card.png',
				'post_mime_type' => 'image/png',
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * @param array<string, mixed> $form A posted form.
	 * @return array<string, mixed> It with the fields' nonce.
	 */
	private function nonced( array $form ): array {
		return $form + array( SplitListingFields::NONCE_FIELD => wp_create_nonce( SplitListingFields::NONCE_ACTION ) );
	}

	/**
	 * @param WC_Product         $product The product.
	 * @param array<int, string> $choices By attribute id.
	 */
	private function choose( WC_Product $product, array $choices ): void {
		$this->stored( $product )->with_split_listing( $choices )->save_to( $this->fresh( $product ) );
	}

	/**
	 * The product as stored now.
	 *
	 * @param WC_Product $product The product.
	 */
	private function fresh( WC_Product $product ): WC_Product {
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	/**
	 * The id of the attribute the product is listed by, as stored now; null for none.
	 *
	 * @param WC_Product $product The product.
	 */
	private function split_by( WC_Product $product ): ?int {
		return SplitListing::attribute_of( $this->fresh( $product ) )?->get_id();
	}

	private function stored( WC_Product $product ): ProductConfig {
		return ProductConfig::stored_on( $this->fresh( $product ) );
	}

	private function row( WC_Product_Attribute $attribute, int $index ): string {
		ob_start();
		$this->fields->render_row( $attribute, $index );
		return (string) ob_get_clean();
	}
}
