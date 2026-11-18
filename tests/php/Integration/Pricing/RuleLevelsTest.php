<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Abilities\GetProductPrices;
use Pixonaut\WooCommerce\Abilities\PriceProduct;
use Pixonaut\WooCommerce\Abilities\ReadPricingSettings;
use Pixonaut\WooCommerce\Abilities\UpdatePricingSettings;
use Pixonaut\WooCommerce\Admin\AttributePriceRuleFields;
use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Admin\VariationPriceRuleField;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Pricing\AttributeRules;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Pricing\RuleChange;
use Pixonaut\WooCommerce\Pricing\RuleSource;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Term;

/**
 * The pricing rule's parts set on values of global attributes and on
 * variations, beside the product's and the store's: each part from the most
 * specific level, and a change at any level settling the prices it reaches.
 */
final class RuleLevelsTest extends PricingTestCase {

	/**
	 * First in the store's attribute order, which is by slug.
	 */
	private const FRAME = 'Framing';

	private const SIZE = 'Size';

	/**
	 * The variations' variant refs, by framing and size slug.
	 */
	private const REFS = array(
		'none'  => array(
			'small' => 'v_poster_small',
			'large' => 'v_poster_large',
		),
		'frame' => array(
			'small' => 'v_frame_small',
			'large' => 'v_frame_large',
		),
	);

	/**
	 * What the printshop charges for each variant.
	 */
	private const COSTS = array(
		'v_poster_small' => '100',
		'v_poster_large' => '150',
		'v_frame_small'  => '300',
		'v_frame_large'  => '500',
	);

	/**
	 * The global attributes, by name.
	 *
	 * @var array<string, int>
	 */
	private array $attributes = array();

	public function set_up(): void {
		parent::set_up();
		$this->attribute( self::FRAME, array( 'none', 'frame' ) );
		$this->attribute( self::SIZE, array( 'small', 'large' ) );
		update_option( 'woocommerce_currency', 'SEK' );
	}

	public function tear_down(): void {
		$_POST = array();
		foreach ( $this->attributes as $name => $id ) {
			wc_delete_attribute( $id );
			unregister_taxonomy( wc_attribute_taxonomy_name( $name ) );
			unset( $GLOBALS['wc_product_attributes'][ wc_attribute_taxonomy_name( $name ) ] );
		}
		parent::tear_down();
	}

	public function test_an_attribute_values_parts_price_every_variation_with_it(): void {
		$this->store_rule();
		AttributeRules::save( $this->term( self::SIZE, 'large' ), array( PriceRule::FIXED => '400' ) );
		$ids = $this->poster();

		$this->pricing->refresh( $this->parent_of( $ids['none']['small'] ) );
		$this->pricing->apply( $this->parent_of( $ids['none']['small'] ) );

		// 100 at 50 % targets 200: 199. 150 targets 300, plus 400 is 700: 699.
		$this->assertSame( '199', $this->price( $ids['none']['small'] ) );
		$this->assertSame( '699', $this->price( $ids['none']['large'] ) );
		$this->assertSame( '1399', $this->price( $ids['frame']['large'] ), '500 targets 1000, plus 400' );
		$large = $this->row( $ids['none']['large'] );
		$this->assertNotNull( $large->rule );
		$this->assertSame( RuleSource::ATTRIBUTE, $large->rule->sources[ PriceRule::FIXED ]->level );
		$this->assertSame( self::SIZE, $large->rule->sources[ PriceRule::FIXED ]->attribute );
		$this->assertSame( 'large', $large->rule->sources[ PriceRule::FIXED ]->value );
		$this->assertSame( RuleSource::STORE, $large->rule->sources[ PriceRule::MARGIN ]->level );
		$shown = PricingPanel::row( $large );
		$this->assertSame( 'large (Size), Store', $shown['source'], 'the most specific first' );
		$this->assertStringContainsString( 'large (Size)', $shown['source_parts'] );
		$this->assertSame( 'Store', PricingPanel::row( $this->row( $ids['none']['small'] ) )['source'] );
		$this->assertSame( '', PricingPanel::row( $this->row( $ids['none']['small'] ) )['source_parts'], 'one level needs no tooltip' );
	}

