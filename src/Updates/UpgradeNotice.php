<?php
/**
 * The notice under a major update in the plugins list.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Updates;

use Pixonaut\WooCommerce\Templates;

/**
 * Warns in the plugins list before a store installs an update that may hold
 * breaking changes, with the release's own upgrade notice when it has one.
 */
final class UpgradeNotice {

	/**
	 * @param string    $basename        The plugin's basename, `<directory>/<file>.php`.
	 * @param string    $current_version The installed version.
	 * @param Templates $templates       Renders the notice.
	 */
	public function __construct(
		private readonly string $basename,
		private readonly string $current_version,
		private readonly Templates $templates,
	) {
	}

	public function register(): void {
		add_action( 'in_plugin_update_message-' . $this->basename, array( $this, 'render' ), 10, 2 );
	}

	/**
	 * Prints the notice inside the update row's message.
	 *
	 * @param array<string, mixed> $plugin_data The plugin's header data.
	 * @param object               $response    The update: `new_version`, optionally `upgrade_notice`.
	 */
	public function render( array $plugin_data, object $response ): void {
		$new_version = isset( $response->new_version ) && is_string( $response->new_version ) ? $response->new_version : '';
		if ( ! self::is_major( $this->current_version, $new_version ) ) {
			return;
		}
		$message = sprintf(
			/* translators: %s: the new version, e.g. 2.0.0. */
			__( 'Version %s is a major update and may hold breaking changes. Read its changelog and back up your site before you update.', 'pixonaut-for-woocommerce' ),
			$new_version
		);

		/**
		 * Filters the warning shown under a major update in the plugins list.
		 *
		 * @since 0.1.0
		 *
		 * @param string $message         The warning; may hold inline markup allowed in posts. Empty hides the notice.
		 * @param string $new_version     The version on offer.
		 * @param string $current_version The installed version.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_updates_upgrade_notice', function ( $message, $new_version ) {
		 *     return $message . ' Ask the web team before updating.';
		 * }, 10, 2 );
		 */
		$message = apply_filters( 'pixonaut_wc_updates_upgrade_notice', $message, $new_version, $this->current_version );
		if ( '' === $message ) {
			return;
		}
		$this->templates->render(
			'admin/upgrade-notice.php',
			array(
				'message'        => $message,
				'upgrade_notice' => isset( $response->upgrade_notice ) && is_string( $response->upgrade_notice ) ? $response->upgrade_notice : '',
			)
		);
	}

	/**
	 * Whether moving between two versions is a major upgrade: the major
	 * version goes up or, below 1.0.0, where breaking changes bump the minor
	 * version, the minor version goes up. Pre-release suffixes are ignored.
	 *
	 * @param string $from The installed version, e.g. `0.3.1`.
	 * @param string $to   The version on offer, e.g. `1.0.0-beta.2`.
	 */
	public static function is_major( string $from, string $to ): bool {
		$old = self::numbers( $from );
		$new = self::numbers( $to );
		if ( null === $old || null === $new ) {
			return false;
		}
		if ( $new[0] !== $old[0] ) {
			return $new[0] > $old[0];
		}
		return 0 === $new[0] && $new[1] > $old[1];
	}

	/**
	 * @param string $version A version such as `1.2.3` or `1.2.3-beta.4`.
	 * @return array{int, int}|null Major and minor, or null when it is not a version.
	 */
	private static function numbers( string $version ): ?array {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)\.\d+/', $version, $parts ) ) {
			return null;
		}
		return array( (int) $parts[1], (int) $parts[2] );
	}
}
