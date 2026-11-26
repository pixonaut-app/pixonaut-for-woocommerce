<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Abilities\GetAttributeBlankOptions;
use Pixonaut\WooCommerce\Abilities\SetAttributeBlankOption;
use Pixonaut\WooCommerce\Admin\BlankOptionField;
use Pixonaut\WooCommerce\Catalogue\BlankOptionChoices;
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Catalogue\PendingRechecks;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\SwatchLooks;
use Pixonaut\WooCommerce\Templates;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use WP_Term;
use WP_UnitTestCase;

final class BlankOptionLinksTest extends WP_UnitTestCase {

	private const ATTRIBUTE = 'Ramfärg';

	private const SLUG = 'ramfarg';

	private const TAXONOMY = 'pa_ramfarg';

	private const BLANK = 'blank_framed';

	private const OTHER_BLANK = 'blank_hanger';

	/**
	 * The store's frame colours, by slug, with the blank's Color value each stands for.
	 */
	private const FRAMES = array(
		'vit'       => array( 'Vit', 'White' ),
		'svart'     => array( 'Svart', 'Black' ),
		'ljust-tra' => array( 'Ljust trä', 'Natural Wood' ),
		'morkt-tra' => array( 'Mörkt trä', 'Dark Wood' ),
	);

	private const OAK_URL = 'https://cdn.example.test/values/oak.png';

	private int $attribute = 0;

	/**
	 * Term ids by slug.
	 *
	 * @var array<string, int>
	 */
	private array $terms = array();

