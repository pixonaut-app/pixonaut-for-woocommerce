<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Plugin;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * The abilities that read and change the settings the active theme declares
 * on `pixonaut_wc_theme_settings`, as the Abilities API (and so the MCP
 * Adapter) runs them. The settings here are a stand-in theme's.
 */
final class ThemeSettingsAbilitiesTest extends WP_UnitTestCase {

	public const READ = Abilities::NAMESPACE . '/get-theme-settings';

	public const UPDATE = Abilities::NAMESPACE . '/update-theme-settings';

	private const LAYOUT = 'example_theme_layout';

	private const BANNER = 'example_theme_banner';

	private const NOTE = 'example_theme_note';

	private const LAYOUT_DEFAULT = 'wide';

	private const SALE_BANNER = array(
		'text' => 'Sale',
		'link' => 'https://example.org/sale',
	);

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		add_filter( 'pixonaut_wc_theme_settings', array( self::class, 'declare_settings' ) );
		wp_set_current_user( self::user( 'administrator' ) );
	}

	public function tear_down(): void {
		foreach ( array( self::LAYOUT, self::BANNER, self::NOTE ) as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	/**
	 * A stand-in theme's settings, among entries the plugin leaves out.
	 *
	 * @param array<mixed> $settings The settings declared so far.
	 * @return array<mixed>
	 */
	public static function declare_settings( array $settings ): array {
		$settings[ self::LAYOUT ]         = array(
			'label'       => 'Layout',
			'description' => 'How wide the pages are: wide or narrow.',
			'schema'      => array(
				'type' => 'string',
				'enum' => array( 'wide', 'narrow' ),
			),
			'default'     => self::LAYOUT_DEFAULT,
		);
		$settings[ self::BANNER ]         = array(
			'label'       => 'Campaign banner',
			'description' => 'The band above the header, or none.',
			'schema'      => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(
					'text' => array( 'type' => 'string' ),
					'link' => array( 'type' => 'string' ),
				),
				'required'             => array( 'text', 'link' ),
				'additionalProperties' => false,
			),
			'default'     => null,
		);
		$settings[ self::NOTE ]           = array(
			'label'       => 'Note',
			'description' => 'A line under the footer.',
			'schema'      => array( 'type' => 'string' ),
		);
		$settings['example_unlabelled']   = array(
			'description' => 'No label.',
			'schema'      => array( 'type' => 'string' ),
		);
		$settings['example_bad_schema']   = array(
			'label'       => 'Bad schema',
			'description' => 'Its schema is not an array.',
			'schema'      => 'string',
		);
		$settings['example_no_describe']  = array(
			'label'       => 'No description',
			'description' => 7,
			'schema'      => array( 'type' => 'string' ),
		);
		$settings[]                       = array(
			'label'       => 'Numbered',
			'description' => 'Its key is not a string.',
			'schema'      => array( 'type' => 'string' ),
		);
		$settings['example_not_an_entry'] = 'wide';
		return $settings;
	}

	public function test_the_active_theme_and_its_well_formed_settings_are_read_with_their_defaults(): void {
		$read  = $this->succeed( self::READ, array() );
		$theme = wp_get_theme();

		$this->assertSame(
			array(
				'stylesheet' => $theme->get_stylesheet(),
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
			),
			$read['theme']
		);
		$this->assertSame( array( self::LAYOUT, self::BANNER, self::NOTE ), array_column( $read['settings'], 'key' ) );
		$layout = $read['settings'][0];
		$this->assertSame( 'Layout', $layout['label'] );
		$this->assertSame( 'How wide the pages are: wide or narrow.', $layout['description'] );
		$this->assertSame( array( 'wide', 'narrow' ), $layout['schema']['enum'] );
		$this->assertSame( self::LAYOUT_DEFAULT, $layout['default'] );
		$this->assertSame( self::LAYOUT_DEFAULT, $layout['value'] );
		$this->assertNull( $read['settings'][1]['value'] );
		// An entry without a default has null.
		$this->assertArrayHasKey( 'default', $read['settings'][2] );
		$this->assertNull( $read['settings'][2]['default'] );
		$this->assertNull( $read['settings'][2]['value'] );
	}

	public function test_a_stored_value_that_fits_is_read_and_one_that_does_not_reads_as_the_default(): void {
		update_option( self::BANNER, self::SALE_BANNER );
		update_option( self::LAYOUT, 'sideways' );

		$values = $this->values( $this->succeed( self::READ, array() ) );

		$this->assertSame( self::SALE_BANNER, $values[ self::BANNER ] );
		$this->assertSame( self::LAYOUT_DEFAULT, $values[ self::LAYOUT ] );
	}

	public function test_a_theme_that_declares_nothing_has_no_settings(): void {
		remove_filter( 'pixonaut_wc_theme_settings', array( self::class, 'declare_settings' ) );

		$this->assertSame( array(), $this->succeed( self::READ, array() )['settings'] );
	}

	public function test_the_settings_given_change_and_the_others_keep_their_values(): void {
		update_option( self::NOTE, 'Free delivery' );

		$answer = $this->succeed(
			self::UPDATE,
			array(
				'settings' => array(
					self::LAYOUT => 'narrow',
					self::BANNER => self::SALE_BANNER,
				),
			)
		);

		$this->assertSame( 'narrow', get_option( self::LAYOUT ) );
		$this->assertSame( self::SALE_BANNER, get_option( self::BANNER ) );
		$this->assertSame( 'Free delivery', get_option( self::NOTE ) );
		$this->assertSame(
			array(
				self::LAYOUT => 'narrow',
				self::BANNER => self::SALE_BANNER,
				self::NOTE   => 'Free delivery',
			),
			$this->values( $answer )
		);
		$this->assertSame( wp_get_theme()->get_stylesheet(), $answer['theme']['stylesheet'] );
	}

	public function test_null_deletes_the_option_so_the_theme_shows_its_default(): void {
		update_option( self::LAYOUT, 'narrow' );
		update_option( self::BANNER, self::SALE_BANNER );

		$answer = $this->succeed(
			self::UPDATE,
			array(
				'settings' => array(
					self::LAYOUT => null,
					self::BANNER => null,
				),
			)
		);

		$this->assertFalse( get_option( self::LAYOUT ) );
		$this->assertFalse( get_option( self::BANNER ) );
		$this->assertSame( self::LAYOUT_DEFAULT, $this->values( $answer )[ self::LAYOUT ] );
		$this->assertNull( $this->values( $answer )[ self::BANNER ] );
	}

	public function test_an_unknown_key_or_a_value_that_does_not_fit_changes_nothing(): void {
		$refusals = array(
			'pixonaut_wc_ability_theme_setting_unknown' => array( 'blogname' => 'Shop' ),
			'pixonaut_wc_ability_theme_setting_invalid' => array( self::BANNER => array( 'text' => 'Sale' ) ),
		);
		foreach ( $refusals as $code => $refused ) {
			$answer = $this->ability( self::UPDATE )->execute( array( 'settings' => array( self::LAYOUT => 'narrow' ) + $refused ) );

			$this->assertInstanceOf( WP_Error::class, $answer, $code );
			$this->assertSame( $code, $answer->get_error_code() );
			$this->assertSame( array(), (array) $answer->get_error_data(), 'No status: the shop tool route answers it as input that does not fit.' );
		}
		$invalid = $this->ability( self::UPDATE )->execute( array( 'settings' => array( self::LAYOUT => 'sideways' ) ) );
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'pixonaut_wc_ability_theme_setting_invalid', $invalid->get_error_code() );
		$this->assertFalse( get_option( self::LAYOUT ) );
		$this->assertNotSame( 'Shop', get_option( 'blogname' ) );
	}

	public function test_no_settings_to_change_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, $this->ability( self::UPDATE )->execute( array( 'settings' => array() ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( self::UPDATE )->execute( array() ) );
	}

	public function test_a_user_who_may_not_edit_the_theme_options_may_neither_read_nor_change_them(): void {
		foreach ( array( 'customer', 'author', 'editor' ) as $role ) {
			wp_set_current_user( self::user( $role ) );
			foreach ( array(
				self::READ   => array(),
				self::UPDATE => array( 'settings' => array( self::LAYOUT => 'narrow' ) ),
			) as $name => $input ) {
				$answer = $this->ability( $name )->execute( $input );
				$this->assertInstanceOf( WP_Error::class, $answer, $name . ' as ' . $role );
				$this->assertSame( 'ability_invalid_permissions', $answer->get_error_code(), $name . ' as ' . $role );
			}
		}
		$this->assertFalse( get_option( self::LAYOUT ) );
	}

	public function test_the_abilities_are_registered_with_their_schemas_and_marked_for_mcp(): void {
		foreach ( array( self::READ, self::UPDATE ) as $name ) {
			$ability = $this->ability( $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category(), $name );
			$this->assertSame( 'object', $ability->get_input_schema()['type'] ?? null, $name );
			$this->assertSame( 'object', $ability->get_output_schema()['type'] ?? null, $name );
			$this->assertSame( array( 'public' => true ), $ability->get_meta_item( 'mcp' ), $name );
			$this->assertSame( self::READ === $name, $ability->get_meta_item( 'annotations' )['readonly'], $name );
		}
		$this->assertSame( 1, $this->ability( self::UPDATE )->get_input_schema()['properties']['settings']['minProperties'] );
	}

	/**
	 * Each setting's value in an answer, by its key.
	 *
	 * @param array<string, mixed> $answer What the ability answered.
	 * @return array<string, mixed>
	 */
	private function values( array $answer ): array {
		return array_column( $answer['settings'], 'value', 'key' );
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	/**
	 * Runs an ability that is to succeed.
	 *
	 * @param string       $name  The ability.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $name, array $input ): array {
		$answer = $this->ability( $name )->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : $name );
		return $answer;
	}

	private static function user( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		self::assertIsInt( $user );
		return $user;
	}
}
