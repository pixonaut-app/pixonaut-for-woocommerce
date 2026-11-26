<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Abilities\GenerateProductVariations;
use Pixonaut\WooCommerce\Abilities\GetProductVariationPlan;
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\GeneratedVariation;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationChanges;
use Pixonaut\WooCommerce\Catalogue\VariationCombinations;
use Pixonaut\WooCommerce\Catalogue\VariationPlan;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Tests\Integration\Pricing\PricingTestCase;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use Pixonaut\WooCommerce\Schema;
use WP_REST_Request;
use WP_REST_Response;
use Pixonaut\WooCommerce\Admin\VariationPlanPanel;

/**
 * A födelsetavla-like poster sold unframed on one blank and framed on
 * another, by size, frame colour and with or without a photo: its
 * variations generated from its plan, generated again once the framed
 * blank gains a colour, and once one of its colours is retired.
 */
final class VariationGenerationTest extends PricingTestCase {

	private const POSTER = 'blank_poster';

	private const FRAMED = 'blank_framed';

	/**
	 * The store's attributes: each value's name, and the blank option value
	 * it stands for; null for an attribute linked to no option.
	 */
	private const ATTRIBUTES = array(
		'size'   => array(
			'option' => 'Size',
			'values' => array(
				's30' => array( '30×40 cm', '30x40cm' ),
				's50' => array( '50×70 cm', '50x70cm' ),
			),
		),
		'mount'  => array(
			'option' => null,
			'values' => array(
				'none'   => array( 'Ingen', null ),
				'framed' => array( 'Ram', null ),
			),
		),
		'colour' => array(
			'option' => 'Color',
			'values' => array(
				'black' => array( 'Svart', 'Black' ),
				'white' => array( 'Vit', 'White' ),
			),
		),
	);

	/**
	 * The poster's form: its sizes in a group of their own.
	 */
	private const ARRANGEMENT = array(
		'groups' => array(
			array(
				'id'    => 'wc-sizes',
				'title' => 'Storlek',
				'items' => array( array( 'attribute' => 'pa_size' ) ),
			),
		),
	);

	/**
	 * The template choice: a custom attribute.
	 */
	private const PHOTO = array( 'With', 'Without' );

	/**
	 * The plan: unframed on the poster blank, upright; framed on the framed blank.
	 */
	private const PLAN = array(
		array(
			'blank_id' => self::POSTER,
			'when'     => array( 'pa_mount' => 'none' ),
			'fixed'    => array( 'Orientation' => 'Vertical' ),
		),
		array(
			'blank_id' => self::FRAMED,
			'when'     => array( 'pa_mount' => 'Ram' ),
		),
	);

	private VariantsAnswer $variants;

	/**
	 * Each attribute's id, by slug.
	 *
	 * @var array<string, int>
	 */
	private array $attribute_ids = array();