	public function set_up(): void {
		parent::set_up();
		$attribute = wc_create_attribute(
			array(
				'name' => self::ATTRIBUTE,
				'slug' => self::SLUG,
			)
		);
		$this->assertIsInt( $attribute );
		$this->attribute = $attribute;
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $this->attribute ) ) );
		foreach ( self::FRAMES as $slug => list( $name ) ) {
			$term = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			$this->assertIsArray( $term );
			$this->terms[ $slug ] = (int) $term['term_id'];
		}
		BlankOptionLinks::register();
	}

	public function tear_down(): void {
		unregister_taxonomy( self::TAXONOMY );
		unset( $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( BlankOptionLinks::OPTION );
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	public function test_a_link_keeps_the_option_and_the_values_given_and_unlinking_forgets_it(): void {
		$this->link();

		$this->assertSame(
			array(
				'option' => 'Color',
				'values' => array(
					$this->terms['vit']       => 'White',
					$this->terms['svart']     => 'Black',
					$this->terms['ljust-tra'] => 'Natural Wood',
					$this->terms['morkt-tra'] => 'Dark Wood',
				),
			),
			BlankOptionLinks::of( $this->attribute )
		);
		$this->assertSame( 'Color', BlankOptionLinks::option_of( self::TAXONOMY ) );
		$this->assertNull( BlankOptionLinks::option_of( 'size' ), 'a custom attribute is never linked' );

		BlankOptionLinks::set( $this->attribute, ' ', array() );
		$this->assertNull( BlankOptionLinks::of( $this->attribute ) );
		$this->assertFalse( get_option( BlankOptionLinks::OPTION ) );
	}

	public function test_a_value_without_one_of_its_own_stands_for_the_options_value_of_its_name(): void {
		$white = wp_insert_term( 'White', self::TAXONOMY );
		$this->assertIsArray( $white );
		BlankOptionLinks::set( $this->attribute, 'Color', array( $this->terms['svart'] => 'Black' ) );

		$term = get_term( (int) $white['term_id'], self::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'White', BlankOptionLinks::stands_for( self::TAXONOMY, $term ) );
		$this->assertSame( 'White', BlankOptionLinks::same_name( ' white ', array( 'Black', 'White' ) ) );
		$this->assertNull( BlankOptionLinks::same_name( 'Vit', array( 'Black', 'White' ) ) );
	}

	public function test_deleting_the_attribute_or_a_value_forgets_its_link(): void {
		$this->link();

		wp_delete_term( $this->terms['svart'], self::TAXONOMY );
		$this->assertArrayNotHasKey( $this->terms['svart'], BlankOptionLinks::of( $this->attribute )['values'] ?? array() );

		wc_delete_attribute( $this->attribute );
		$this->assertFalse( get_option( BlankOptionLinks::OPTION ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function frames(): array {
		$frames = array();
		foreach ( array_keys( self::FRAMES ) as $slug ) {
			$frames[ $slug ] = array( $slug );
		}
		return $frames;
	}

	/**
	 * @dataProvider frames
	 *
	 * @param string $slug The frame colour's slug.
	 */
	public function test_a_linked_value_picks_the_blank_variant_of_its_option_value( string $slug ): void {
		$this->link();
		$variation = $this->poster()[ $slug ];

		$resolved = ProductVariant::resolve( self::BLANK, null, ProductVariants::attributes( $variation ), self::variants(), BlankOptionLinks::labels( $variation ) );

		$this->assertSame( 'v_' . str_replace( '-', '_', $slug ), $resolved->ref );
		$this->assertSame( array( self::ATTRIBUTE => self::FRAMES[ $slug ][0] ), $resolved->matched_on, 'the match is kept on the attribute as the store names it' );
		$this->assertTrue( $resolved->is_current( $variation ) );
	}

	public function test_an_unlinked_attribute_picks_no_variant_by_an_option_of_another_name(): void {
		$variation = $this->poster()['svart'];

		$resolved = ProductVariant::resolve( self::BLANK, null, ProductVariants::attributes( $variation ), self::variants(), BlankOptionLinks::labels( $variation ) );

		$this->assertNull( $resolved->ref );
		$this->assertSame( array(), BlankOptionLinks::labels( $variation ) );
	}

	public function test_a_changed_link_rechecks_the_products_with_the_attribute_even_one_configured_on_its_variations_only(): void {
		$product = $this->poster();
		$parent  = $product['vit']->get_parent_id();
		add_post_meta( $product['vit']->get_id(), ProductIndex::BLANK_META, self::BLANK );
		$this->poster();
		as_unschedule_all_actions( ProductRecheck::ACTION );

		$this->assertTrue( BlankOptionLinks::set( $this->attribute, 'Color', array() ) );

		$queued = as_get_scheduled_actions(
			array(
				'hook'   => ProductRecheck::ACTION,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $queued );
		$this->assertSame( array( $parent ), current( $queued )->get_args()['product_ids'] ?? null, 'only the product using a blank' );

		as_unschedule_all_actions( ProductRecheck::ACTION );
		PendingRechecks::forget( array( $parent ) );
		$this->assertTrue( BlankOptionLinks::set( $this->attribute, 'Color', array() ) );
		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => ProductRecheck::ACTION,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			),
			'the same link changes nothing'
		);
	}

	public function test_a_link_whose_recheck_cannot_be_queued_says_so_and_the_tool_answers_an_error(): void {
		$parent = $this->poster()['vit']->get_parent_id();
		add_post_meta( $parent, ProductIndex::BLANK_META, self::BLANK );
		PendingRechecks::forget( array( $parent ) );
		$refuse = static fn (): int => 0;
		add_filter( 'pre_as_schedule_single_action', $refuse );
		try {
			$answer = ( new SetAttributeBlankOption( $this->choices() ) )->execute(
				array(
					'attribute_id' => $this->attribute,
					'option'       => 'Color',
				)
			);
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $refuse );
		}

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_recheck_not_queued', $answer->get_error_code() );
		$this->assertSame( 'Color', BlankOptionLinks::option_of( self::TAXONOMY ), 'the link is saved' );

		$again = ( new SetAttributeBlankOption( $this->choices() ) )->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Color',
			)
		);

		$this->assertIsArray( $again, 'the same link given again answers its row' );
		$this->assertNotEmpty(
			as_get_scheduled_actions(
				array(
					'hook'   => ProductRecheck::ACTION,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			),
			'and queues the re-check it missed'
		);
	}

	public function test_a_linked_attributes_swatches_take_the_looks_of_its_option_values_on_any_blank(): void {
		$this->link();
		$parent = wc_get_product( $this->poster()['vit']->get_parent_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $parent );
		$settings = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings();

		// The designer's blank has no Color; the variations' framed blank has.
		$looks = SwatchLooks::for_product( $parent, array( array(), self::options() ), $settings );

		$this->assertSame(
			array(
				'vit'       => array(
					'kind'    => 'fill',
					'colours' => array( '#ffffff' ),
				),
				'svart'     => array(
					'kind'    => 'fill',
					'colours' => array( '#000000' ),
				),
				'ljust-tra' => array(
					'kind' => 'image',
					'url'  => self::OAK_URL,
				),
			),
			$looks['attribute_pa_ramfarg']['looks'] ?? null
		);
		$this->assertSame( $settings->get( Settings::SWATCH_NAMES ), $looks['attribute_pa_ramfarg']['names'] );
	}

	public function test_the_blanks_a_product_uses_are_its_own_and_its_variations(): void {
		$poster = $this->poster();
		$parent = $poster['vit']->get_parent_id();
		add_post_meta( $parent, ProductIndex::BLANK_META, self::OTHER_BLANK );
		add_post_meta( $poster['vit']->get_id(), ProductIndex::BLANK_META, self::BLANK );
		add_post_meta( $poster['svart']->get_id(), ProductIndex::BLANK_META, self::BLANK );
		// A product without the attribute adds nothing.
		$other = new WC_Product_Variable();
		$other->save();
		add_post_meta( $other->get_id(), ProductIndex::BLANK_META, 'blank_mug' );

		$this->assertSame( array( self::BLANK, self::OTHER_BLANK ), ProductIndex::blanks_of( $parent ) );
		$this->assertSame( array( self::BLANK, self::OTHER_BLANK ), ProductIndex::blanks_with_attribute( self::TAXONOMY ) );
		$this->assertSame( array( $parent ), ProductIndex::products_with_attribute( self::TAXONOMY ) );
	}

	public function test_the_tools_link_the_attribute_to_an_option_its_products_blanks_have(): void {
		// Configured on a variation only.
		add_post_meta( $this->poster()['svart']->get_id(), ProductIndex::BLANK_META, self::BLANK );
		$choices = $this->choices();
		$set     = new SetAttributeBlankOption( $choices );

		$read = ( new GetAttributeBlankOptions( $choices ) )->execute( array( 'attribute_id' => $this->attribute ) );
		$this->assertCount( 1, $read['attributes'] );
		$this->assertNull( $read['attributes'][0]['option'] );
		$this->assertSame(
			array(
				'Color'                      => array( 'White', 'Black', 'Natural Wood', 'Dark Wood' ),
				BlankOptionLinks::PAGE_COUNT => array(),
			),
			$read['attributes'][0]['options']
		);

		$refused = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Size',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertStringContainsString( 'Color', $refused->get_error_message() );
		$refused = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Color',
				'values'       => array(
					array(
						'slug'  => 'vit',
						'value' => 'Ivory',
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$refused = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Color',
				'values'       => array(
					array(
						'slug'  => 'gron',
						'value' => 'White',
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertNull( BlankOptionLinks::of( $this->attribute ), 'nothing is linked by a refused call' );

		$row = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Color',
				'values'       => array(
					array(
						'slug'  => 'vit',
						'value' => 'White',
					),
					array(
						'term_id' => $this->terms['svart'],
						'value'   => 'Black',
					),
				),
			)
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'Color', $row['option'] );
		$by_slug = array_column( $row['values'], null, 'slug' );
		$this->assertSame( 'White', $by_slug['vit']['stands_for'] );
		$this->assertSame( 'Black', $by_slug['svart']['value'] );
		$this->assertNull( $by_slug['ljust-tra']['stands_for'], 'a value with no link and no value of its name says it is not linked' );

		// Values not given keep their link while the option stays.
		$row = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Color',
				'values'       => array(
					array(
						'slug'  => 'ljust-tra',
						'value' => 'Natural Wood',
					),
				),
			)
		);
		$this->assertIsArray( $row );
		$this->assertCount( 3, BlankOptionLinks::of( $this->attribute )['values'] ?? array() );

		$row = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => '',
			)
		);
		$this->assertIsArray( $row );
		$this->assertNull( $row['option'] );
		$this->assertNull( BlankOptionLinks::of( $this->attribute ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$set->execute(
				array(
					'attribute_id' => 999999,
					'option'       => 'Color',
				)
			)
		);
	}

	public function test_an_attribute_standing_for_a_page_count_names_no_option_and_reads_each_count(): void {
		$counts = array();
		foreach ( array( '20 sidor', '44 pages', 'Tjock' ) as $name ) {
			$term = wp_insert_term( $name, self::TAXONOMY );
			$this->assertIsArray( $term );
			$counts[ $name ] = (int) $term['term_id'];
		}
		$set = new SetAttributeBlankOption( $this->choices() );

		$refused = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => BlankOptionLinks::PAGE_COUNT,
				'values'       => array(
					array(
						'term_id' => $counts['Tjock'],
						'value'   => 'many',
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$row = $set->execute(
			array(
				'attribute_id' => $this->attribute,
				'option'       => BlankOptionLinks::PAGE_COUNT,
				'values'       => array(
					array(
						'term_id' => $counts['Tjock'],
						'value'   => '120 pages',
					),
				),
			)
		);
		$this->assertIsArray( $row );
		$by_id = array_column( $row['values'], null, 'term_id' );
		$this->assertSame( '20', $by_id[ $counts['20 sidor'] ]['stands_for'] );
		$this->assertSame( '44', $by_id[ $counts['44 pages'] ]['stands_for'] );
		$this->assertSame( '120', $by_id[ $counts['Tjock'] ]['stands_for'] );
		$this->assertNull( $by_id[ $this->terms['vit'] ]['stands_for'], 'a value with no number stands for no count' );

		$thick = get_term( $counts['Tjock'], self::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $thick );
		$variation = $this->poster()['svart'];
		$variation->set_attributes( array( self::TAXONOMY => $thick->slug ) );
		$variation->save();
		$this->assertSame( 120, BlankOptionLinks::page_count( $variation ) );
		$this->assertSame( array(), BlankOptionLinks::labels( $variation ), 'a page count picks no variant' );
		$product = wc_get_product( $variation->get_parent_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $product );
		$this->assertSame( array(), BlankOptionLinks::linked_in( $product ), 'a page count has no swatch looks' );
	}

	public function test_the_attribute_screen_offers_a_page_count_and_saves_each_count(): void {
		$field = new BlankOptionField( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ), $this->choices() );
		$field->register();
		$_GET['edit'] = (string) $this->attribute;
		BlankOptionLinks::set( $this->attribute, BlankOptionLinks::PAGE_COUNT, array() );

		ob_start();
		$field->render_edit();
		$screen = (string) ob_get_clean();
		$this->assertStringContainsString( '<option value="@pages" selected=\'selected\'>Page count (books)</option>', $screen );
		$this->assertStringContainsString( 'type="number"', $screen );

		$_POST = array(
			BlankOptionField::OPTION_FIELD => BlankOptionLinks::PAGE_COUNT,
			BlankOptionField::VALUES_FIELD => array(
				$this->terms['vit']   => '44',
				$this->terms['svart'] => '',
			),
			BlankOptionField::NONCE_FIELD  => wp_create_nonce( BlankOptionField::NONCE_ACTION ),
		);
		wc_update_attribute( $this->attribute, array( 'name' => self::ATTRIBUTE ) );
		$this->assertSame(
			array(
				'option' => BlankOptionLinks::PAGE_COUNT,
				'values' => array( $this->terms['vit'] => '44' ),
			),
			BlankOptionLinks::of( $this->attribute )
		);
	}

	public function test_the_attribute_screen_lists_the_options_and_saves_the_link(): void {
		add_post_meta( $this->poster()['vit']->get_parent_id(), ProductIndex::BLANK_META, self::BLANK );
		$field = new BlankOptionField( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ), $this->choices() );
		$field->register();
		$_GET['edit'] = (string) $this->attribute;

		ob_start();
		$field->render_edit();
		$unlinked = (string) ob_get_clean();
		$this->assertStringContainsString( '<option value="Color">Color</option>', $unlinked );
		$this->assertStringNotContainsString( 'pixonaut-blank-option-values', $unlinked, 'no values until an option is saved' );

		$_POST = array(
			BlankOptionField::OPTION_FIELD => 'Color',
			BlankOptionField::VALUES_FIELD => array( $this->terms['vit'] => 'White' ),
			BlankOptionField::NONCE_FIELD  => wp_create_nonce( BlankOptionField::NONCE_ACTION ),
		);
		wc_update_attribute( $this->attribute, array( 'name' => self::ATTRIBUTE ) );
		$this->assertSame(
			array(
				'option' => 'Color',
				'values' => array(),
			),
			BlankOptionLinks::of( $this->attribute ),
			'values posted for the option before are not kept'
		);

		wc_update_attribute( $this->attribute, array( 'name' => self::ATTRIBUTE ) );
		$this->assertSame(
			array(
				'option' => 'Color',
				'values' => array( $this->terms['vit'] => 'White' ),
			),
			BlankOptionLinks::of( $this->attribute )
		);

		ob_start();
		$field->render_edit();
		$linked = (string) ob_get_clean();
		$this->assertStringContainsString( 'pixonaut-blank-option-values', $linked );
		$this->assertStringContainsString( '<option value="White" selected=\'selected\'>White</option>', $linked );
		$this->assertStringContainsString( 'Not linked', $linked );

		$_POST = array( BlankOptionField::OPTION_FIELD => '' );
		wc_update_attribute( $this->attribute, array( 'name' => self::ATTRIBUTE ) );
		$this->assertNotNull( BlankOptionLinks::of( $this->attribute ), 'an update not from the screen leaves it' );
	}

	/**
	 * Links Ramfärg to Color, each value to its colour.
	 */
	private function link(): void {
		$values = array();
		foreach ( self::FRAMES as $slug => list( , $value ) ) {
			$values[ $this->terms[ $slug ] ] = $value;
		}
		BlankOptionLinks::set( $this->attribute, 'Color', $values );
	}

	private function choices(): BlankOptionChoices {
		return new BlankOptionChoices( static fn ( string $blank ): array => self::BLANK === $blank ? self::options() : array() );
	}

	/**
	 * A framed poster's Color option as Pixonaut writes it.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function options(): array {
		return array(
			array(
				'name'        => 'Color',
				'values'      => array( 'White', 'Black', 'Natural Wood', 'Dark Wood' ),
				'appearances' => array(
					'White'        => array(
						'kind'    => 'fill',
						'colours' => array( '#ffffff' ),
					),
					'Black'        => array(
						'kind'    => 'fill',
						'colours' => array( '#000000' ),
					),
					'Natural Wood' => array(
						'kind' => 'image',
						'url'  => self::OAK_URL,
					),
					'Dark Wood'    => null,
				),
			),
		);
	}

	/**
	 * The framed poster's variants, one per Color.
	 *
	 * @return list<BlankVariant>
	 */
	private static function variants(): array {
		$variants = array();
		foreach ( self::FRAMES as $slug => list( , $value ) ) {
			$variants[] = new BlankVariant( 'v_' . str_replace( '-', '_', $slug ), array( 'Color' => $value ) );
		}
		return $variants;
	}

	/**
	 * A variable poster with a variation per frame colour.
	 *
	 * @return array<string, WC_Product_Variation> Its variations by slug.
	 */
	private function poster(): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $this->attribute );
		$attribute->set_name( self::TAXONOMY );
		$attribute->set_options( array_values( $this->terms ) );
		$attribute->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( 'Poster' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		wp_set_object_terms( $product->get_id(), array_values( $this->terms ), self::TAXONOMY );
		$variations = array();
		foreach ( array_keys( self::FRAMES ) as $slug ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( self::TAXONOMY => $slug ) );
			$variation->set_regular_price( '10' );
			$variation->save();
			$variations[ $slug ] = $variation;
		}
		return $variations;
	}
}
