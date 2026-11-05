<?php
/**
 * Updates from the plugin's GitHub Releases.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Updates;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Vendor\YahnisElsts\PluginUpdateChecker\v5p7\Plugin\PluginInfo;
use Pixonaut\WooCommerce\Vendor\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;
use Pixonaut\WooCommerce\Vendor\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;

/**
 * Offers the release zips attached to the repository named in the plugin's
 * `Update URI` header, through plugin-update-checker. The wordpress.org build
 * leaves this class and the library out (`docs/developer/releasing.md`).
 */
final class GitHubUpdater {

	/**
	 * The release zip attached to each GitHub Release. The checksum file next
	 * to it does not match.
	 */
	public const ASSET_PATTERN = '/^pixonaut-for-woocommerce-\d+\.\d+\.\d+(?:-beta\.\d+)?\.zip$/';

	/**
	 * How many of the most recently published releases are searched for the
	 * highest one on the store's channel. A stable store skips betas, so this
	 * bounds how many betas may follow the last stable release.
	 */
	public const RELEASES_SEARCHED = 20;

	/**
	 * Hours between two automatic checks.
	 */
	public const CHECK_PERIOD_HOURS = 12;

	private ?PluginUpdateChecker $checker = null;

	/**
	 * @param string      $file    Absolute path of the main plugin file.
	 * @param string|null $token   GitHub token that may read the repository's releases; null for a public repository.
	 * @param string      $channel One of UpdateChannel::all().
	 */
	public function __construct(
		private readonly string $file,
		private readonly ?string $token,
		private readonly string $channel,
	) {
	}

	/**
	 * Starts checking for updates; null when the plugin header names no repository.
	 */
	public function register(): ?PluginUpdateChecker {
		$repository = get_file_data( $this->file, array( 'update_uri' => 'Update URI' ) )['update_uri'];
		if ( '' === $repository ) {
			return null;
		}
		$api = new GitHubReleases( $repository );
		$api->enableReleaseAssets( self::ASSET_PATTERN, Api::REQUIRE_RELEASE_ASSETS );
		$channel = $this->channel;
		// A release whose zip is not attached yet (still building, or a failed
		// build) is skipped rather than hiding the older releases behind it.
		$api->setReleaseFilter(
			static fn ( string $version, object $release ): bool => UpdateChannel::accepts( $channel, $version ) && self::has_zip( $release ),
			UpdateChannel::BETA === $channel ? Api::RELEASE_FILTER_ALL : Api::RELEASE_FILTER_SKIP_PRERELEASE,
			self::RELEASES_SEARCHED
		);

		$checker = new PluginUpdateChecker(
			$api,
			$this->file,
			Plugin::SLUG,
			self::CHECK_PERIOD_HOURS,
			UpdateChannel::state_option( $channel )
		);
		// Sent as a Basic authorization header, never in a URL, so it is not
		// stored with the update the checker caches. Without a token the
		// checker reads the public releases and downloads the zip from its
		// public download URL.
		if ( null !== $this->token ) {
			$checker->setAuthentication( $this->token );
		}

		// Releases only: the checker would otherwise fall back to the highest
		// tag, beta or not, and then to the default branch.
		add_filter(
			$checker->getUniqueName( 'vcs_update_detection_strategies' ),
			static fn ( array $strategies ): array => array_intersect_key( $strategies, array( Api::STRATEGY_LATEST_RELEASE => true ) )
		);
		// The readme.txt with the upgrade notices exists only in the zip, so
		// the notice comes from the release's notes instead.
		add_filter(
			$checker->getUniqueName( 'request_info_result' ),
			static function ( $info ) use ( $api ) {
				$release = $api->latest_release();
				if ( $info instanceof PluginInfo && null !== $release && isset( $release->body ) && is_string( $release->body ) ) {
					$info->upgrade_notice = GitHubReleases::upgrade_notice( $release->body );
				}
				return $info;
			}
		);
		$this->checker = $checker;
		return $checker;
	}

	/**
	 * Whether a release has the zip the checker installs attached.
	 *
	 * @param object $release A release from the GitHub API.
	 */
	private static function has_zip( object $release ): bool {
		$assets = isset( $release->assets ) && is_array( $release->assets ) ? $release->assets : array();
		foreach ( $assets as $asset ) {
			if ( is_object( $asset ) && isset( $asset->name ) && is_string( $asset->name ) && 1 === preg_match( self::ASSET_PATTERN, $asset->name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The update checker, once register() started one.
	 */
	public function checker(): ?PluginUpdateChecker {
		return $this->checker;
	}
}
