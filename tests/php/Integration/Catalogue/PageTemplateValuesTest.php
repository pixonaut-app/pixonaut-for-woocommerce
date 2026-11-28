<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Abilities\SetProductValues;
use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Api\ValueSets;
use Pixonaut\WooCommerce\Catalogue\LayoutValueSets;
use Pixonaut\WooCommerce\Catalogue\PageTemplate;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValues;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use WC_Product;
use WC_Product_Simple;
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

	/**
	 * Each template version's layers, as the scripted maker reads them.
	 */
	private const LAYERS = array(
		self::TEMPLATE . '@3'    => array( 'title', 'motif', 'photo' ),
		self::TWO_PHOTOS . '@2'  => array( 'title', 'photo-1', 'photo-2' ),
		self::FOUR_PHOTOS . '@1' => array( 'grid' ),
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
	 */
	public function made( string $template_id, array $values ): string {
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
		$side = new Side( 'pages', 'Pages', 'pages', 'Pages', self::TEMPLATE, 3, null, 'Mini book' );
		$side = $side->with_page_templates(
			array_map(
				static function ( string $id ): PageTemplate {
					list( $base, $version ) = explode( '@', $id );
					return new PageTemplate( $base, (int) $version );
				},
				$layouts
			)
		);
		( new ProductConfig( personalise: true, blank_id: 'blank_book', sides: array( $side ) ) )->save_to( $product );
		return $this->reload( $product );
	}

	private function reload( WC_Product $product ): WC_Product {
		wp_cache_delete( (string) $product->get_id(), 'post_meta' );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}
}
