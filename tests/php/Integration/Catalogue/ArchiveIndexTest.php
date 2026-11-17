<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ArchiveCards;
use Pixonaut\WooCommerce\Catalogue\ArchiveIndexBuild;
use Pixonaut\WooCommerce\Catalogue\ArchiveTerms;
use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ArchiveIndexClient;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Grouped;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Query;
use WP_UnitTestCase;

/**
 * An archive's index, read by the contract's rules (`ArchiveIndexClient`),
 * lists the cards, links, pictures and counts the server-rendered archive
 * lists for the same address, under each way of listing a poster.
 *
 * A poster sold bare, framed in oak or black, or with a black hanger, in
 * two sizes; the frame colour applies only to the framed and the hanger
 * variations. A print sold bare in 30x40 at 199 and framed in oak in 50x70
 * at 350, and a sea sold bare and with a hanger in oak or black.
 *
 * With `PIXONAUT_ARCHIVE_FIXTURES` naming a directory, each case writes the
 * index and what the server listed for each address there, for a theme's
 * tests.
 */
final class ArchiveIndexTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Inramning';

	private const SIZE = 'Storlek';

	private const COLOUR = 'Ramfärg';

	/**
	 * The addresses each case compares: filter arguments as the server reads them.
	 */
	private const ADDRESSES = array(
		array(),
		array( 'filter_ramfarg' => 'ek' ),
		array(
			'filter_inramning' => 'ingen',
			'filter_ramfarg'   => 'ek',
		),
		array(
			'filter_inramning' => 'hangare,ingen',
			'filter_ramfarg'   => 'ek',
		),
		array(
			'filter_inramning' => 'ingen,ram',
			'filter_ramfarg'   => 'ek',
		),
		array(
			'filter_inramning' => 'ingen,hangare',
			'filter_storlek'   => '50x70',
		),
		array(
			'filter_inramning' => 'ingen,hangare',
			'filter_storlek'   => '50x70',
			'max_price'        => '400',
		),
		array(
			'filter_storlek' => '30x40,50x70',
			'max_price'      => '300',
		),
		array(
			'filter_storlek' => '30x40',
			'min_price'      => '299',
			'max_price'      => '391',
		),
		array( 'filter_inramning' => 'ram' ),
		array( 'filter_inramning' => 'ingen,ram,hangare' ),
		array(
			'filter_inramning' => 'ingen,ram,hangare',
			'filter_storlek'   => '50x70',
		),
		array( 'min_price' => '450' ),
		array( 'filter_ramfarg' => 'ek,svart' ),
		array(
			'filter_inramning' => 'ram,hangare',
			'filter_ramfarg'   => 'ek,svart',
		),
	);

	public function set_up(): void {
		parent::set_up();
		$this->attribute( self::PICTURE, array( 'Ingen', 'Ram', 'Hängare' ) );
		$this->attribute( self::SIZE, array( '30x40', '50x70' ) );
		$this->attribute( self::COLOUR, array( 'Ek', 'Svart' ) );
		$category = wp_insert_term( 'Tavlor', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
		delete_transient( 'pixonaut_wc_attribute_dependencies' );
		$safari = $this->product(
			'Safari',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'ingen', '', '299' ),
				array( '30x40', 'ram', 'ek', '349' ),
				array( '30x40', 'ram', 'svart', '349' ),
				array( '50x70', 'ram', 'ek', '499' ),
				array( '50x70', 'hangare', 'svart', '450' ),
			)
		);
		$safari->set_default_attributes( array( 'pa_storlek' => '50x70' ) );
		$safari->save();
		$this->product(
			'Skog',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'ram', 'ek', '350' ),
			)
		);
		$this->product(
			'Hav',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'hangare', 'ek', '450' ),
				array( '50x70', 'hangare', 'svart', '450' ),
			)
		);
	}

	public function tear_down(): void {
		FreshRequest::forget_filter_reads();
		$this->tear_down_posters();
		update_option( Settings::OPTION, array() );
		delete_option( 'woocommerce_hide_out_of_stock_items' );
		$_GET = array();
		$this->forget_chosen_filters();
		parent::tear_down();
	}

	public function test_posters_listed_once(): void {
		$this->assert_index_lists_as_the_server( 'listed-once' );
	}

	public function test_posters_listed_by_framing(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$this->assert_index_lists_as_the_server( 'split-framing' );
	}

	public function test_posters_listed_by_framing_only_while_filtered(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::FILTERED );
		$this->assert_index_lists_as_the_server( 'split-framing-while-filtered' );
	}

	public function test_posters_listed_by_frame_colour(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::COLOUR ], SplitListing::ON );
		$this->assert_index_lists_as_the_server( 'split-colour' );
	}

	public function test_posters_listed_by_framing_and_by_colour_only_while_filtered(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		SplitListing::set_attribute_mode( $this->attributes[ self::COLOUR ], SplitListing::FILTERED );
		$this->assert_index_lists_as_the_server( 'split-framing-colour-while-filtered' );
	}

	public function test_posters_listed_by_framing_while_filtered_with_a_picture_of_each_variation(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::FILTERED );
		$this->picture_each_variation();
		$this->assert_index_lists_as_the_server( 'split-framing-while-filtered-pictures' );
	}

	public function test_posters_listed_by_framing_with_a_picture_of_each_variation(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$this->picture_each_variation();
		$this->assert_index_lists_as_the_server( 'split-framing-pictures' );
	}

	public function test_a_swatch_names_each_of_its_values_by_its_attribute(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::FILTERED );
		SplitListing::set_attribute_mode( $this->attributes[ self::COLOUR ], SplitListing::FILTERED );
		$swatches = array();
		$this->forget_chosen_filters();
		$args = array(
			'filter_inramning' => 'ram,hangare',
			'filter_ramfarg'   => 'ek,svart',
		);
		$_GET = $args;
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( add_query_arg( $args, $page ) );
		global $wp_query;
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$product = wc_get_product( (int) get_the_ID() );
			if ( $product instanceof WC_Product && 'Safari' === $product->get_name() ) {
				$swatches = apply_filters( 'pixonaut_wc_storefront_card_swatches', array(), $product );
				break;
			}
		}
		wp_reset_postdata();

		$this->assertIsArray( $swatches );
		$this->assertNotSame( array(), $swatches, 'the safari\'s other framings and colours' );
		$this->assertTrue( $swatches[0]['current'] );
		foreach ( $swatches as $swatch ) {
			$this->assertSame( array( 'inramning', 'ramfarg' ), array_column( $swatch['values'], 'key' ), 'a value of each attribute, by its own key' );
			$this->assertContains( $swatch['values'][0]['id'], array( 'ram', 'hangare' ) );
			$this->assertContains( $swatch['values'][1]['id'], array( 'ek', 'svart' ) );
		}
	}

	public function test_simple_grouped_and_sold_out_products_beside_the_posters(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$sold_out = new WC_Product_Simple();
		$sold_out->set_name( 'Slutsåld' );
		$sold_out->set_status( 'publish' );
		$sold_out->set_category_ids( array( $this->category ) );
		$sold_out->set_attributes( array( $this->plain_attribute( self::SIZE, '30x40' ) ) );
		$sold_out->set_regular_price( '100' );
		$sold_out->set_stock_status( 'outofstock' );
		$sold_out->save();
		$sale = new WC_Product_Simple();
		$sale->set_name( 'Rea' );
		$sale->set_status( 'publish' );
		$sale->set_category_ids( array( $this->category ) );
		$sale->set_attributes( array( $this->plain_attribute( self::SIZE, '50x70' ) ) );
		$sale->set_regular_price( '500' );
		$sale->set_sale_price( '420' );
		$sale->save();
		$group = new WC_Product_Grouped();
		$group->set_name( 'Paket' );
		$group->set_status( 'publish' );
		$group->set_category_ids( array( $this->category ) );
		$group->set_attributes( array( $this->plain_attribute( self::SIZE, '30x40' ) ) );
		$group->set_children( array( $this->child( '100' )->get_id(), $this->child( '500' )->get_id() ) );
		$group->save();

		$index = $this->assert_index_lists_as_the_server( 'simple-grouped-sold-out' );
		$rows  = array();
		foreach ( $index['cards'] as $card ) {
			$rows[ $card['title'] ] = $card['variations'];
		}
		$this->assertSame( 420.0, (float) $rows['Rea'][0]['price'], 'the sale price' );
		$this->assertSame( 500.0, (float) $rows['Rea'][0]['regularPrice'] );
		$this->assertSame( 500.0, (float) $rows['Paket'][0]['priceMax'], 'the group spans its children' );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$hidden = $this->assert_index_lists_as_the_server( 'simple-grouped-sold-out-hidden' );
		$this->assertNotContains( 'Slutsåld', array_column( $hidden['cards'], 'title' ) );
	}

	public function test_a_theme_adds_its_own_fields_to_a_cards_variations(): void {
		add_filter(
			'pixonaut_wc_archive_index_variation',
			static function ( array $row, WC_Product $variation, WC_Product $product ): array {
				$row['name'] = $product->get_name() . ' ' . $variation->get_id();
				return $row;
			},
			10,
			3
		);
		$index = $this->index();
		remove_all_filters( 'pixonaut_wc_archive_index_variation' );

		foreach ( $index['cards'] as $card ) {
			foreach ( $card['variations'] as $row ) {
				$this->assertStringStartsWith( $card['title'] . ' ', $row['name'] );
			}
		}
	}

	public function test_the_index_names_the_filters_their_values_and_the_dependent_attribute(): void {
		$index = $this->index();
		$this->assertSame( array( 'inramning', 'ramfarg', 'storlek', 'price' ), array_column( $index['filters'], 'key' ) );
		$colour = $index['filters'][1];
		$this->assertSame( array( 'ek', 'svart' ), array_column( $colour['values'], 'id' ) );
		$this->assertSame( 'Ramfärg', $colour['label'] );
		$this->assertSame(
			array(
				'key'    => 'inramning',
				'values' => array( 'hangare', 'ram' ),
			),
			array(
				'key'    => $colour['parent']['key'],
				'values' => array_values( array_intersect( array( 'hangare', 'ram' ), $colour['parent']['values'] ) ),
			),
			'the colour applies to the framed and the hangers'
		);
		$this->assertSame( get_woocommerce_currency(), $index['currency']['code'] );
		$this->assertSame( wc_get_price_decimals(), $index['currency']['decimals'] );
	}

	/**
	 * Compares the index with the server-rendered archive for every
	 * address, every value's count and every sort, and writes the fixture.
	 *
	 * @param string $name The case's name, for the fixtures.
	 * @return array<string, mixed> The index.
	 */
	private function assert_index_lists_as_the_server( string $name ): array {
		$index    = $this->index();
		$client   = new ArchiveIndexClient( $index );
		$expected = array();
		foreach ( self::ADDRESSES as $address ) {
			list( $chosen, $min, $max ) = $this->chosen_in( $address );
			$server                     = $this->shop( $address );
			$shown                      = $client->shown( $chosen, $min, $max );
			$label                      = $name . ' ' . ( (string) wp_json_encode( $address ) );
			$this->assertSame( array_map( array( $this, 'normal' ), array_column( $server, 'link' ) ), array_map( array( $this, 'normal' ), array_column( $shown, 'link' ) ), "{$label}: the cards and their links" );
			$this->assertSame( array_column( $server, 'image' ), array_column( $shown, 'image' ), "{$label}: the cards' pictures" );
			$this->assertSame(
				array_column( $server, 'swatches' ),
				array_map(
					fn ( array $card ): array => array_map(
						fn ( array $swatch ): array => array(
							'link'  => $this->normal( $swatch['link'] ),
							'image' => $swatch['image'],
						),
						$card['swatches']
					),
					$shown
				),
				"{$label}: the cards' swatches"
			);
			$counts = $client->counts( $chosen, $min, $max );
			foreach ( $counts as $key => $by_value ) {
				foreach ( $by_value as $value => $count ) {
					$with            = $address;
					$filter          = 'filter_' . $key;
					$with[ $filter ] = isset( $with[ $filter ] ) ? $with[ $filter ] . ',' . $value : $value;
					$this->assertSame( count( $this->shop( $with ) ), $count, "{$label}: the count of {$key} {$value}" );
				}
			}
			$expected[] = array(
				'query'    => (object) $this->short_query( $address ),
				'cards'    => array_column( $shown, 'id' ),
				'links'    => array_column( $shown, 'link' ),
				'images'   => array_column( $shown, 'image' ),
				'swatches' => array_column( $shown, 'swatches' ),
				'counts'   => $counts,
			);
		}
		foreach ( ArchiveIndexBuild::SORTS as $sort ) {
			$server = $this->shop( array( 'orderby' => $sort ) );
			$shown  = $client->shown( array(), null, null, $sort );
			$this->assertSame( array_map( array( $this, 'normal' ), array_column( $server, 'link' ) ), array_map( array( $this, 'normal' ), array_column( $shown, 'link' ) ), "{$name}: sorted by {$sort}" );
			$expected[] = array(
				'query'  => (object) array( 'orderby' => $sort ),
				'cards'  => array_column( $shown, 'id' ),
				'links'  => array_column( $shown, 'link' ),
				'images' => array_column( $shown, 'image' ),
			);
		}
		$this->write_fixture( $name, $index, $expected );
		return $index;
	}

	/**
	 * The category's index, as a theme decodes it.
	 *
	 * @return array<string, mixed>
	 */
	private function index(): array {
		FreshRequest::forget_filter_reads();
		$terms = new ArchiveTerms();
		$built = ( new ArchiveIndexBuild( new ArchiveCards( $terms ), $terms ) )->build( 'product_cat-' . $this->category, ( new NotLiveProducts() )->hidden_ids() );
		$this->assertNotNull( $built );
		$index = json_decode( (string) wp_json_encode( array( 'version' => 'test' ) + $built['index'] ), true );
		$this->assertIsArray( $index );
		return $index;
	}

	/**
	 * Writes a case's index and what the server listed, when asked to.
	 *
	 * @param string                     $name     The case.
	 * @param array<string, mixed>       $index    Its index.
	 * @param list<array<string, mixed>> $expected What the server listed per address.
	 */
	private function write_fixture( string $name, array $index, array $expected ): void {
		$directory = getenv( 'PIXONAUT_ARCHIVE_FIXTURES' );
		if ( ! is_string( $directory ) || '' === $directory ) {
			return;
		}
		wp_mkdir_p( $directory );
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		file_put_contents( "{$directory}/{$name}.index.json", wp_json_encode( $index, $flags ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixtures for a theme's tests.
		file_put_contents( "{$directory}/{$name}.expected.json", wp_json_encode( $expected, $flags ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixtures for a theme's tests.
	}

	/**
	 * An address's choice as the index names it.
	 *
	 * @param array<string, string> $address Filter arguments.
	 * @return array{0: array<string, list<string>>, 1: float|null, 2: float|null}
	 */
	private function chosen_in( array $address ): array {
		$chosen = array();
		foreach ( $address as $name => $value ) {
			if ( str_starts_with( $name, 'filter_' ) ) {
				$chosen[ substr( $name, strlen( 'filter_' ) ) ] = explode( ',', $value );
			}
		}
		return array(
			$chosen,
			isset( $address['min_price'] ) ? (float) $address['min_price'] : null,
			isset( $address['max_price'] ) ? (float) $address['max_price'] : null,
		);
	}

	/**
	 * An address's arguments as the shop's addresses write them.
	 *
	 * @param array<string, string> $address Filter arguments.
	 * @return array<string, string>
	 */
	private function short_query( array $address ): array {
		$short = array();
		foreach ( $address as $name => $value ) {
			$short[ str_starts_with( $name, 'filter_' ) ? substr( $name, strlen( 'filter_' ) ) : $name ] = $value;
		}
		return $short;
	}

	/**
	 * A link with its arguments in one order.
	 *
	 * @param string $link A card's link.
	 */
	private function normal( string $link ): string {
		$parts = explode( '?', $link, 2 );
		parse_str( $parts[1] ?? '', $query );
		ksort( $query );
		return $parts[0] . '?' . http_build_query( $query );
	}

	/**
	 * The cards the category's page lists at an address, with their links and pictures.
	 *
	 * @param array<string, string> $args Query parameters.
	 * @return list<array<string, mixed>>
	 */
	private function shop( array $args ): array {
		$this->forget_chosen_filters();
		FreshRequest::forget_filter_reads();
		$_GET = $args;
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( add_query_arg( $args, $page ) );
		global $wp_query;
		$cards = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$product = wc_get_product( (int) get_the_ID() );
			$this->assertInstanceOf( WC_Product::class, $product );
			$swatches = apply_filters( 'pixonaut_wc_storefront_card_swatches', array(), $product );
			$this->assertIsArray( $swatches );
			if ( array() !== $swatches ) {
				$this->assertTrue( $swatches[0]['current'], 'the card itself first' );
				$this->assertSame( $this->normal( (string) get_permalink() ), $this->normal( $swatches[0]['url'] ) );
				$this->assertCount( 1, array_filter( array_column( $swatches, 'current' ) ) );
			}
			$cards[] = array(
				'link'     => (string) get_permalink(),
				'image'    => ArchiveCards::image( (int) $product->get_image_id() )['src'],
				'swatches' => array_map(
					fn ( array $swatch ): array => array(
						'link'  => $this->normal( $swatch['url'] ),
						'image' => $swatch['image']['src'],
					),
					array_values( array_filter( $swatches, static fn ( array $swatch ): bool => ! $swatch['current'] ) )
				),
			);
		}
		wp_reset_postdata();
		$this->assertSame( count( $cards ), (int) $wp_query->found_posts, 'one page lists every card' );
		return $cards;
	}

	/**
	 * A variable product in the category with the three attributes.
	 *
	 * @param string                                                  $name       Its name.
	 * @param list<array{0: string, 1: string, 2: string, 3: string}> $variations Size, framing, colour and price of each.
	 */
	private function product( string $name, array $variations ): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_attributes( array( $this->product_attribute( self::SIZE ), $this->product_attribute( self::PICTURE ), $this->product_attribute( self::COLOUR ) ) );
		$product->save();
		foreach ( $variations as list( $size, $framing, $colour, $price ) ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes(
				array(
					'pa_storlek'   => $size,
					'pa_inramning' => $framing,
					'pa_ramfarg'   => $colour,
				)
			);
			$variation->set_regular_price( $price );
			$variation->save();
		}
		WC_Product_Variable::sync( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	/**
	 * Gives each framed or hanger variation of every poster an image of its own.
	 */
	private function picture_each_variation(): void {
		$products = wc_get_products(
			array(
				'type'  => 'variable',
				'limit' => -1,
			)
		);
		$this->assertIsArray( $products );
		foreach ( $products as $product ) {
			$this->assertInstanceOf( WC_Product::class, $product );
			foreach ( $product->get_children() as $id ) {
				$variation = wc_get_product( $id );
				$this->assertInstanceOf( WC_Product_Variation::class, $variation );
				if ( 'ingen' === $variation->get_attribute( 'pa_inramning' ) ) {
					continue;
				}
				$image = self::factory()->attachment->create_object(
					array(
						'file'           => "variation-{$id}.png",
						'post_mime_type' => 'image/png',
					)
				);
				$this->assertIsInt( $image );
				$variation->set_image_id( $image );
				$variation->save();
			}
		}
	}

	/**
	 * A product attribute holding one value, not used for variations.
	 *
	 * @param string $name  The attribute's name.
	 * @param string $value The value's slug.
	 */
	private function plain_attribute( string $name, string $value ): WC_Product_Attribute {
		$attribute = $this->product_attribute( $name );
		$attribute->set_options( array( $this->term( $value ) ) );
		$attribute->set_variation( false );
		return $attribute;
	}

	/**
	 * A simple product outside the category, for a group.
	 *
	 * @param string $price Its price.
	 */
	private function child( string $price ): WC_Product {
		$child = new WC_Product_Simple();
		$child->set_name( 'Del ' . $price );
		$child->set_status( 'publish' );
		$child->set_regular_price( $price );
		$child->save();
		return $child;
	}

	/**
	 * WooCommerce reads the chosen filters once a request.
	 */
	private function forget_chosen_filters(): void {
		( new \ReflectionProperty( WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
	}
}
