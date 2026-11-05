<?php
/**
 * The language the designer is shown in.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Closure;
use MO;
use NOOP_Translations;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Settings\Settings;
use WP_Textdomain_Registry;

/**
 * Which language the inline designer, Pixonaut's hosted designer and the
 * proof page speak: the Pixonaut workspace's default, the visitor's language
 * on the site, or one chosen for the store. LANGUAGE_NAMES is the list Pixonaut's
 * `locale` takes (the pinned spec's enum, checked by a test), so a language
 * Pixonaut adds is one entry here.
 */
final class DesignerLanguage {

	/**
	 * No language is sent, and Pixonaut uses the workspace's default.
	 */
	public const WORKSPACE_DEFAULT = 'workspace';

	/**
	 * The visitor's language on the site when Pixonaut has it, else the
	 * workspace default.
	 */
	public const FOLLOW_SITE = 'site';

	public const ENGLISH = 'en';

	public const SWEDISH = 'sv';

	/**
	 * The languages Pixonaut's customer-facing pages and the SDK speak, by
	 * the code its `locale` takes, each named in its own language.
	 */
	private const LANGUAGE_NAMES = array(
		self::ENGLISH => 'English',
		self::SWEDISH => 'Svenska',
		'de'          => 'Deutsch',
		'fr'          => 'Français',
		'es'          => 'Español',
		'nl'          => 'Nederlands',
		'da'          => 'Dansk',
		'nb'          => 'Norsk bokmål',
		'fi'          => 'Suomi',
	);

	/**
	 * The WordPress locale whose translations speak each language, for a
	 * page in another one.
	 */
	private const WORDPRESS_LOCALES = array(
		self::ENGLISH => self::WORDPRESS_DEFAULT_LOCALE,
		self::SWEDISH => 'sv_SE',
		'de'          => 'de_DE',
		'fr'          => 'fr_FR',
		'es'          => 'es_ES',
		'nl'          => 'nl_NL',
		'da'          => 'da_DK',
		'nb'          => 'nb_NO',
		'fi'          => 'fi',
	);

	/**
	 * Separates a WordPress locale's language from its region, e.g. `de_CH`.
	 */
	private const LOCALE_SEPARATOR = '_';

	/**
	 * The locale WordPress runs in when no language is set.
	 */
	private const WORDPRESS_DEFAULT_LOCALE = 'en_US';

	/**
	 * @return list<string> The codes Pixonaut's `locale` takes.
	 */
	public static function languages(): array {
		return array_keys( self::LANGUAGE_NAMES );
	}

	/**
	 * @return list<string> Every choice the setting offers.
	 */
	public static function available(): array {
		return array_merge( array( self::WORKSPACE_DEFAULT, self::FOLLOW_SITE ), self::languages() );
	}