	/**
	 * Term ids by attribute slug and term slug.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $terms = array();

	private GenerateProductVariations $generate;

	public function set_up(): void {
		parent::set_up();
		$this->plugin->connection_store()->save( self::connection() );
		$this->variants                           = ( new VariantsAnswer() )->start();
		$this->variants->by_blank[ self::POSTER ] = self::poster_variants();
		$this->variants->by_blank[ self::FRAMED ] = self::framed_variants( array( 'Black', 'White' ) );
		FreshRequest::begin( $this->plugin );
		foreach ( self::ATTRIBUTES as $slug => $attribute ) {
			$this->attribute( $slug, $attribute['option'], $attribute['values'] );
		}
		$this->store_rule();
		$this->generate = new GenerateProductVariations( $this->plugin->variation_generator( fn (): ProductPricing => $this->pricing ) );
	}

	public function tear_down(): void {
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		foreach ( array_keys( self::ATTRIBUTES ) as $slug ) {
			unregister_taxonomy( 'pa_' . $slug );
			unset( $GLOBALS['wc_product_attributes'][ 'pa_' . $slug ] );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( BlankOptionLinks::OPTION );
		parent::tear_down();
	}

	public function test_a_dry_run_lists_every_variation_the_plan_offers_and_changes_nothing(): void {
		$product = $this->poster();
		$framed  = $this->framed_variation( $product );

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => self::PLAN,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertTrue( $answer['dry_run'] );
		// Unframed: 2 sizes × 2 photo choices, any colour; framed: 2 sizes × 2 colours × 2 photo choices, one of them made already.
		$this->assertCount( 11, $answer['add'] );
		$this->assertSame( 1, $answer['kept'] );
		$this->assertSame( array(), $answer['disable'] );
		$this->assertSame( array(), $answer['skipped'] );
		$this->assertContains(
			array(
				'attributes' => array(
					'pa_size'   => 's30',
					'pa_mount'  => 'none',
					'pa_colour' => '',
					'photo'     => 'With',
				),
				'label'      => 'size: 30×40 cm, mount: Ingen, colour: Any, Photo: With',
				'blank_id'   => self::POSTER,
				'ref'        => 'v_poster_s30_vertical',
				'variant'    => 'Size: 30x40cm, Orientation: Vertical',
				'template'   => 'Mini poster',
			),
			array_map( static fn ( array $row ): array => array( 'attributes' => (array) $row['attributes'] ) + $row, $answer['add'] )
		);
		$this->assertSame( array( $framed->get_id() ), $this->reread( $product )->get_children(), 'nothing is made on a dry run' );
		$this->assertNull( VariationPlan::stored_on( $this->reread( $product ) ), 'nor is the plan saved' );
	}

	public function test_an_attribute_standing_for_a_page_count_makes_a_variation_per_count(): void {
		$product = $this->poster();
		BlankOptionLinks::set( $this->attribute_ids['colour'], BlankOptionLinks::PAGE_COUNT, array() );

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => array( self::PLAN[0] ),
			)
		);

		$this->assertIsArray( $answer );
		$counts = array_unique( array_map( static fn ( array $row ): string => (string) ( (array) $row['attributes'] )['pa_colour'], $answer['add'] ) );
		sort( $counts );
		$this->assertSame( array( 'black', 'white' ), $counts, 'each value its own variation, none for any value' );
	}

	public function test_generating_makes_links_and_prices_each_variation_on_its_blank(): void {
		$product = $this->poster();
		$framed  = $this->framed_variation( $product );
		$this->script_costs_twice();

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => self::PLAN,
				'dry_run'    => false,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertCount( 11, $answer['made'] );
		$this->assertSame( 11, $answer['priced'] );
		$this->assertSame( array(), $answer['unpriced'] );
		$this->assertSame( self::PLAN[0]['blank_id'], VariationPlan::stored_on( $this->reread( $product ) )?->entries[0]['blank_id'] );
		$sold = $this->sold( $product );
		$this->assertCount( 12, $sold );
		$this->assertSame( 'v_framed_s30_black', $sold['s30|framed|black|With']['ref'] );
		$this->assertSame( $framed->get_id(), $sold['s30|framed|black|With']['id'], 'the variation there was is kept' );
		$this->assertSame( 'v_framed_s50_white', $sold['s50|framed|white|Without']['ref'] );
		$this->assertSame( self::FRAMED, $sold['s50|framed|white|Without']['blank'], 'a framed variation gets the framed setup of its sibling' );
		$this->assertTrue( $sold['s50|framed|white|Without']['own'] );
		$this->assertSame( 'v_poster_s50_vertical', $sold['s50|none||Without']['ref'] );
		$this->assertSame( self::POSTER, $sold['s50|none||Without']['blank'] );
		$this->assertFalse( $sold['s50|none||Without']['own'], 'an unframed variation follows the product, on its blank' );
		$this->assertSame( '379', $sold['s50|none||Without']['price'], 'priced by the rule from the cost read' );
		$this->assertSame( '', $sold['s30|framed|black|With']['price'], 'the variation there was keeps its own price' );
		$this->assertSame( GeneratedVariation::MADE, $this->reread( wc_get_product( $sold['s50|none||With']['id'] ) )->get_meta( GeneratedVariation::META_KEY ) );

		$again = $this->generate->execute( array( 'product_id' => $product->get_id() ) );
		$this->assertIsArray( $again );
		$this->assertSame( array(), $again['add'], 'generating again from the saved plan changes nothing' );
		$this->assertSame( 12, $again['kept'] );
	}

	public function test_a_blank_no_variation_is_on_yet_gets_the_products_setup_on_it_checked_once(): void {
		$product = $this->poster();
		$checks  = ( new HttpRecorder( Hosts::server_api() ) )->start();
		self::script_framed_check( $checks );
		try {
			$this->script_costs_twice();

			$answer = $this->generated( $product );
		} finally {
			$checks->stop();
		}

		$this->assertCount( 12, $answer['made'] );
		$sold = $this->sold( $product );
		$this->assertSame( self::FRAMED, $sold['s50|framed|white|Without']['blank'] );
		$this->assertTrue( $sold['s50|framed|white|Without']['own'] );
		$this->assertSame( 'v_framed_s50_white', $sold['s50|framed|white|Without']['ref'] );
		$this->assertCount( 4, $checks->requests, 'the framed setup is checked once for all eight framed variations' );
	}

	public function test_another_blank_takes_neither_the_size_picked_for_the_products_blank_nor_a_copy_of_its_form(): void {
		$arranged = FormGroups::from_array( self::ARRANGEMENT );
		$product  = $this->poster( new PrintSize( 280, 360, 'Poster' ), $arranged );
		$checks   = ( new HttpRecorder( Hosts::server_api() ) )->start();
		self::script_framed_check( $checks );
		try {
			$this->script_costs_twice();

			$this->generated( $product );
		} finally {
			$checks->stop();
		}

		foreach ( $this->reread( $product )->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			$this->assertInstanceOf( WC_Product::class, $variation );
			$config = ProductConfig::config_for( $variation );
			if ( self::FRAMED !== $config->blank_id ) {
				continue;
			}
			$sizes = $config->sides[0]->print_sizes;
			$this->assertCount( 1, $sizes );
			$this->assertTrue( $sizes[0]->placement_default, 'each framed variation is sold at the framed placement\'s whole area, not the poster\'s size' );
			$this->assertNull( ProductConfig::stored_on( $variation )->sides[0]->form_groups, 'it keeps no copy of the product\'s form' );
			$this->assertSame( self::ARRANGEMENT['groups'], $config->sides[0]->form_groups?->groups, 'it shows the product\'s form' );
			$framed = $variation;
		}
		$stored = ProductConfig::stored_on( $this->reread( $product ) );
		$this->assertSame( 'Poster', $stored->sides[0]->print_sizes[0]->label, 'the product keeps its own size' );
		$this->assertTrue( isset( $framed ) );

		$moved = self::ARRANGEMENT;
		// The photo choice joins the sizes.
		$moved['groups'][0]['items'][] = array( 'attribute' => 'photo' );
		$stored->with_sides( array( $stored->sides[0]->with_form_groups( FormGroups::from_array( $moved ) ) ) )->save_to( $this->reread( $product ) );

		$this->assertSame( $moved['groups'], ProductConfig::config_for( $this->reread( $framed ) )->sides[0]->form_groups?->groups, 'a later arrangement of the product reaches it too' );
	}

	public function test_variations_made_on_another_blank_keep_the_template_their_photo_choice_picks(): void {
		$product = $this->poster();
		$this->unframed_variations( $product );

		$preview = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => self::PLAN,
			)
		);
		$this->assertIsArray( $preview );
		$this->assertCount( 8, $preview['add'], 'framed: 2 sizes × 2 colours × 2 photo choices' );
		foreach ( $preview['add'] as $row ) {
			$this->assertSame( 'With' === ( (array) $row['attributes'] )['photo'] ? 'Mini poster' : 'Plain poster', $row['template'], 'the preview shows the template each variation is sold on' );
		}

		$checks = ( new HttpRecorder( Hosts::server_api() ) )->start();
		self::script_framed_check( $checks );
		self::script_framed_check( $checks, 'plain-poster' );
		try {
			$this->script_costs_twice();

			$answer = $this->generated( $product );
		} finally {
			$checks->stop();
		}

		$this->assertCount( 8, $answer['made'] );
		$this->assertCount( 8, $checks->requests, 'the framed setup is checked once for each template' );
		foreach ( $this->reread( $product )->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			$this->assertInstanceOf( WC_Product_Variation::class, $variation );
			$config = ProductConfig::config_for( $variation );
			$photo  = $variation->get_attributes( 'edit' )['photo'] ?? '';
			$this->assertSame( 'With' === $photo ? 'mini-poster' : 'plain-poster', $config->sides[0]->template_base_id, 'each variation is sold on the template its photo choice picks' );
			if ( self::FRAMED === $config->blank_id ) {
				$this->assertTrue( $config->sides[0]->print_sizes[0]->placement_default, 'at the framed placement\'s whole area' );
				$this->assertSame( 'publish', $variation->get_status() );
			}
		}
	}

	public function test_a_choice_pinned_to_another_version_of_the_products_template_keeps_that_version(): void {
		$product = $this->poster();
		$this->unframed_variations( $product, new Side( 'front', 'Front', 'front', 'Front', 'mini-poster', 2, null, 'Mini poster', pinned: true ) );
		$checks = ( new HttpRecorder( Hosts::server_api() ) )->start();
		self::script_framed_check( $checks );
		self::script_framed_check( $checks, 'mini-poster', 2 );
		try {
			$this->script_costs_twice();

			$this->generated( $product );
		} finally {
			$checks->stop();
		}

		foreach ( $this->reread( $product )->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			$this->assertInstanceOf( WC_Product_Variation::class, $variation );
			$side = ProductConfig::config_for( $variation )->sides[0];
			if ( 'Without' === ( $variation->get_attributes( 'edit' )['photo'] ?? '' ) ) {
				$this->assertTrue( $side->pinned, 'a variation without a photo stays on the version its siblings are pinned to' );
				$this->assertSame( 2, $side->template_version );
			} else {
				$this->assertFalse( $side->pinned );
			}
		}
	}

	public function test_a_value_the_blank_gains_is_made_once_the_product_offers_it_keeping_every_variation(): void {
		$product = $this->poster();
		$this->framed_variation( $product );
		$this->script_costs_twice();
		$this->generated( $product );
		$before = $this->sold( $product );

		$this->variants->by_blank[ self::FRAMED ] = self::framed_variants( array( 'Black', 'White', 'Natural Wood' ) );
		$oak                                      = $this->term( 'colour', 'oak', 'Ljust trä' );
		BlankOptionLinks::set( $this->attribute_ids['colour'], 'Color', array( $oak => 'Natural Wood' ) + $this->linked( 'colour' ) );
		$this->offer( $product, 'colour', $oak );
		FreshRequest::begin( $this->plugin );
		$this->script_costs_twice();

		$dry = $this->generate->execute( array( 'product_id' => $product->get_id() ) );
		$this->assertIsArray( $dry );
		$this->assertSame(
			array( 's30|framed|oak|With', 's30|framed|oak|Without', 's50|framed|oak|With', 's50|framed|oak|Without' ),
			array_map( static fn ( array $row ): string => implode( '|', (array) $row['attributes'] ), $dry['add'] )
		);

		$this->generated( $product );

		$after = $this->sold( $product );
		$this->assertCount( 16, $after );
		foreach ( $before as $key => $was ) {
			$this->assertSame( $was['id'], $after[ $key ]['id'], 'the variations there were are kept' );
		}
		$this->assertSame( 'v_framed_s50_natural_wood', $after['s50|framed|oak|With']['ref'] );
	}

	public function test_a_retired_value_disables_its_variations_and_offered_again_publishes_them_again(): void {
		$product = $this->poster();
		$this->framed_variation( $product );
		$this->script_costs_twice();
		$this->generated( $product );
		$sold          = $this->sold( $product );
		$by_hand       = $sold['s30|framed|black|Without']['id'];
		$disabled_self = wc_get_product( $by_hand );
		$this->assertInstanceOf( WC_Product::class, $disabled_self );
		$disabled_self->set_status( 'private' );
		$disabled_self->save();

		$this->variants->by_blank[ self::FRAMED ] = self::framed_variants( array( 'Black', 'White' ), array( 'White' ) );
		FreshRequest::begin( $this->plugin );

		$dry = $this->generate->execute( array( 'product_id' => $product->get_id() ) );
		$this->assertIsArray( $dry );
		$this->assertCount( 4, $dry['disable'] );
		$this->assertSame( array( VariationChanges::RETIRED ), array_values( array_unique( array_column( $dry['disable'], 'why' ) ) ) );
		$this->assertCount( 4, $dry['skipped'] );

		$this->generated( $product );
		$white = wc_get_product( $sold['s50|framed|white|With']['id'] );
		$this->assertInstanceOf( WC_Product::class, $white, 'never deleted' );
		$this->assertSame( 'private', $white->get_status() );
		$this->assertTrue( GeneratedVariation::disabled( $white ) );

		$this->variants->by_blank[ self::FRAMED ] = self::framed_variants( array( 'Black', 'White' ) );
		FreshRequest::begin( $this->plugin );
		$this->script_costs_twice();
		$again = $this->generated( $product );

		$this->assertCount( 4, $again['change'] );
		$this->assertSame( array( VariationChanges::ENABLE ), array_values( array_unique( array_column( $again['change'], 'why' ) ) ) );
		$this->assertSame( ProductConfig::PUBLIC_STATUS, $this->reread( $white )->get_status() );
		$this->assertSame( 'private', $this->reread( $disabled_self )->get_status(), 'a variation the store manager disabled stays disabled' );
	}

	public function test_a_run_cut_short_leaves_its_unfinished_variation_disabled_and_the_next_run_finishes_and_prices_it(): void {
		$product = $this->poster();
		$this->framed_variation( $product );
		$made  = 0;
		$crash = static function () use ( &$made ): void {
			if ( 3 === ++$made ) {
				throw new \RuntimeException( 'The request died.' );
			}
		};
		add_action( 'woocommerce_new_product_variation', $crash );
		try {
			$this->generated( $product );
			$this->fail( 'the run should have been cut short' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The request died.', $e->getMessage() );
		} finally {
			remove_action( 'woocommerce_new_product_variation', $crash );
		}
		$cut = $this->sold( $product );
		$this->assertCount( 4, $cut );
		$this->assertSame( array( ProductConfig::PUBLIC_STATUS, ProductConfig::PUBLIC_STATUS, ProductConfig::PUBLIC_STATUS, 'private' ), array_column( $cut, 'status' ), 'the unfinished variation is not sold' );

		FreshRequest::begin( $this->plugin );
		$this->script_costs_twice();
		$again = $this->generated( $product );

		$this->assertCount( 8, $again['made'] );
		$this->assertCount( 1, $again['change'] );
		$this->assertSame( VariationChanges::ENABLE, $again['change'][0]['why'] );
		$sold = $this->sold( $product );
		$this->assertCount( 12, $sold );
		$this->assertSame( array( ProductConfig::PUBLIC_STATUS ), array_values( array_unique( array_column( $sold, 'status' ) ) ) );
		$this->assertNotContains( null, array_column( $sold, 'ref' ) );
		$this->assertSame( array( 's30|framed|black|With' ), array_keys( array_filter( $sold, static fn ( array $one ): bool => '' === $one['price'] ) ), 'every variation made is priced, those of the run cut short too' );
	}

	public function test_a_variation_sold_for_any_value_the_plan_offers_one_by_one_refuses_the_plan(): void {
		$product   = $this->poster();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes(
			array(
				'pa_size'  => 's30',
				'pa_mount' => 'none',
			)
		);
		$variation->set_status( ProductConfig::PUBLIC_STATUS );
		$variation->save();

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => self::PLAN,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertStringContainsString( sprintf( 'Variation #%d', $variation->get_id() ), $answer->get_error_message() );
	}

	public function test_a_product_whose_variations_are_being_generated_is_not_generated_twice_at_once(): void {
		$product = $this->poster();
		add_option( Schema::VARIATION_PLAN_LOCK_OPTION_PREFIX . $product->get_id(), (string) time(), '', false );

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => self::PLAN,
				'dry_run'    => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_variations_generating', $answer->get_error_code() );
		$this->assertSame( array(), $this->reread( $product )->get_children() );
		$this->assertIsArray(
			$this->generate->execute(
				array(
					'product_id' => $product->get_id(),
					'entries'    => self::PLAN,
				)
			),
			'a dry run still lists'
		);
		delete_option( Schema::VARIATION_PLAN_LOCK_OPTION_PREFIX . $product->get_id() );
	}

	/**
	 * @return array<string, array{list<array<string, mixed>>, string}>
	 */
	public static function refusals(): array {
		return array(
			'two blanks for the same values' => array(
				array(
					array(
						'blank_id' => self::POSTER,
						'fixed'    => array( 'Orientation' => 'Vertical' ),
					),
					array( 'blank_id' => self::FRAMED ),
				),
				'would sell the same values',
			),
			'an option left open'            => array(
				array( array( 'blank_id' => self::POSTER ) ),
				'Orientation',
			),
			'a value the product lacks'      => array(
				array(
					array(
						'blank_id' => self::POSTER,
						'when'     => array( 'pa_mount' => 'hanger' ),
					),
				),
				'does not offer hanger',
			),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param array<mixed> $entries The plan.
	 * @param string       $says    Part of the refusal.
	 */
	public function test_a_plan_that_cannot_be_used_is_refused_and_changes_nothing( array $entries, string $says ): void {
		$product = $this->poster();

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => $entries,
				'dry_run'    => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertStringContainsString( $says, $answer->get_error_message() );
		$this->assertSame( array(), $this->reread( $product )->get_children() );
		$this->assertNull( VariationPlan::stored_on( $this->reread( $product ) ) );
	}

	public function test_an_option_no_attribute_offers_is_refused_with_its_reason(): void {
		$product = $this->poster();

		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'entries'    => array( array( 'blank_id' => self::POSTER ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( VariationCombinations::OPTION_UNLINKED, $answer->get_error_data()['reason'] ?? null );
	}

	public function test_the_plan_reads_with_the_attributes_and_the_options_they_stand_for(): void {
		$product = $this->poster();
		$this->framed_variation( $product );
		VariationPlan::from_input( self::PLAN )->save_to( $product );

		$read = ( new GetProductVariationPlan() )->execute( array( 'product_id' => $product->get_id() ) );

		$this->assertIsArray( $read );
		$this->assertSame( array( self::POSTER, self::FRAMED ), array_column( $read['entries'], 'blank_id' ) );
		$this->assertSame( array( 'pa_size', 'pa_mount', 'pa_colour', 'photo' ), array_column( $read['attributes'], 'attribute' ) );
		$this->assertSame( array( 'Size', null, 'Color', null ), array_column( $read['attributes'], 'option' ) );
		$this->assertSame( array( false, true, false, false ), array_column( $read['attributes'], 'picks_blank' ) );
		$this->assertSame( array( '30x40cm', '50x70cm' ), array_column( $read['attributes'][0]['values'], 'stands_for' ) );
		$this->assertSame( array( self::POSTER, self::FRAMED ), array_column( $read['blanks'], 'blank_id' ) );
	}

	public function test_the_variations_tab_previews_the_plan_and_generates_it(): void {
		$product = $this->poster();
		$this->framed_variation( $product );
		$panel   = new VariationPlanPanel( fn (): GenerateProductVariations => $this->generate, $this->plugin->templates(), static fn (): bool => true );
		$request = new WP_REST_Request( 'POST', '/pixonaut/v1/products/' . $product->get_id() . '/variation-plan' );
		$request->set_url_params( array( 'product' => (string) $product->get_id() ) );
		$request->set_param( 'entries', self::PLAN );
		$request->set_param( 'dry_run', true );

		$preview = $panel->act( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $preview );
		$data = $preview->get_data();
		$this->assertSame( 'Generating would add 11, change 0 and disable 0 variations.', $data['message'] );
		$this->assertFalse( $data['changed'] );
		$this->assertStringContainsString( 'To add (11)', $data['html'] );
		$this->assertStringContainsString( 'size: 30×40 cm, mount: Ram, colour: Vit, Photo: With', $data['html'] );
		$this->assertStringContainsString( 'value="Orientation: Vertical"', $data['html'], 'the plan previewed stays in the fields' );
		$this->assertCount( 1, $this->reread( $product )->get_children() );

		$this->script_costs_twice();
		$request->set_param( 'dry_run', false );
		$made = $panel->act( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $made );
		$this->assertTrue( $made->get_data()['changed'] );
		$this->assertCount( 12, $this->reread( $product )->get_children() );
		$this->assertNotNull( VariationPlan::stored_on( $this->reread( $product ) ) );

		global $product_object;
		$product_object = $this->reread( $product );
		ob_start();
		$panel->render();
		$this->assertStringContainsString( 'Variations from the blank', (string) ob_get_clean() );
	}

	/**
	 * Generates the product's variations from its plan.
	 *
	 * @param WC_Product $product The product.
	 * @return array<string, mixed>
	 */
	private function generated( WC_Product $product ): array {
		$plan   = VariationPlan::stored_on( $this->reread( $product ) );
		$answer = $this->generate->execute(
			array(
				'product_id' => $product->get_id(),
				'dry_run'    => false,
			) + ( null === $plan ? array( 'entries' => self::PLAN ) : array() )
		);
		$this->assertIsArray( $answer );
		return $answer;
	}

	/**
	 * Each variation of the product, by its values joined with `|`: its id,
	 * status, the variant it sells, its blank, whether it has a setup of
	 * its own, and its price.
	 *
	 * @param WC_Product $product The product.
	 * @return array<string, array{id: int, status: string, ref: string|null, blank: string|null, own: bool, price: string}>
	 */
	private function sold( WC_Product $product ): array {
		$sold = array();
		foreach ( $this->reread( $product )->get_children() as $id ) {
			$variation = wc_get_product( $id );
			$this->assertInstanceOf( WC_Product_Variation::class, $variation );
			$held         = $variation->get_attributes( 'edit' );
			$key          = implode( '|', array( $held['pa_size'] ?? '', $held['pa_mount'] ?? '', $held['pa_colour'] ?? '', $held['photo'] ?? '' ) );
			$config       = ProductConfig::config_for( $variation );
			$sold[ $key ] = array(
				'id'     => $id,
				'status' => $variation->get_status(),
				'ref'    => ProductVariant::stored_on( $variation )?->ref,
				'blank'  => $config->blank_id,
				'own'    => ProductConfig::stored_on( $variation )->override,
				'price'  => $variation->get_regular_price( 'edit' ),
			);
		}
		return $sold;
	}

	private function reread( mixed $product ): WC_Product {
		$this->assertInstanceOf( WC_Product::class, $product );
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}

	/**
	 * Scripts Pixonaut's answers to one check of the framed setup: the
	 * blank, the template on its placement, the verdict and the template's
	 * status.
	 *
	 * @param HttpRecorder $checks   The recorder of Pixonaut's server API.
	 * @param string       $template The template's id.
	 * @param int          $version  The template's version.
	 */
	private static function script_framed_check( HttpRecorder $checks, string $template = 'mini-poster', int $version = 1 ): void {
		$checks->json( 200, BlankFixture::answer( array( 'front' => 'Front' ) ) );
		$checks->json(
			200,
			array(
				'templateId'     => $template . '@' . $version,
				'ratio'          => array(
					'w' => 280,
					'h' => 360,
				),
				'ratioTolerance' => 0,
				'fit'            => 'contain',
				'placement'      => array(
					'blankId'            => self::FRAMED,
					'placementId'        => 'front',
					'maxWidthMm'         => 330,
					'maxHeightMm'        => 420,
					'defaultWidthMm'     => 300,
					'defaultHeightMm'    => 360,
					'printWidthMm'       => 280,
					'printHeightMm'      => 360,
					'bleedMm'            => 3,
					'safeAreaMm'         => 5,
					'cmykPreviewProfile' => 'ISOcoated_v2_eci',
					'coverage'           => 'within',
					'sizes'              => array(
						'fits'    => array(),
						'misfits' => array(),
					),
				),
			)
		);
		$checks->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$checks->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
	}

	/**
	 * The poster, on the poster blank, offering every value but no variation yet.
	 */
	/**
	 * @param PrintSize|null  $size        The size its side is sold in; null for none picked.
	 * @param FormGroups|null $arrangement Its form's arrangement; null for the template's own form.
	 */
	private function poster( ?PrintSize $size = null, ?FormGroups $arrangement = null ): WC_Product_Variable {
		$product    = new WC_Product_Variable();
		$attributes = array();
		foreach ( array_keys( self::ATTRIBUTES ) as $slug ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $this->attribute_ids[ $slug ] );
			$attribute->set_name( 'pa_' . $slug );
			$attribute->set_options( array_values( $this->terms[ $slug ] ) );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$photo = new WC_Product_Attribute();
		$photo->set_name( 'Photo' );
		$photo->set_options( self::PHOTO );
		$photo->set_variation( true );
		$attributes[] = $photo;
		$product->set_name( 'Birth poster' );
		$product->set_attributes( $attributes );
		$product->save();
		( new ProductConfig( personalise: true, blank_id: self::POSTER, sides: array( self::side( $size )->with_form_groups( $arrangement ) ) ) )->save_to( $product );
		return $product;
	}

	/**
	 * The framed variation the store manager made by hand: framed in black,
	 * with a photo, on its own framed setup, at no price yet.
	 *
	 * @param WC_Product $product The product.
	 */
	private function framed_variation( WC_Product $product ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes(
			array(
				'pa_size'   => 's30',
				'pa_mount'  => 'framed',
				'pa_colour' => 'black',
				'photo'     => 'With',
			)
		);
		$variation->set_status( ProductConfig::PUBLIC_STATUS );
		$variation->save();
		( new ProductConfig( override: true, personalise: true, blank_id: self::FRAMED, sides: array( self::side() ) ) )->save_to( $variation );
		( new ProductVariant( self::FRAMED, 'v_framed_s30_black', 'v_framed_s30_black', 'Size: 30x40cm, Color: Black' ) )->save_to( $variation );
		return $variation;
	}

	/**
	 * The unframed variations the store manager made by hand, one per size
	 * and photo choice: those with a photo follow the product, those
	 * without are on a template without a photo.
	 *
	 * @param WC_Product $product The product.
	 * @param Side|null  $without The side of those without a photo; null for one on a template without a photo.
	 */
	private function unframed_variations( WC_Product $product, ?Side $without = null ): void {
		$without ??= new Side( 'front', 'Front', 'front', 'Front', 'plain-poster', 1, null, 'Plain poster' );
		foreach ( array( 's30', 's50' ) as $size ) {
			foreach ( self::PHOTO as $photo ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
				$variation->set_attributes(
					array(
						'pa_size'   => $size,
						'pa_mount'  => 'none',
						'pa_colour' => '',
						'photo'     => $photo,
					)
				);
				$variation->set_regular_price( '300' );
				$variation->set_status( ProductConfig::PUBLIC_STATUS );
				$variation->save();
				if ( 'Without' === $photo ) {
					( new ProductConfig( override: true, personalise: true, blank_id: self::POSTER, sides: array( $without ) ) )->save_to( $variation );
				}
				$ref = 'v_poster_' . $size . '_vertical';
				( new ProductVariant( self::POSTER, $ref, $ref, 'Size: ' . ( 's30' === $size ? '30x40cm' : '50x70cm' ) . ', Orientation: Vertical' ) )->save_to( $variation );
			}
		}
	}

	/**
	 * Adds a value to the product's attribute.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $slug    The attribute's slug.
	 * @param int        $term    The value's term.
	 */
	private function offer( WC_Product $product, string $slug, int $term ): void {
		$product    = $this->reread( $product );
		$attributes = $product->get_attributes();
		$attribute  = $attributes[ 'pa_' . $slug ];
		$this->assertInstanceOf( WC_Product_Attribute::class, $attribute );
		$attribute = clone $attribute;
		$attribute->set_options( array_merge( $attribute->get_options(), array( $term ) ) );
		$attributes[ 'pa_' . $slug ] = $attribute;
		$product->set_attributes( $attributes );
		$product->save();
	}

	/**
	 * Makes a global attribute with its values, linked to the option given.
	 *
	 * @param string                                    $slug   The attribute's slug.
	 * @param string|null                               $option The blank option it stands for; null for none.
	 * @param array<string, array{string, string|null}> $values Each value's name and option value, by slug.
	 */
	private function attribute( string $slug, ?string $option, array $values ): void {
		$id = wc_create_attribute(
			array(
				'name' => $slug,
				'slug' => $slug,
			)
		);
		$this->assertIsInt( $id );
		$this->attribute_ids[ $slug ] = $id;
		register_taxonomy( 'pa_' . $slug, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ 'pa_' . $slug ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
		$linked = array();
		foreach ( $values as $value => list( $name, $stands_for ) ) {
			$term = $this->term( $slug, $value, $name );
			if ( null !== $stands_for ) {
				$linked[ $term ] = $stands_for;
			}
		}
		if ( null !== $option ) {
			BlankOptionLinks::set( $id, $option, $linked );
		}
	}

	private function term( string $slug, string $value, string $name ): int {
		$term = wp_insert_term( $name, 'pa_' . $slug, array( 'slug' => $value ) );
		$this->assertIsArray( $term );
		$this->terms[ $slug ][ $value ] = (int) $term['term_id'];
		return (int) $term['term_id'];
	}

	/**
	 * The option values an attribute's values are linked to, by term id.
	 *
	 * @param string $slug The attribute's slug.
	 * @return array<int, string>
	 */
	private function linked( string $slug ): array {
		return BlankOptionLinks::of( $this->attribute_ids[ $slug ] )['values'] ?? array();
	}

	/**
	 * The poster blank: two sizes, upright or on its side.
	 *
	 * @return array<string, mixed>
	 */
	private static function poster_variants(): array {
		$variants = array();
		foreach ( array( '30x40cm', '50x70cm' ) as $size ) {
			foreach ( array( 'Vertical', 'Horizontal' ) as $orientation ) {
				$variants[] = array(
					'ref'     => 'v_poster_' . self::ref_part( $size ) . '_' . strtolower( $orientation ),
					'options' => array(
						'Size'        => $size,
						'Orientation' => $orientation,
					),
				);
			}
		}
		return BlankFixture::variants( $variants, array( 'Size', 'Orientation' ) );
	}

	/**
	 * The framed blank: two sizes in the colours given, upright only.
	 *
	 * @param string[] $colours The colours it sells.
	 * @param string[] $retired The colours retired.
	 * @phpstan-param list<string> $colours
	 * @phpstan-param list<string> $retired
	 * @return array<string, mixed>
	 */
	private static function framed_variants( array $colours, array $retired = array() ): array {
		$variants = array();
		foreach ( array( '30x40cm', '50x70cm' ) as $size ) {
			foreach ( $colours as $colour ) {
				$variants[] = array(
					'ref'     => 'v_framed_' . self::ref_part( $size ) . '_' . str_replace( ' ', '_', strtolower( $colour ) ),
					'options' => array(
						'Size'        => $size,
						'Color'       => $colour,
						'Orientation' => 'Vertical',
					),
					'retired' => in_array( $colour, $retired, true ) ? array( 'reason' => 'no_longer_sold' ) : null,
				);
			}
		}
		return BlankFixture::variants( $variants, array( 'Size', 'Color', 'Orientation' ) );
	}

	/**
	 * Scripts the costs each blank reads in one run.
	 */
	private function script_costs_twice(): void {
		$this->script_costs( self::costs() );
		$this->script_costs( self::costs() );
	}

	private static function ref_part( string $size ): string {
		return 's' . substr( $size, 0, 2 );
	}

	/**
	 * Every variant's cost, in one answer: each blank reads it.
	 *
	 * @return array<string, string>
	 */
	private static function costs(): array {
		$costs = array();
		foreach ( array( 's30', 's50' ) as $size ) {
			foreach ( array( 'vertical', 'horizontal' ) as $orientation ) {
				$costs[ 'v_poster_' . $size . '_' . $orientation ] = 's30' === $size ? '150' : '190';
			}
			foreach ( array( 'black', 'white', 'natural_wood' ) as $colour ) {
				$costs[ 'v_framed_' . $size . '_' . $colour ] = 's30' === $size ? '400' : '600';
			}
		}
		return $costs;
	}

	/**
	 * @param PrintSize|null $size The size it is sold in; null for none picked.
	 */
	private static function side( ?PrintSize $size = null ): Side {
		return new Side( 'front', 'Front', 'front', 'Front', 'mini-poster', 1, null, 'Mini poster', null === $size ? array() : array( $size ) );
	}

	private static function connection(): StoredConnection {
		return StoredConnection::from_array(
			array(
				'site'                  => 'site-a',
				'home_url'              => Connection::site_url(),
				'connected_at'          => 1790000000,
				'tenant_id'             => 'tenant',
				'tenant_name'           => 'Acme',
				'environment'           => 'live',
				'connection_id'         => 'sc_1',
				'integration_key_id'    => 'key-id',
				'integration_key'       => 'ik_live_0123456789abcdef01234567',
				'integration_key_label' => 'Shop',
				'publishable_key_id'    => 'pub-id',
				'publishable_key'       => 'pk_live_89abcdef0123456789abcdef',
				'allowed_origins'       => array( 'http://example.org' ),
				'webhook_id'            => 'hook',
				'webhook_url'           => 'http://example.org/hook',
				'webhook_secret'        => 'whsec_0123456789abcdef0123456789abcdef',
			)
		);
	}
}