	public function test_the_variation_wins_over_the_product_over_the_attribute_value_over_the_store(): void {
		$this->store_rule();
		$ids     = $this->poster();
		$product = $this->parent_of( $ids['none']['small'] );
		AttributeRules::save( $this->term( self::FRAME, 'frame' ), array( PriceRule::MARGIN => '60' ) );
		AttributeRules::save( $this->term( self::SIZE, 'large' ), array( PriceRule::MARGIN => '40' ) );
		$this->pricing->save_own_parts( $product, array( PriceRule::STEP => '50' ) );
		$this->pricing->save_own_parts( $this->product( $ids['frame']['large'] ), array( PriceRule::MARGIN => '75' ) );

		$rules = $this->pricing->rules( $this->product( $product->get_id() ) );

		$this->assertSame( '50', $rules[ $ids['none']['small'] ]->parts[ PriceRule::MARGIN ], 'the store' );
		$this->assertSame( '40', $rules[ $ids['none']['large'] ]->parts[ PriceRule::MARGIN ], 'the size' );
		$this->assertSame( '60', $rules[ $ids['frame']['small'] ]->parts[ PriceRule::MARGIN ], 'the framing' );
		$this->assertSame( '75', $rules[ $ids['frame']['large'] ]->parts[ PriceRule::MARGIN ], 'the variation' );
		foreach ( $rules as $rule ) {
			$this->assertSame( '50', $rule->parts[ PriceRule::STEP ], 'the product over the store' );
			$this->assertSame( RuleSource::PRODUCT, $rule->sources[ PriceRule::STEP ]->level );
		}
		$this->assertSame( array(), $rules[ $ids['frame']['large'] ]->conflicts, 'the variation decides the margin' );
	}

	public function test_of_two_values_setting_a_part_the_first_attribute_wins_and_both_screens_say_so(): void {
		$this->store_rule();
		$ids   = $this->poster();
		$frame = $this->term( self::FRAME, 'frame' );
		$large = $this->term( self::SIZE, 'large' );
		AttributeRules::save( $frame, array( PriceRule::FIXED => '200' ) );
		AttributeRules::save( $large, array( PriceRule::FIXED => '400' ) );

		$rules = $this->pricing->rules( $this->parent_of( $ids['frame']['large'] ) );

		$this->assertSame( '200', $rules[ $ids['frame']['large'] ]->parts[ PriceRule::FIXED ], self::FRAME . ' comes first' );
		$this->assertCount( 1, $rules[ $ids['frame']['large'] ]->conflicts );
		$this->assertSame( '400', $rules[ $ids['none']['large'] ]->parts[ PriceRule::FIXED ] );
		$this->assertSame( array(), $rules[ $ids['none']['large'] ]->conflicts );
		$shown = PricingPanel::row( $this->row( $ids['frame']['large'] ) );
		$this->assertCount( 1, $shown['conflicts'] );
		$this->assertStringContainsString( 'frame (Framing) wins', $shown['conflicts'][0] );
		$loses = AttributeRules::clashes( $this->term_object( $large ) );
		$this->assertCount( 1, $loses );
		$this->assertSame( PriceRule::FIXED, $loses[0]['part'] );
		$this->assertFalse( $loses[0]['wins'] );
		$this->assertTrue( AttributeRules::clashes( $this->term_object( $frame ) )[0]['wins'] );
		$this->assertSame( array(), AttributeRules::clashes( $this->term_object( $this->term( self::SIZE, 'small' ) ) ) );
	}