	/**
	 * Each choice's name, for the store manager. The workspace default names
	 * the workspace's language when the store read it.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		$default = self::name( WorkspaceSettings::default_language() );
		return array(
			self::WORKSPACE_DEFAULT => null === $default
				/* translators: Option in the designer language setting, shown to the merchant: use the default language set in the Pixonaut workspace. */
				? __( 'Pixonaut workspace default', 'pixonaut-for-woocommerce' )
				/* translators: Option in the designer language setting, shown to the merchant. %s: the workspace's default language, named in that language, e.g. Deutsch. */
				: sprintf( __( 'Pixonaut workspace default (%s)', 'pixonaut-for-woocommerce' ), $default ),
			/* translators: Option in the designer language setting, shown to the merchant: use the site's language. */
			self::FOLLOW_SITE       => __( 'Follow the site', 'pixonaut-for-woocommerce' ),
		) + self::LANGUAGE_NAMES;
	}

	/**
	 * A language's name in its own language, e.g. `Deutsch` for `de`.
	 *
	 * @param string|null $language One of languages().
	 * @return string|null Null for null or any other code.
	 */
	public static function name( ?string $language ): ?string {
		return null === $language ? null : ( self::LANGUAGE_NAMES[ $language ] ?? null );
	}

	/**
	 * The designer's language: the store's choice, or the visitor's
	 * language on the site when it follows the site.
	 *
	 * @param Settings $settings The store's settings.
	 * @param string   $locale   The WordPress locale of the request, e.g. `sv_SE`, as `determine_locale()` answers it.
	 * @return string|null One of languages(), as the SDK's `locale` and a link's take it; null to send none, so Pixonaut uses the workspace's default.
	 */
	public static function resolve( Settings $settings, string $locale ): ?string {
		$chosen = (string) $settings->get( Settings::DESIGNER_LANGUAGE );
		if ( isset( self::LANGUAGE_NAMES[ $chosen ] ) ) {
			return $chosen;
		}
		return self::WORKSPACE_DEFAULT === $chosen ? null : self::for_locale( $locale );
	}

	/**
	 * The language the store reads a blank's option names, option values and
	 * view labels in: the site's, as set under Settings, so what the store
	 * keeps does not change with the language of whoever saved a product, a
	 * switched locale or a multilingual plugin's filter.
	 *
	 * @return string|null One of languages(); null when Pixonaut does not have the site's language, for the names as written.
	 */
	public static function of_site(): ?string {
		$locale = get_option( 'WPLANG' );
		if ( ( ! is_string( $locale ) || '' === $locale ) && is_multisite() ) {
			$locale = get_site_option( 'WPLANG' );
		}
		return self::for_locale( is_string( $locale ) && '' !== $locale ? $locale : self::WORDPRESS_DEFAULT_LOCALE );
	}

	/**
	 * What `$render` prints with the plugin's own strings in `$language`:
	 * the words the designer writes, printed before it does in a language
	 * that may not be the page's. The request's own translations when its
	 * locale already speaks the language (`de_CH` for `de`), else the
	 * plugin's translations for the language's locale (`WORDPRESS_LOCALES`),
	 * which need no WordPress language pack; the strings as written when
	 * there are none. Only the plugin's text domain is switched.
	 *
	 * @param string  $language One of languages().
	 * @param Closure $render   Prints the page part.
	 * @phpstan-param Closure(): string $render
	 */
	public static function words_in( string $language, Closure $render ): string {
		$locale = self::WORDPRESS_LOCALES[ $language ] ?? null;
		if ( null === $locale || self::for_locale( determine_locale() ) === $language ) {
			return $render();
		}
		global $l10n;
		$had                         = isset( $l10n[ Plugin::TEXT_DOMAIN ] );
		$kept                        = $had ? $l10n[ Plugin::TEXT_DOMAIN ] : null;
		$l10n[ Plugin::TEXT_DOMAIN ] = self::translations( $locale ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		try {
			return $render();
		} finally {
			if ( $had ) {
				$l10n[ Plugin::TEXT_DOMAIN ] = $kept; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the request's own.
			} else {
				unset( $l10n[ Plugin::TEXT_DOMAIN ] );
			}
		}
	}

	/**
	 * The plugin's translations for a locale, from where WordPress would
	 * load them (`WP_LANG_DIR/plugins`, else the plugin's `languages`).
	 *
	 * @param string $locale A WordPress locale.
	 */
	private static function translations( string $locale ): MO|NOOP_Translations {
		global $wp_textdomain_registry;
		$path = $wp_textdomain_registry instanceof WP_Textdomain_Registry ? $wp_textdomain_registry->get( Plugin::TEXT_DOMAIN, $locale ) : false;
		$file = is_string( $path ) ? $path . Plugin::TEXT_DOMAIN . '-' . $locale . '.mo' : '';
		$mo   = new MO();
		return '' !== $file && is_readable( $file ) && $mo->import_from_file( $file ) ? $mo : new NOOP_Translations();
	}

	/**
	 * The designer's language for a WordPress locale: its language, without
	 * the region (`de_CH` is `de`).
	 *
	 * @param string $locale E.g. `sv_SE`.
	 * @return string|null One of languages(); null when Pixonaut does not have the locale's language.
	 */
	public static function for_locale( string $locale ): ?string {
		$language = strtolower( explode( self::LOCALE_SEPARATOR, str_replace( '-', self::LOCALE_SEPARATOR, $locale ) )[0] );
		return isset( self::LANGUAGE_NAMES[ $language ] ) ? $language : null;
	}
}
