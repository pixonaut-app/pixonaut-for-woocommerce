<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\FormGroupsMount;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;

/**
 * A side's Groups setting: the template's groups read for it, the
 * arrangement saved with the product, and the mount option it becomes.
 */
final class ProductFormGroupsTest extends ProductPanelTestCase {

	private const ARRANGEMENT = array(
		'groups' => array(
			array(
				'id'    => 'text-front',
				'title' => 'Your words',
				'items' => array(
					array( 'attribute' => 'size' ),
					array(
						'field' => 'slogan:content',
						'label' => 'Name on the back',
					),
				),
			),
		),
	);

	public function test_the_template_groups_route_lists_the_templates_groups_and_keeps_them(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->script_template();
		$template = json_decode( (string) OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 ), true );
		$steps    = is_array( $template ) ? $template['formSchema']['steps'] : array();

		$response = $this->get( array( 'id' => self::TEMPLATE_ID ) );
		$again    = $this->get( array( 'id' => self::TEMPLATE_ID ) );

		$this->assertSame( 200, $response->get_status() );
		$groups = $response->get_data()['groups'];
		$this->assertSame( array_column( $steps, 'groupId' ), array_column( $groups, 'id' ) );
		foreach ( $groups as $index => $group ) {
			$this->assertSame(
				array_map( static fn ( array $field ): string => $field['layerId'] . ':' . $field['prop'], $steps[ $index ]['fields'] ),
				array_column( $group['fields'], 'field' )
			);
		}
		$this->assertSame( $response->get_data(), $again->get_data() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_template_groups_route_is_for_product_editors_and_a_502_without_pixonaut(): void {
		$this->assertSame( 401, $this->get( array( 'id' => self::TEMPLATE_ID ) )->get_status() );
		wp_set_current_user( $this->user( 'customer' ) );
		$this->assertSame( 403, $this->get( array( 'id' => self::TEMPLATE_ID ) )->get_status() );

		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$this->assertSame( 502, $this->get( array( 'id' => self::TEMPLATE_ID ) )->get_status() );
	}

	public function test_saves_a_sides_arrangement_and_shows_it_again(): void {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );

		$this->save( $product->get_id(), array(), array( ProductPanel::FORM_GROUPS_FIELD => wp_slash( (string) wp_json_encode( self::ARRANGEMENT ) ) ) );

		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertNotNull( $side->form_groups );
		$this->assertSame( self::ARRANGEMENT['groups'], $side->form_groups->groups );
		// The panel reads the blank, its views and its printshop to show them.
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$html = $this->panel->fields_html( $this->panel->panel( $this->reload( $product->get_id() ) ) );
		$this->assertStringContainsString( 'value="' . esc_attr( (string) wp_json_encode( self::ARRANGEMENT ) ) . '"', $html );
	}

	public function test_keeps_the_slug_of_an_attribute_named_in_another_script(): void {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );
		$slug        = sanitize_title( '尺寸' );
		$arrangement = array(
			'groups' => array(
				array(
					'id'    => 'text-front',
					'title' => '<b>Your</b> words',
					'items' => array( array( 'attribute' => $slug ) ),
				),
			),
		);

		$this->save( $product->get_id(), array(), array( ProductPanel::FORM_GROUPS_FIELD => wp_slash( (string) wp_json_encode( $arrangement ) ) ) );

