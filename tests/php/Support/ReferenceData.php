<?php
/**
 * What the generated developer references list.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DeprecatedHooks;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Support\Hooks\Hook;
use Pixonaut\WooCommerce\Tests\Support\Hooks\HookScanner;

/**
 * Collects the data `pnpm docs:generate` writes into docs/developer/: hooks
 * and templates from their docblocks, without WordPress, and labels, settings, emails and
 * REST routes from the registries that define them, in a running WordPress
 * with the plugin active.
 */
final class ReferenceData {

	/**
	 * The locale the references are written in.
	 */
	private const LOCALE = 'en_US';

	/**
	 * The plugin's hooks, its renamed hooks and its templates. Runs without
	 * WordPress, which can have loaded another plugin's copy of the PHP parser.
	 *
	 * @param string $plugin_root The plugin's directory.
	 * @return array<string, mixed>
	 */
	public static function hooks( string $plugin_root ): array {
		return array(
			'hooks'     => array_map( static fn ( Hook $hook ): array => $hook->to_array(), HookScanner::plugin_hooks( $plugin_root . '/src' ) ),
			'renamed'   => self::renamed(),
			'templates' => self::templates( $plugin_root . '/templates/' ),
		);
	}

	/**
	 * Every template with its `@version`, the path of a theme's override and
	 * the first paragraph of its docblock.
	 *
	 * @param string $dir The plugin's `templates/` directory, with a trailing slash.
	 * @return list<array{name: string, version: string, override: string, summary: string}>
	 */
	public static function templates( string $dir ): array {
		$templates = array();
		foreach ( ( new Templates( $dir ) )->names() as $name ) {
			$templates[] = array(
				'name'     => $name,
				'version'  => Templates::version( $dir . $name ),
				'override' => Templates::THEME_PATH . $name,
				'summary'  => self::template_summary( (string) file_get_contents( $dir . $name ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local template.
			);
		}
		return $templates;
	}

	/**
	 * The first paragraph of a template's docblock, on one line.
	 *
	 * @param string $source The template.
	 */
	private static function template_summary( string $source ): string {
		if ( ! preg_match( '#/\*\*(.*?)\*/#s', $source, $match ) ) {
			return '';
		}
		$lines = array();
		foreach ( explode( "\n", $match[1] ) as $line ) {
			$line = trim( (string) preg_replace( '#^\s*\*\s?#', '', $line ) );
			if ( '' === $line && array() !== $lines ) {
				break;
			}
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( ' ', $lines );
	}

	/**
	 * What the running site registers.
	 *
	 * @param string $plugin_root The plugin's directory.
	 * @return array<string, mixed>
	 */
	public static function site( string $plugin_root ): array {
		$plugin = Plugin::boot( $plugin_root . '/pixonaut-for-woocommerce.php' );
		switch_to_locale( self::LOCALE );
		// Option names follow no workspace the site may have read.
		add_filter( 'pre_option_' . WorkspaceSettings::OPTION, '__return_empty_array' );

		return array(
			'labels'      => $plugin->labels()->definitions(),
			'settings'    => self::settings( $plugin ),
			'emails'      => self::emails(),
			'rest_routes' => self::rest_routes(),
		);
	}

	/**
	 * @return list<array{old: string, new: string, version: string, type: string}>
	 */
	private static function renamed(): array {
		/**
		 * Both maps are empty until a hook is renamed.
		 *
		 * @var array<string, array<string, array{0: string, 1: string}>> $maps
		 */
		$maps    = array(
			Hook::FILTER => DeprecatedHooks::RENAMED_FILTERS,
			Hook::ACTION => DeprecatedHooks::RENAMED_ACTIONS,
		);
		$renamed = array();
		foreach ( $maps as $type => $map ) {
			foreach ( $map as $old => list( $new, $version ) ) {
				$renamed[] = array(
					'old'     => $old,
					'new'     => $new,
					'version' => $version,
					'type'    => $type,
				);
			}
		}
		return $renamed;
	}

	/**
	 * @param Plugin $plugin The booted plugin.
	 * @return list<array<string, mixed>>
	 */
	private static function settings( Plugin $plugin ): array {
		$settings = array();
		foreach ( $plugin->settings()->all() as $setting ) {
			$field      = $setting->field();
			$settings[] = array(
				'key'         => $setting->key,
				'default'     => $setting->fallback,
				'type'        => $field['type'] ?? '',
				'title'       => $field['title'] ?? '',
				'description' => $field['desc'] ?? '',
				'options'     => $field['options'] ?? array(),
			);
		}
		return $settings;
	}

	/**
	 * The plugin's WooCommerce emails: the registered ones whose class is the
	 * plugin's.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function emails(): array {
		$emails = array();
		foreach ( WC()->mailer()->get_emails() as $class => $email ) {
			if ( ! str_starts_with( get_class( $email ), 'Pixonaut\\WooCommerce\\' ) ) {
				continue;
			}
			$emails[] = array(
				'id'          => $email->id,
				'title'       => $email->get_title(),
				'description' => $email->get_description(),
				'customer'    => $email->is_customer_email(),
				'template'    => $email->template_html,
			);
		}
		usort( $emails, static fn ( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );
		return $emails;
	}

	/**
	 * @return list<array{route: string, methods: list<string>}>
	 */
	private static function rest_routes(): array {
		$routes = array();
		foreach ( rest_get_server()->get_routes( Plugin::REST_NAMESPACE ) as $route => $handlers ) {
			$methods = array();
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) ) );
			}
			$methods = array_values( array_unique( $methods ) );
			sort( $methods );
			$routes[] = array(
				'route'   => $route,
				'methods' => $methods,
			);
		}
		usort( $routes, static fn ( array $a, array $b ): int => strcmp( $a['route'], $b['route'] ) );
		return $routes;
	}
}
