<?php
/**
 * A theme's copy of one of the plugin's templates.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

/**
 * One template a theme overrides, with the `@version` of its copy and of the
 * plugin's own.
 */
final class TemplateOverride {

	/**
	 * @param string $name           Template path below `templates/`, e.g. `admin/notice.php`.
	 * @param string $file           Absolute path of the theme's copy.
	 * @param string $version        The copy's `@version`; empty when it has none.
	 * @param string $plugin_version The plugin template's `@version`.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $file,
		public readonly string $version,
		public readonly string $plugin_version,
	) {
	}

	/**
	 * Whether the copy predates the plugin's template. A copy without an
	 * `@version` cannot be shown to be current, so it counts as outdated.
	 */
	public function outdated(): bool {
		return '' === $this->version || version_compare( $this->version, $this->plugin_version, '<' );
	}
}
