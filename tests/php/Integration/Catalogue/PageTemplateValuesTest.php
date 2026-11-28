<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Abilities\ProductSetup;
use Pixonaut\WooCommerce\Abilities\SetProductValues;
use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Api\ValueSets;
use Pixonaut\WooCommerce\Catalogue\LayoutValueSets;
use Pixonaut\WooCommerce\Catalogue\PageTemplate;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValues;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use WP_UnitTestCase;

/**
 * A book side's own values reach every page layout it offers: a value set
 * per layout made of the values for the layers it has, made by
 * `set-product-values` and again by a saved setup whose layouts changed.
 * A scripted maker of value sets stands in for Pixonaut.
 */
final class PageTemplateValuesTest extends WP_UnitTestCase {

	private const TEMPLATE = 'mini-book';

	private const TWO_PHOTOS = 'two-photos';

	private const FOUR_PHOTOS = 'four-photos';

	private const PHOTO_AND_TITLE = 'photo-and-title';

	/**
	 * The status Pixonaut answers while it is down.
	 */
	private const UNAVAILABLE = 503;

	/**
	 * Each template version's layers, as the scripted maker reads them.
	 */
	private const LAYERS = array(
		self::TEMPLATE . '@3'        => array( 'title', 'motif', 'photo' ),
		self::TWO_PHOTOS . '@2'      => array( 'title', 'photo-1', 'photo-2' ),
		self::FOUR_PHOTOS . '@1'     => array( 'grid' ),
		self::PHOTO_AND_TITLE . '@1' => array( 'title', 'photo-2' ),
	);

	private const VALUES = array(
		'title'   => array( 'content' => 'Sloth' ),
		'motif'   => array( 'src' => 'asset_sloth' ),
		'photo-2' => array( 'fit' => 'cover' ),
	);

	/**
	 * Each set the scripted maker was asked for: the template and values.
	 *
	 * @var list<array{0: string, 1: array<string, array<string, mixed>>}>
	 */
	private array $asked = array();

	/**
	 * Templates whose values the scripted maker refuses.
	 *
	 * @var list<string>
	 */
	private array $refusing = array();

	/**
	 * Templates the scripted maker cannot be asked about, as if Pixonaut were down.
	 *
	 * @var list<string>
	 */
	private array $unreachable = array();