		$groups = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->form_groups;
		$this->assertNotNull( $groups );
		$this->assertStringContainsString( '%', $slug );
		$this->assertSame( array( $slug ), $groups->attributes() );
		$this->assertSame( 'Your words', $groups->groups[0]['title'] ?? null );
	}

	public function test_a_side_never_arranged_saves_none(): void {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );

		$this->save( $product->get_id(), array(), array( ProductPanel::FORM_GROUPS_FIELD => '' ) );

		$this->assertNull( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->form_groups );
	}

	public function test_the_product_lists_its_attributes_used_for_variations_and_a_variation_has_no_setting(): void {
		$product   = $this->variable( array( 'size', 'gift' ) );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->save();
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $product );
		$this->assertSame(
			array(
				array(
					'slug'  => 'size',
					'label' => 'size',
				),
			),
			$panel['groups']['attributes']
		);
		$this->assertStringContainsString( '/' . Plugin::REST_NAMESPACE . CatalogueSearch::TEMPLATE_GROUPS_ROUTE, $panel['groups']['url'] );
		$this->assertNull( $this->panel->panel( $variation )['groups'] );
		$this->assertStringNotContainsString( 'pixonaut-groups', $this->panel->fields_html( $this->panel->panel( $variation ) ) );
	}

	public function test_the_mount_option_puts_the_options_not_placed_first(): void {
		$product = $this->variable( array( 'size', 'colour' ) );
		$side    = $this->arranged(
			array(
				'groups' => array(
					array(
						'id'    => 'text-front',
						'items' => array( array( 'attribute' => 'size' ), array( 'attribute' => 'material' ) ),
					),
					array(
						'id'    => FormGroups::OWN_GROUP_PREFIX . 'gone',
						'title' => 'Material',
						'items' => array( array( 'attribute' => 'material' ) ),
					),
				),
			)
		);

		$option = FormGroupsMount::option( $side, $product );

		$this->assertNotNull( $option );
		$this->assertSame( array( FormGroupsMount::OPTIONS_GROUP_ID, 'text-front' ), array_column( $option['groups'], 'id' ) );
		$this->assertSame( array( array( 'attribute' => 'colour' ) ), $option['groups'][0]['items'] );
		$this->assertNotSame( '', $option['groups'][0]['title'] );
		// An attribute the product no longer varies by is left out, with the group of its own it leaves empty.
		$this->assertSame( array( array( 'attribute' => 'size' ) ), $option['groups'][1]['items'] );
		$this->assertNull( FormGroupsMount::option( $this->arranged( null ), $product ) );
	}

	public function test_each_option_gets_a_group_named_after_it_in_the_products_order_first(): void {
		$product = $this->variable( array( 'storlek', 'farg' ) );

		$option = FormGroupsMount::option( $this->arranged( null ), $product, OptionGroups::FIRST );

		$this->assertNotNull( $option );
		$this->assertSame(
			array(
				array(
					'id'    => FormGroupsMount::OPTION_GROUP_PREFIX . 'storlek',
					'title' => 'storlek',
					'items' => array( array( 'attribute' => 'storlek' ) ),
				),
				array(
					'id'    => FormGroupsMount::OPTION_GROUP_PREFIX . 'farg',
					'title' => 'farg',
					'items' => array( array( 'attribute' => 'farg' ) ),
				),
			),
			$option['groups']
		);
		$fitted = FormGroupsMount::fitted( $option, array( 'picture', 'text' ) );
		$this->assertNotNull( $fitted );
		$this->assertSame( $option['groups'], $fitted['groups'], 'the designer shows the template\'s after the groups named' );
		$this->assertSame( $option, FormGroupsMount::fitted( $option, null ), 'they name nothing of the template\'s, so need no read' );
		$this->assertFalse( FormGroupsMount::awaits_template_groups( $option ) );
	}

	public function test_the_option_groups_follow_the_templates_groups_as_the_page_read_them_when_last(): void {
		$product = $this->variable( array( 'storlek', 'farg' ) );

		$last = FormGroupsMount::option( $this->arranged( null ), $product, OptionGroups::LAST );
		$this->assertTrue( FormGroupsMount::awaits_template_groups( $last ) );

		$fitted = FormGroupsMount::fitted( $last, array( 'picture', 'text' ) );

		$this->assertNotNull( $fitted );
		$this->assertSame(
			array( 'picture', 'text', FormGroupsMount::OPTION_GROUP_PREFIX . 'storlek', FormGroupsMount::OPTION_GROUP_PREFIX . 'farg' ),
			array_column( $fitted['groups'], 'id' )
		);
		$this->assertSame( array(), $fitted['groups'][0]['items'], 'a template group keeps its own fields' );
		$this->assertArrayNotHasKey( 'title', $fitted['groups'][0], 'and its own name' );
		$this->assertNull(
			FormGroupsMount::fitted( FormGroupsMount::option( $this->arranged( null ), $product, OptionGroups::LAST ), null ),
			'without a read, the template’s own form'
		);
	}

	public function test_options_stay_on_top_without_groups_of_their_own_and_a_product_without_options_has_none(): void {
		$this->assertNull( FormGroupsMount::option( $this->arranged( null ), $this->variable( array( 'storlek' ) ), OptionGroups::ON_TOP ) );
		$this->assertNull( FormGroupsMount::option( $this->arranged( null ), $this->product(), OptionGroups::LAST ) );
	}

	public function test_a_side_arranged_by_hand_keeps_its_groups_whatever_the_options_setting_says(): void {
		$product = $this->variable( array( 'size' ) );
		$side    = $this->arranged( self::ARRANGEMENT );

		$this->assertSame(
			FormGroupsMount::option( $side, $product, OptionGroups::ON_TOP ),
			FormGroupsMount::option( $side, $product, OptionGroups::LAST )
		);
	}

	public function test_changing_the_store_setting_changes_a_product_following_it_and_not_one_that_chose(): void {
		$settings  = new Settings();
		$following = new ProductConfig();
		$chosen    = $following->with_option_groups( OptionGroups::ON_TOP )->with_group_numbers( GroupNumbers::ON );

		$this->assertSame( OptionGroups::ON_TOP, OptionGroups::resolve( $following, $settings ), 'off by default' );
		$this->assertTrue( GroupNumbers::shown( $following, $settings ), 'on by default' );

		$settings->set_many(
			array(
				Settings::OPTIONS_AS_GROUPS => true,
				Settings::GROUP_NUMBERS     => false,
			)
		);

		$this->assertSame( OptionGroups::FIRST, OptionGroups::resolve( $following, $settings ), 'First by default' );
		$this->assertFalse( GroupNumbers::shown( $following, $settings ) );
		$this->assertSame( OptionGroups::ON_TOP, OptionGroups::resolve( $chosen, $settings ) );
		$this->assertTrue( GroupNumbers::shown( $chosen, $settings ) );

		$settings->set_many( array( Settings::OPTION_GROUPS_POSITION => OptionGroups::LAST ) );
		$this->assertSame( OptionGroups::LAST, OptionGroups::resolve( $following, $settings ) );
	}

	public function test_a_variation_has_no_choices_of_its_own_on_the_product_screen(): void {
		$product   = $this->variable( array( 'size' ) );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->save();
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $product );
		$this->assertNotSame( array(), $panel['option_choices'] );
		$this->assertNotSame( array(), $panel['number_choices'] );
		$this->assertStringContainsString( PanelFields::OPTION_GROUPS, $this->panel->fields_html( $panel ) );

		$own = $this->panel->panel( $variation );
		$this->assertSame( array(), $own['option_choices'] );
		$this->assertSame( array(), $own['number_choices'] );
		$this->assertStringNotContainsString( PanelFields::OPTION_GROUPS, $this->panel->fields_html( $own ) );
	}

	public function test_the_page_moves_options_out_of_a_group_the_template_no_longer_has(): void {
		$fitted = FormGroupsMount::fitted(
			array(
				'groups' => array(
					array(
						'id'    => FormGroupsMount::OPTIONS_GROUP_ID,
						'title' => 'Options',
						'items' => array( array( 'attribute' => 'colour' ) ),
					),
					array(
						'id'    => 'dropped',
						'items' => array( array( 'attribute' => 'size' ), array( 'field' => 'a:b' ) ),
					),
					array(
						'id'    => 'renamed-but-dropped',
						'title' => 'Still shown',
						'items' => array( array( 'attribute' => 'material' ) ),
					),
					array(
						'id'    => 'text-front',
						'items' => array( array( 'field' => 'slogan:content' ) ),
					),
				),
			),
			array( 'text-front' )
		);

		$this->assertNotNull( $fitted );
		$this->assertSame( array( FormGroupsMount::OPTIONS_GROUP_ID, 'renamed-but-dropped', 'text-front' ), array_column( $fitted['groups'], 'id' ) );
		$this->assertSame( array( array( 'attribute' => 'colour' ), array( 'attribute' => 'size' ) ), $fitted['groups'][0]['items'] );
		$this->assertNull( FormGroupsMount::fitted( $fitted, null ), 'without a read, the template’s own form' );
	}

	/**
	 * A variable product varying by each attribute in `$varying`, and by a
	 * `gift` attribute that is not used for variations.
	 *
	 * @param string[] $varying Attribute names.
	 * @phpstan-param list<string> $varying
	 */
	private function variable( array $varying ): WC_Product_Variable {
		$attributes = array();
		foreach ( array_unique( array_merge( $varying, array( 'gift' ) ) ) as $name ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( 'A', 'B' ) );
			$attribute->set_visible( true );
			$attribute->set_variation( 'gift' !== $name );
			$attributes[] = $attribute;
		}
		$product = new WC_Product_Variable();
		$product->set_name( 'Personalised hoodie' );
		$product->set_attributes( $attributes );
		$product->save();
		return $product;
	}

	/**
	 * The front, arranged as `$groups` says.
	 *
	 * @param array<string, mixed>|null $groups The arrangement; null for none.
	 */
	private function arranged( ?array $groups ): Side {
		return new Side( 'front', 'Front', self::PLACEMENT_ID, 'Front', 'mini-tee', 2, null, 'Mini tee', form_groups: FormGroups::from_array( $groups ) );
	}

	/**
	 * @param array<string, string> $params Query parameters.
	 */
	private function get( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . CatalogueSearch::TEMPLATE_GROUPS_ROUTE );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}
}