	public function test_changing_a_value_flags_the_prices_it_reaches_or_sets_them_when_they_change_automatically(): void {
		$this->store_rule();
		$ids     = $this->poster();
		$product = $this->parent_of( $ids['none']['small'] );
		$this->pricing->refresh( $product );
		$this->pricing->apply( $this->product( $product->get_id() ) );
		$this->assertSame( '299', $this->price( $ids['none']['large'] ) );
		$change = new RuleChange( $this->pricing );
		$change->register();

		AttributeRules::save( $this->term( self::SIZE, 'large' ), array( PriceRule::FIXED => '50' ) );

		$this->assertTrue( as_has_scheduled_action( RuleChange::HOOK, array( 0, wc_attribute_taxonomy_name( self::SIZE ), 'large' ), RuleChange::GROUP ) );
		$change->page( 0, wc_attribute_taxonomy_name( self::SIZE ), 'large' );
		// Within the tolerance of the new rule, yet the rule changed: suggested, not set, with automatic changes off.
		$this->assertSame( '299', $this->price( $ids['none']['large'] ) );
		$this->assertSame( PriceState::FLAG_RULE, $this->row( $ids['none']['large'] )->flag );
		$this->assertSame( self::amount( '349' ), $this->row( $ids['none']['large'] )->suggested );
		$this->assertNull( $this->row( $ids['none']['small'] )->flag, 'a variation without the value is left alone' );

		$this->pricing->settle( $this->product( $product->get_id() ) );
		$this->assertSame( PriceState::FLAG_RULE, $this->row( $ids['none']['large'] )->flag, 'the daily settling keeps the suggestion' );

		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$change->page( 0, wc_attribute_taxonomy_name( self::SIZE ), 'large' );
		$this->assertSame( '349', $this->price( $ids['none']['large'] ) );
		$this->assertSame( '1049', $this->price( $ids['frame']['large'] ), '500 targets 1000, plus 50' );
		$this->assertNull( $this->row( $ids['none']['large'] )->flag );
		remove_action( 'updated_term_meta', array( $change, 'term_changed' ) );
		remove_action( 'added_term_meta', array( $change, 'term_changed' ) );
		remove_action( 'deleted_term_meta', array( $change, 'term_changed' ) );
		remove_action( 'update_option_' . Settings::OPTION, array( $change, 'settings_changed' ) );
		remove_action( 'add_option_' . Settings::OPTION, array( $change, 'settings_added' ) );
	}

	public function test_changing_the_stores_rule_settles_every_price_that_follows_it(): void {
		$this->store_rule();
		$ids     = $this->poster();
		$product = $this->parent_of( $ids['none']['small'] );
		$this->pricing->refresh( $product );
		$this->pricing->apply( $this->product( $product->get_id() ) );
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$change = new RuleChange( $this->pricing );

		$change->settings_changed( array( Settings::PRICE_MARGIN => '50' ), array( Settings::PRICE_MARGIN => '60' ) );
		$this->assertTrue( as_has_scheduled_action( RuleChange::HOOK, array( 0, '', '' ), RuleChange::GROUP ) );
		$this->store_rule( array( PriceRule::MARGIN => '60' ) );
		$change->page( 0, '', '' );

		$this->assertSame( '249', $this->price( $ids['none']['small'] ), '100 at 60 % targets 250' );
		$this->assertSame( '1249', $this->price( $ids['frame']['large'] ) );
	}

	public function test_a_variations_own_price_rule_is_saved_from_its_section_and_settles_its_price(): void {
		$this->store_rule();
		$ids     = $this->poster();
		$product = $this->parent_of( $ids['none']['small'] );
		$this->pricing->refresh( $product );
		$this->pricing->apply( $this->product( $product->get_id() ) );
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$field = new VariationPriceRuleField( $this->pricing, $this->plugin->templates() );
		$_POST = array(
			VariationPriceRuleField::FIELD => array(
				3 => array(
					'nonce' => wp_create_nonce( VariationPriceRuleField::NONCE_ACTION ),
					'parts' => array(
						PriceRule::MARGIN => '75',
						PriceRule::STEP   => '',
					),
				),
			),
		);

		$field->save( $ids['none']['small'], 3 );

		$this->assertSame( '75', ProductPricing::own_parts( $this->product( $ids['none']['small'] ) )[ PriceRule::MARGIN ] );
		$this->assertSame( '399', $this->price( $ids['none']['small'] ), '100 at 75 % targets 400' );
		$this->assertSame( '299', $this->price( $ids['none']['large'] ), 'the other variations keep theirs' );
		$this->assertSame( RuleSource::VARIATION, $this->row( $ids['none']['small'] )->rule?->sources[ PriceRule::MARGIN ]->level );
	}

