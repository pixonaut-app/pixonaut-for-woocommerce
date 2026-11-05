<?php
/**
 * Plugin updates.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Updates;

use Pixonaut\WooCommerce\Settings\Settings;

/**
 * Where updates come from and which ones a store is offered.
 *
 * The GitHub build checks the public repository's releases, on the channel
 * the store chose. `PIXONAUT_WC_UPDATE_TOKEN` is optional: it authenticates
 * the checks, for a build whose `Update URI` names a private repository. The wordpress.org
 * build has no GitHubUpdater and no channel setting, and updates through
 * wordpress.org.
 */
final class Updates {

	/**
	 * The `wp-config.php` constant holding a GitHub token that may read the
	 * repository's releases. It is read on each request and never stored.
	 */
	public const TOKEN_CONSTANT = 'PIXONAUT_WC_UPDATE_TOKEN';

	private ?GitHubUpdater $updater = null;

	/**
	 * @param Settings    $settings      Holds the update channel.
	 * @param string      $file          Absolute path of the main plugin file.
	 * @param string|null $token         The GitHub token; null when none is set.
	 * @param bool        $github_build  Whether this build updates from GitHub (has GitHubUpdater).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly string $file,
		private readonly ?string $token,
		private readonly bool $github_build,
	) {
	}

	/**
	 * @param Settings $settings Holds the update channel.
	 * @param string   $file     Absolute path of the main plugin file.
	 */
	public static function for_plugin( Settings $settings, string $file ): self {
		return new self( $settings, $file, self::configured_token(), class_exists( GitHubUpdater::class ) );
	}

	/**
	 * The token from `wp-config.php`, or null when it is missing or empty.
	 */
	public static function configured_token(): ?string {
		if ( ! defined( self::TOKEN_CONSTANT ) ) {
			return null;
		}
		$token = constant( self::TOKEN_CONSTANT );
		return is_string( $token ) && '' !== trim( $token ) ? trim( $token ) : null;
	}

	public function register(): void {
		if ( ! $this->github_build ) {
			return;
		}
		$this->settings->register( UpdateChannel::setting() );
		$this->updater = new GitHubUpdater( $this->file, $this->token, $this->channel() );
		$this->updater->register();
	}

	/**
	 * The GitHub updater, once register() started one.
	 */
	public function updater(): ?GitHubUpdater {
		return $this->updater;
	}

	public function channel(): string {
		$channel = $this->settings->get( UpdateChannel::SETTING );
		return is_string( $channel ) ? $channel : UpdateChannel::STABLE;
	}
}
