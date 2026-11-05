<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;

final class DesignerLanguageTest extends TestCase {

	use FakeOptions;

	/**
	 * The operations whose request takes a `locale` the plugin sends.
	 */
	private const LOCALE_REQUESTS = array(
		array( '/v1/share-links', 'post' ),
		array( '/v1/templates/{id}/designer-links', 'post' ),
	);

	/**
	 * Answers naming a language: path, method, status and property. Pixonaut
	 * marks these `x-extensible-enum`, so a client takes a code it does not know.
	 */
	private const LANGUAGE_ANSWERS = array(
		array( '/v1/share-links', 'post', '201', 'locale' ),
		array( '/v1/templates/{id}/designer-links', 'post', '201', 'locale' ),
		array( '/v1/connect', 'get', '200', 'defaultLanguage' ),
		array( '/v1/connect', 'patch', '200', 'defaultLanguage' ),
	);

	/**
	 * The blank reads that take a `lang` the plugin sends.
	 */
	private const LANGUAGE_QUERIES = array(
		'/v1/blanks/{id}/variants',
		'/v1/blanks/{id}/mockups',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\stubTranslationFunctions();
	}

	public function test_following_the_site_takes_the_sites_language_when_pixonaut_has_it(): void {
		$settings = new Settings();

		$this->assertSame( DesignerLanguage::FOLLOW_SITE, $settings->get( Settings::DESIGNER_LANGUAGE ) );
		$this->assertSame( 'sv', DesignerLanguage::resolve( $settings, 'sv_SE' ) );
		$this->assertSame( 'en', DesignerLanguage::resolve( $settings, 'en_GB' ) );
		$this->assertSame( 'de', DesignerLanguage::resolve( $settings, 'de_DE_formal' ) );
		$this->assertSame( 'fi', DesignerLanguage::resolve( $settings, 'fi' ) );
	}

	public function test_a_regional_locale_maps_to_its_language(): void {
		$this->assertSame( 'de', DesignerLanguage::for_locale( 'de_CH' ) );
		$this->assertSame( 'nb', DesignerLanguage::for_locale( 'nb_NO' ) );
		$this->assertSame( 'fr', DesignerLanguage::for_locale( 'fr_CA' ) );
		$this->assertSame( 'es', DesignerLanguage::for_locale( 'es-MX' ) );
	}

	public function test_following_a_site_in_a_language_pixonaut_lacks_sends_none(): void {
		$settings = new Settings();

		$this->assertNull( DesignerLanguage::resolve( $settings, 'pl_PL' ) );
		$this->assertNull( DesignerLanguage::resolve( $settings, 'nn_NO' ) );
		$this->assertNull( DesignerLanguage::resolve( $settings, '' ) );
	}

	public function test_the_workspace_default_sends_no_language(): void {
		$settings = new Settings();
		$settings->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::WORKSPACE_DEFAULT );

		$this->assertNull( DesignerLanguage::resolve( $settings, 'sv_SE' ) );
	}

	public function test_a_chosen_language_outranks_the_sites(): void {
		$settings = new Settings();

		$settings->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::ENGLISH );
		$this->assertSame( 'en', DesignerLanguage::resolve( $settings, 'sv_SE' ) );

		$settings->set( Settings::DESIGNER_LANGUAGE, 'de' );
		$this->assertSame( 'de', DesignerLanguage::resolve( $settings, 'pl_PL' ) );
	}

	public function test_a_language_pixonaut_does_not_have_reads_as_following_the_site(): void {
		$this->options[ Settings::OPTION ] = array( Settings::DESIGNER_LANGUAGE => 'pl' );

		$this->assertSame( 'sv', DesignerLanguage::resolve( new Settings(), 'sv_SE' ) );
	}

	public function test_the_setting_offers_the_workspace_default_following_the_site_and_each_language_in_its_own_name(): void {
		$names = DesignerLanguage::names();

		$this->assertSame( DesignerLanguage::available(), array_keys( $names ) );
		$this->assertSame( array( DesignerLanguage::WORKSPACE_DEFAULT, DesignerLanguage::FOLLOW_SITE ), array_slice( DesignerLanguage::available(), 0, 2 ) );
		$this->assertSame( 'Pixonaut workspace default', $names[ DesignerLanguage::WORKSPACE_DEFAULT ] );
		$this->assertSame( 'Deutsch', $names['de'] );
		$this->assertSame( 'Suomi', $names['fi'] );
	}

	public function test_the_workspace_default_names_the_language_last_read(): void {
		$this->options[ WorkspaceSettings::OPTION ] = array(
			'accent'           => null,
			'default_language' => 'fr',
			'read_at'          => 0,
		);

		$this->assertSame( 'Pixonaut workspace default (Français)', DesignerLanguage::names()[ DesignerLanguage::WORKSPACE_DEFAULT ] );
	}

	public function test_blank_names_are_read_in_the_sites_language_when_pixonaut_has_it(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		// The request's locale is not the site's.
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );

		update_option( 'WPLANG', 'sv_SE' );
		$this->assertSame( 'sv', DesignerLanguage::of_site() );

		update_option( 'WPLANG', 'pl_PL' );
		$this->assertNull( DesignerLanguage::of_site() );

		update_option( 'WPLANG', '' );
		$this->assertSame( 'en', DesignerLanguage::of_site(), 'a site with no language set runs in English' );
	}

	public function test_the_languages_are_the_locales_the_pinned_spec_takes(): void {
		foreach ( self::LOCALE_REQUESTS as [ $path, $method ] ) {
			$this->assertEqualsCanonicalizing(
				DesignerLanguage::languages(),
				OpenApiAssert::at( array( 'paths', $path, $method, 'requestBody', 'content', 'application/json', 'schema', 'properties', 'locale', 'enum' ) ),
				"{$method} {$path}"
			);
		}
		foreach ( self::LANGUAGE_ANSWERS as [ $path, $method, $status, $property ] ) {
			$this->assertEqualsCanonicalizing(
				DesignerLanguage::languages(),
				OpenApiAssert::at( array( 'paths', $path, $method, 'responses', $status, 'content', 'application/json', 'schema', 'properties', $property, 'x-extensible-enum' ) ),
				"{$method} {$path} {$status} {$property}"
			);
		}
		foreach ( self::LANGUAGE_QUERIES as $path ) {
			$lang = array_values( array_filter( (array) OpenApiAssert::at( array( 'paths', $path, 'get', 'parameters' ) ), static fn ( mixed $parameter ): bool => is_array( $parameter ) && 'lang' === ( $parameter['name'] ?? null ) ) );
			$this->assertEqualsCanonicalizing( DesignerLanguage::languages(), $lang[0]['schema']['enum'] ?? null, "GET {$path} lang" );
		}
	}
}