	public function test_the_variations_section_shows_what_each_part_follows(): void {
		$this->store_rule();
		$ids = $this->poster();
		AttributeRules::save( $this->term( self::SIZE, 'large' ), array( PriceRule::FIXED => '400' ) );
		$field = new VariationPriceRuleField( $this->pricing, $this->plugin->templates() );

		ob_start();
		$field->render( 2, array(), get_post( $ids['none']['large'] ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="pixonaut_variation_price_rule[2][parts][margin]"', $html );
		$this->assertStringContainsString( 'placeholder="50 (Store)"', $html );
		$this->assertStringContainsString( 'placeholder="400 (large (Size))"', $html );
		$this->assertStringNotContainsString( ' open', explode( '>', $html )[0], 'closed while the variation changes nothing' );
	}

	public function test_a_values_screen_saves_its_parts_and_warns_of_a_part_another_attribute_sets_too(): void {
		$this->store_rule();
		$this->poster();
		$fields = new AttributePriceRuleFields( $this->pricing, $this->plugin->templates() );
		$large  = $this->term( self::SIZE, 'large' );
		AttributeRules::save( $this->term( self::FRAME, 'frame' ), array( PriceRule::FIXED => '200' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $admin );
		wp_set_current_user( $admin );
		$_POST = array(
			AttributePriceRuleFields::NONCE_FIELD => wp_create_nonce( AttributePriceRuleFields::NONCE_ACTION ),
			AttributePriceRuleFields::FIELD       => array(
				PriceRule::FIXED  => '400,50',
				PriceRule::MARGIN => 'lots',
			),
		);

		$fields->save( $large );

		$this->assertSame( '400.50', AttributeRules::parts( $large )[ PriceRule::FIXED ] );
		$this->assertSame( '', AttributeRules::parts( $large )[ PriceRule::MARGIN ], 'a part that is no number follows the store' );
		ob_start();
		$fields->render_edit( $this->term_object( $large ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="pixonaut_term_price_rule[fixed]" value="400.50"', $html );
		$this->assertStringContainsString( 'name="pixonaut_term_price_rule[margin]" value="" placeholder="50"', $html );
		$this->assertStringContainsString( 'frame (Framing) sets Fixed amount per item too. On a variation with both, frame wins', $html );
	}

	public function test_the_abilities_set_and_read_value_and_variation_rules_with_their_sources(): void {
		$this->store_rule();
		$ids        = $this->poster();
		$product_id = $this->parent_of( $ids['none']['small'] )->get_id();
		$settings   = new UpdatePricingSettings( $this->plugin->settings() );

		$answer = $settings->execute(
			array(
				'attribute_rules' => array(
					array(
						'attribute_id' => $this->attributes[ self::SIZE ],
						'slug'         => 'large',
						'rule'         => array( PriceRule::FIXED => '400' ),
					),
					array(
						'attribute_id' => $this->attributes[ self::FRAME ],
						'term_id'      => $this->term( self::FRAME, 'frame' ),
						'rule'         => array( PriceRule::FIXED => '200' ),
					),
				),
			)
		);

		$this->assertIsArray( $answer );
		$this->assertSame( array( 'frame', 'large' ), array_column( $answer['attribute_rules'], 'slug' ), 'in the store’s attribute order' );
		$this->assertSame( '200', $answer['attribute_rules'][0]['rule'][ PriceRule::FIXED ] );
		$this->assertTrue( $answer['attribute_rules'][0]['conflicts'][0]['wins'] );
		$this->assertSame( $answer, ReadPricingSettings::values( $this->plugin->settings() ) );
		$unknown = $settings->execute(
			array(
				'attribute_rules' => array(
					array(
						'attribute_id' => $this->attributes[ self::SIZE ],
						'slug'         => 'huge',
						'rule'         => array(),
					),
				),
			)
		);
		$this->assertWPError( $unknown );

		$priced = ( new PriceProduct( fn (): ProductPricing => $this->pricing ) )->execute(
			array(
				'product_id'      => $product_id,
				'variation_rules' => array(
					array(
						'variation_id' => $ids['none']['small'],
						'rule'         => array( PriceRule::FIXED => '100' ),
					),
				),
			)
		);
		$this->assertIsArray( $priced );
		$this->assertSame( '299', $this->price( $ids['none']['small'] ), '200 plus its own 100' );
		$this->assertSame( '699', $this->price( $ids['none']['large'] ) );
		$this->assertSame( '1199', $this->price( $ids['frame']['large'] ), 'the framing’s 200 wins over the size’s 400' );

		$read = ( new GetProductPrices( fn (): ProductPricing => $this->pricing ) )->execute( array( 'product_id' => $product_id ) );
		$this->assertIsArray( $read );
		$by_id = array_column( $read['prices'], null, 'id' );
		$small = $by_id[ $ids['none']['small'] ];
		$this->assertSame( '100', $small['rule'][ PriceRule::FIXED ] );
		$this->assertSame( '100', $small['own_rule'][ PriceRule::FIXED ] );
		$this->assertSame( RuleSource::VARIATION, $small['rule_sources'][ PriceRule::FIXED ]['level'] );
		$this->assertSame( RuleSource::STORE, $small['rule_sources'][ PriceRule::MARGIN ]['level'] );
		$this->assertNull( $small['rule_sources'][ PriceRule::MIN ] );
		$this->assertSame( '199', $small['contribution'] );
		$frame_large = $by_id[ $ids['frame']['large'] ];
		$this->assertSame( 'frame', $frame_large['rule_sources'][ PriceRule::FIXED ]['value'] );
		$this->assertSame( PriceRule::FIXED, $frame_large['rule_conflicts'][0]['part'] );
		$this->assertSame( 'large', $frame_large['rule_conflicts'][0]['loser']['value'] );
		$this->assertSame( '', $frame_large['own_rule'][ PriceRule::FIXED ] );

		$refused = ( new PriceProduct( fn (): ProductPricing => $this->pricing ) )->execute(
			array(
				'product_id'      => $product_id,
				'variation_rules' => array(
					array(
						'variation_id' => $product_id,
						'rule'         => array(),
					),
				),
			)
		);
		$this->assertWPError( $refused );
	}

	/**
	 * A poster on the blank with a variation per framing and size, each
	 * selling its own variant.
	 *
	 * @return array<string, array<string, int>> The variations' ids, by framing and size slug.
	 */
	private function poster(): array {
		$product    = new WC_Product_Variable();
		$attributes = array();
		foreach ( array(
			self::FRAME => array_keys( self::REFS ),
			self::SIZE  => array_keys( self::REFS['none'] ),
		) as $name => $slugs ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $this->attributes[ $name ] );
			$attribute->set_name( wc_attribute_taxonomy_name( $name ) );
			$attribute->set_options( array_map( fn ( string $slug ): int => $this->term( $name, $slug ), $slugs ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$product->set_name( 'Poster' );
		$product->set_attributes( $attributes );
		$product->save();
		( new ProductConfig( personalise: true, blank_id: self::BLANK_ID ) )->save_to( $product );
		$ids = array();
		foreach ( self::REFS as $frame => $sizes ) {
			foreach ( $sizes as $size => $ref ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
				$variation->set_attributes(
					array(
						wc_attribute_taxonomy_name( self::FRAME ) => $frame,
						wc_attribute_taxonomy_name( self::SIZE )  => $size,
					)
				);
				$variation->save();
				( new ProductVariant( self::BLANK_ID, $ref, $ref, $frame . ' ' . $size ) )->save_to( $variation );
				$ids[ $frame ][ $size ] = $variation->get_id();
			}
		}
		$this->script_costs( self::COSTS );
		return $ids;
	}

	/**
	 * One price's row, as stored.
	 *
	 * @param int $id The variation.
	 */
	private function row( int $id ): PriceRow {
		$rows = array_column( $this->pricing->rows( $this->parent_of( $id ) ), null, 'id' );
		$this->assertArrayHasKey( $id, $rows );
		return $rows[ $id ];
	}

	/**
	 * Adds a global attribute with its taxonomy and terms.
	 *
	 * @param string   $name   The attribute's name.
	 * @param string[] $values Its terms.
	 */
	private function attribute( string $name, array $values ): void {
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
	}

	/**
	 * A global attribute value's term id.
	 *
	 * @param string $name The attribute's name.
	 * @param string $slug The value's slug.
	 */
	private function term( string $name, string $slug ): int {
		$term = get_term_by( 'slug', $slug, wc_attribute_taxonomy_name( $name ) );
		$this->assertInstanceOf( WP_Term::class, $term );
		return $term->term_id;
	}

	/**
	 * @param int $id A term.
	 */
	private function term_object( int $id ): WP_Term {
		$term = get_term( $id );
		$this->assertInstanceOf( WP_Term::class, $term );
		return $term;
	}
}