	public function test_a_book_sides_values_reach_each_layout_with_the_layers_it_has(): void {
		$product = $this->book( array( self::TWO_PHOTOS . '@2', self::FOUR_PHOTOS . '@1' ) );

		$answer = $this->set( $product );

		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : '' );
		$this->assertSame(
			array(
				array(
					self::TWO_PHOTOS . '@2',
					array(
						'title'   => self::VALUES['title'],
						'photo-2' => self::VALUES['photo-2'],
					),
				),
				array(
					self::TEMPLATE . '@3',
					array(
						'title' => self::VALUES['title'],
						'motif' => self::VALUES['motif'],
					),
				),
			),
			$this->asked,
			'the layout of four photos has none of the layers'
		);
		$side = ProductConfig::stored_on( $this->reload( $product ) )->sides[0];
		$this->assertSame(
			array(
				self::TEMPLATE   => self::set_id( self::TEMPLATE . '@3' ),
				self::TWO_PHOTOS => self::set_id( self::TWO_PHOTOS . '@2' ),
			),
			SideValues::sets_of( $this->reload( $product ), $side )
		);
		$this->assertSame( self::VALUES, SideValues::of( $this->reload( $product ), $side )?->values, 'the values are kept as given' );
		$this->assertEquals( (object) array( self::TWO_PHOTOS => self::set_id( self::TWO_PHOTOS . '@2' ) ), $answer['sides'][0]['page_template_value_sets'] );
	}

	public function test_a_layer_neither_the_sides_template_nor_a_layout_has_is_refused_and_nothing_saved(): void {
		$product = $this->book( array( self::TWO_PHOTOS . '@2' ) );

		$answer = $this->set( $product, self::VALUES + array( 'ghost' => array( 'content' => 'Boo' ) ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertStringContainsString( '`ghost`', $answer->get_error_message() );
		$this->assertSame( array(), $this->asked );
		$this->assertNull( SideValues::document_on( $this->reload( $product ) ) );
	}

	public function test_values_a_layouts_rules_refuse_refuse_the_call(): void {
		$product          = $this->book( array( self::TWO_PHOTOS . '@2' ) );
		$this->refusing[] = self::TWO_PHOTOS . '@2';

		$answer = $this->set( $product );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertStringContainsString( '`photo-2`', $answer->get_error_message() );
		$this->assertNull( SideValues::document_on( $this->reload( $product ) ) );
	}

	public function test_a_setup_saved_with_other_layouts_drops_the_sets_of_the_layouts_gone(): void {
		$product = $this->book( array( self::TWO_PHOTOS . '@2' ) );
		$this->assertIsArray( $this->set( $product ) );
		$config = ProductConfig::stored_on( $this->reload( $product ) );
		$config->with_sides( array( $config->sides[0]->with_page_templates( array( new PageTemplate( self::FOUR_PHOTOS, 1 ) ) ) ) )->save_to( $product );
		$this->asked = array();

		( new LayoutValueSets( $this->maker(), new RecordingLogger() ) )->refresh( $this->reload( $product ) );

		// The layout of four photos has none of the values' layers.
		$this->assertSame( array(), SideValues::stored_on( $this->reload( $product ) )['pages']->layout_sets );
		$this->assertSame( array(), $this->asked );
	}

	public function test_a_setup_saved_with_a_new_layout_makes_its_set_once(): void {
		$product = $this->book( array() );
		$this->assertIsArray( $this->set( $product ) );
		$config = ProductConfig::stored_on( $this->reload( $product ) );
		$config->with_sides( array( $config->sides[0]->with_page_templates( array( new PageTemplate( self::TWO_PHOTOS, 2 ) ) ) ) )->save_to( $product );
		$this->asked = array();
		$refresh     = new LayoutValueSets( $this->maker(), new RecordingLogger() );

		$refresh->refresh( $this->reload( $product ) );
		$refresh->refresh( $this->reload( $product ) );

		$this->assertSame(
			array(
				array(
					self::TWO_PHOTOS . '@2',
					array(
						'title'   => self::VALUES['title'],
						'photo-2' => self::VALUES['photo-2'],
					),
				),
			),
			$this->asked
		);
		$this->assertSame( array( self::TWO_PHOTOS => self::set_id( self::TWO_PHOTOS . '@2' ) ), SideValues::stored_on( $this->reload( $product ) )['pages']->layout_sets );
	}

	public function test_a_variation_of_its_own_sold_with_its_products_values_has_a_set_for_a_layout_only_it_offers(): void {
		list( $product, $variation ) = $this->variable_book();
		$this->assertIsArray( $this->set( $product ) );
		$this->own_setup( $variation, array( self::TWO_PHOTOS . '@2', self::PHOTO_AND_TITLE . '@1' ) );
		$this->asked = array();

		$this->assertTrue( ( new LayoutValueSets( $this->maker(), new RecordingLogger() ) )->refresh( $this->reload( $variation ) ) );

		$side = ProductConfig::stored_on( $this->reload( $variation ) )->sides[0];
		$this->assertSame( SideValues::FROM_PRODUCT, SideValues::sold_with( $this->reload( $variation ), $side )['from'] );
		$this->assertSame(
			array(
				array(
					self::PHOTO_AND_TITLE . '@1',
					array(
						'title'   => self::VALUES['title'],
						'photo-2' => self::VALUES['photo-2'],
					),
				),
			),
			$this->asked,
			'only the layout its product does not offer is asked for'
		);
		$this->assertSame(
			array(
				self::TEMPLATE        => self::set_id( self::TEMPLATE . '@3' ),
				self::TWO_PHOTOS      => self::set_id( self::TWO_PHOTOS . '@2' ),
				self::PHOTO_AND_TITLE => self::set_id( self::PHOTO_AND_TITLE . '@1' ),
			),
			SideValues::sets_of( $this->reload( $variation ), $side )
		);

		$this->assertIsArray( $this->set( $product, array( 'title' => array( 'content' => 'Otter' ) ) ) );

		$this->assertSame(
			self::set_id( self::PHOTO_AND_TITLE . '@1' ),
			SideValues::sets_of( $this->reload( $variation ), $side )[ self::PHOTO_AND_TITLE ] ?? null,
			'the product\'s new values reach the variation\'s layout too'
		);
		$this->assertSame( array( self::PHOTO_AND_TITLE . '@1', array( 'title' => array( 'content' => 'Otter' ) ) ), end( $this->asked ) );
	}

	public function test_a_layout_left_without_its_set_while_pixonaut_is_down_is_pending_until_a_retry_makes_it(): void {
		$product = $this->book( array() );
		$this->assertIsArray( $this->set( $product ) );
		$config = ProductConfig::stored_on( $this->reload( $product ) );
		$config->with_sides( array( $config->sides[0]->with_page_templates( array( new PageTemplate( self::TWO_PHOTOS, 2 ) ) ) ) )->save_to( $product );
		$this->unreachable[] = self::TWO_PHOTOS . '@2';
		$logger              = new RecordingLogger();
		$refresh             = new LayoutValueSets( $this->maker(), $logger );

		$this->assertFalse( $refresh->refresh( $this->reload( $product ) ) );

		$side = ProductConfig::stored_on( $this->reload( $product ) )->sides[0];
		$this->assertSame( array( self::TWO_PHOTOS ), SideValues::pending_of( $this->reload( $product ), $side ) );
		$this->assertTrue( SideValues::any_pending( $this->reload( $product ), ProductConfig::stored_on( $this->reload( $product ) ) ), 'the book is not sold' );
		$this->assertArrayNotHasKey( self::TWO_PHOTOS, SideValues::sets_of( $this->reload( $product ), $side ) );
		$this->assertSame( array( self::TWO_PHOTOS ), ProductSetup::side_values( $this->reload( $product ), $side )['page_templates_pending'] ?? null );
		$this->assertNotSame( array(), $logger->entries );

		$this->unreachable = array();
		$this->assertTrue( $refresh->retry( $this->reload( $product ) ) );

		$this->assertSame( array(), SideValues::pending_of( $this->reload( $product ), $side ) );
		$this->assertFalse( SideValues::any_pending( $this->reload( $product ), ProductConfig::stored_on( $this->reload( $product ) ) ) );
		$this->assertSame( self::set_id( self::TWO_PHOTOS . '@2' ), SideValues::sets_of( $this->reload( $product ), $side )[ self::TWO_PHOTOS ] ?? null );
	}

	/**
	 * Sets the book side's values with the scripted maker.
	 *
	 * @param WC_Product                          $product The book.
	 * @param array<string, array<string, mixed>> $values  The values.
	 * @return array<string, mixed>|WP_Error
	 */
	private function set( WC_Product $product, array $values = self::VALUES ): array|WP_Error {
		return ( new SetProductValues( $this->maker() ) )->execute(
			array(
				'product_id' => $product->get_id(),
				'sides'      => array(
					array(
						'key'    => 'pages',
						'values' => $values,
					),
				),
			)
		);
	}

	/**
	 * The scripted maker of value sets.
	 */
	private function maker(): ValueSets {
		$test = $this;
		return new class( $test ) implements ValueSets {
			public function __construct( private readonly PageTemplateValuesTest $test ) {
			}

			public function make( string $template_id, array $values ): string {
				return $this->test->made( $template_id, $values );
			}

			public function layer_ids( string $template_id ): array {
				return PageTemplateValuesTest::layers( $template_id );
			}
		};
	}

	/**
	 * The scripted maker's answer to one call.
	 *
	 * @param string                              $template_id The template version.
	 * @param array<string, array<string, mixed>> $values      The values.
	 *
	 * @throws ValueSetRefused For a template it refuses.
	 * @throws ApiException    For a template it cannot be asked about.
	 */
	public function made( string $template_id, array $values ): string {
		if ( in_array( $template_id, $this->unreachable, true ) ) {
			throw new ApiException( 'templates.valueSets.create', self::UNAVAILABLE, 'service_unavailable', true );
		}
		if ( in_array( $template_id, $this->refusing, true ) ) {
			throw new ValueSetRefused(
				array(
					array(
						'layer_id' => 'photo-2',
						'prop'     => 'fit',
						'rule'     => 'not_in_options',
					),
				)
			);
		}
		$this->asked[] = array( $template_id, $values );
		return self::set_id( $template_id );
	}

	/**
	 * A template version's layers.
	 *
	 * @param string $template_id The template version.
	 * @return list<string>
	 */
	public static function layers( string $template_id ): array {
		return self::LAYERS[ $template_id ] ?? array();
	}

	/**
	 * The set the scripted maker makes for a template.
	 *
	 * @param string $template_id The template version.
	 */
	private static function set_id( string $template_id ): string {
		return 'vs_' . md5( $template_id );
	}

	/**
	 * A book whose one side, its pages, offers the layouts given.
	 *
	 * @param string[] $layouts Each layout's version.
	 * @phpstan-param list<string> $layouts
	 */
	private function book( array $layouts ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'Photo book' );
		$product->save();
		( new ProductConfig( personalise: true, blank_id: 'blank_book', sides: array( self::book_side( $layouts ) ) ) )->save_to( $product );
		return $this->reload( $product );
	}

	/**
	 * A variable book whose product's side offers the layout of two photos,
	 * and one variation of it with a setup of its own on the same template.
	 *
	 * @return array{0: WC_Product, 1: WC_Product}
	 */
	private function variable_book(): array {
		$product = new WC_Product_Variable();
		$product->set_name( 'Photo book' );
		$product->save();
		( new ProductConfig( personalise: true, blank_id: 'blank_book', sides: array( self::book_side( array( self::TWO_PHOTOS . '@2' ) ) ) ) )->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '10' );
		$variation->save();
		$this->own_setup( $variation, array( self::TWO_PHOTOS . '@2' ) );
		return array( $this->reload( $product ), $this->reload( $variation ) );
	}

	/**
	 * Gives a variation a setup of its own whose book side offers the layouts given.
	 *
	 * @param WC_Product $variation The variation.
	 * @param string[]   $layouts   Each layout's version.
	 * @phpstan-param list<string> $layouts
	 */
	private function own_setup( WC_Product $variation, array $layouts ): void {
		( new ProductConfig( personalise: true, override: true, blank_id: 'blank_book', sides: array( self::book_side( $layouts ) ) ) )->save_to( $this->reload( $variation ) );
	}

	/**
	 * The book's side, its pages, offering the layouts given.
	 *
	 * @param string[] $layouts Each layout's version.
	 * @phpstan-param list<string> $layouts
	 */
	private static function book_side( array $layouts ): Side {
		$side = new Side( 'pages', 'Pages', 'pages', 'Pages', self::TEMPLATE, 3, null, 'Mini book' );
		return $side->with_page_templates(
			array_map(
				static function ( string $id ): PageTemplate {
					list( $base, $version ) = explode( '@', $id );
					return new PageTemplate( $base, (int) $version );
				},
				$layouts
			)
		);
	}

	private function reload( WC_Product $product ): WC_Product {
		wp_cache_delete( (string) $product->get_id(), 'post_meta' );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}
}
