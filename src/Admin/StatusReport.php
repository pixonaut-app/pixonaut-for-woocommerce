<?php
/**
 * The plugin's section of WooCommerce > Status.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\TemplateOverride;
use Pixonaut\WooCommerce\Templates;

/**
 * Lists, on WooCommerce > Status, the plugin's templates the active theme
 * overrides, and flags each copy older than the plugin's template. WooCommerce
 * scans only its own templates, so the section sits below its report and is
 * part of the copied system report.
 */
final class StatusReport {

	/**
	 * @param Templates $templates Finds the overrides and renders the section.
	 */
	public function __construct( private readonly Templates $templates ) {
	}

	public function register(): void {
		add_action( 'woocommerce_system_status_report', array( $this, 'render' ) );
	}

	/**
	 * Prints the section.
	 */
	public function render(): void {
		$overrides = $this->templates->overrides();
		$this->templates->render(
			'admin/status-report.php',
			array(
				'overrides'    => array_map(
					static fn ( TemplateOverride $override ): array => array(
						'file'           => self::shown_path( $override->file ),
						'version'        => $override->version,
						'plugin_version' => $override->plugin_version,
						'outdated'       => $override->outdated(),
					),
					$overrides
				),
				'has_outdated' => array() !== array_filter( $overrides, static fn ( TemplateOverride $override ): bool => $override->outdated() ),
			)
		);
	}

	/**
	 * A theme file's path as WooCommerce shows its own overrides: relative to
	 * the WordPress root.
	 *
	 * @param string $file Absolute path.
	 */
	private static function shown_path( string $file ): string {
		return str_starts_with( $file, ABSPATH ) ? substr( $file, strlen( ABSPATH ) ) : $file;
	}
}
