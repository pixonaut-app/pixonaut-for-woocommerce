<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\QueryNameField;
use Pixonaut\WooCommerce\Storefront\QueryNames;
use Pixonaut\WooCommerce\Templates;
use WP_UnitTestCase;

final class QueryNameFieldTest extends WP_UnitTestCase {

	private QueryNameField $field;

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
		$this->field = new QueryNameField( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ) );
		$this->field->register();
		$this->attributes['Storlek'] = $this->attribute( 'Storlek' );
	}

	public function tear_down(): void {
		remove_action( 'woocommerce_attribute_added', array( $this->field, 'save_attribute' ) );
		remove_action( 'woocommerce_attribute_updated', array( $this->field, 'save_attribute' ) );
		foreach ( $this->attributes as $id ) {
			wc_delete_attribute( $id );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( QueryNames::OPTION );
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	public function test_the_attribute_screens_set_its_name_in_addresses(): void {
		$_POST                   = $this->nonced( 'ram' );
		$this->attributes['Ram'] = $this->attribute( 'Inramning' );
		$this->assertSame( 'ram', QueryNames::of( $this->attributes['Ram'] ), 'added with its name' );

		$_POST = $this->nonced( ' Storlek ' );
		wc_update_attribute( $this->attributes['Storlek'], array( 'name' => 'Storlek' ) );
		$this->assertSame( '', QueryNames::own( $this->attributes['Storlek'] ), 'its own slug, as typed, is no name of its own' );

		$_POST = array( QueryNameField::FIELD => 'size' );
		wc_update_attribute( $this->attributes['Storlek'], array( 'name' => 'Storlek' ) );
		$this->assertSame( 'storlek', QueryNames::of( $this->attributes['Storlek'] ), 'an update not from the screen leaves it' );
	}

	public function test_the_screen_refuses_a_name_in_use_and_says_why(): void {
		$_POST = $this->nonced( 'orderby' );
		wc_update_attribute( $this->attributes['Storlek'], array( 'name' => 'Storlek' ) );

		$this->assertSame( 'storlek', QueryNames::of( $this->attributes['Storlek'] ), 'the attribute keeps its name' );
		$_GET['edit'] = (string) $this->attributes['Storlek'];
		ob_start();
		$this->field->render_edit();
		$edited = (string) ob_get_clean();
		$this->assertStringContainsString( '<tr class="form-field pixonaut-query-name">', $edited );
		$this->assertStringContainsString( 'value="orderby"', $edited, 'the refused name stays in the field to change' );
		$this->assertStringContainsString( 'placeholder="storlek"', $edited );
		$this->assertStringContainsString( 'pixonaut-query-name-refused', $edited );
		$this->assertStringContainsString( esc_html( (string) QueryNames::refusal( 'orderby', $this->attributes['Storlek'] ) ), $edited );

		$_POST                      = $this->nonced( 'storlek' );
		$this->attributes['Format'] = $this->attribute( 'Format' );
		$this->assertSame( 'format', QueryNames::of( $this->attributes['Format'] ), 'another attribute\'s name: added without it' );
		ob_start();
		$this->field->render_add();
		$added = (string) ob_get_clean();
		$this->assertStringContainsString( 'Storlek', $added, 'naming the attribute that has it' );
	}

	public function test_the_screens_show_the_name_as_set(): void {
		ob_start();
		$this->field->render_add();
		$added = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . QueryNameField::FIELD . '"', $added );
		$this->assertStringContainsString( 'name="' . QueryNameField::NONCE_FIELD . '"', $added );
		$this->assertStringNotContainsString( 'pixonaut-query-name-refused', $added );

		QueryNames::set( $this->attributes['Storlek'], 'size' );
		$_GET['edit'] = (string) $this->attributes['Storlek'];
		ob_start();
		$this->field->render_edit();
		$edited = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="size"', $edited );
		$this->assertStringContainsString( 'placeholder="storlek"', $edited );
	}

	/**
	 * The screen's post, naming the attribute.
	 *
	 * @param string $name The name typed.
	 * @return array<string, string>
	 */
	private function nonced( string $name ): array {
		return array(
			QueryNameField::FIELD       => $name,
			QueryNameField::NONCE_FIELD => wp_create_nonce( QueryNameField::NONCE_ACTION ),
		);
	}

	private function attribute( string $name ): int {
		$id = wc_create_attribute( array( 'name' => $name ) );
		$this->assertIsInt( $id );
		register_taxonomy( wc_attribute_taxonomy_name( $name ), array( 'product' ) );
		return $id;
	}
}
