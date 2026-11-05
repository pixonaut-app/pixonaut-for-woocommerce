<?php
/**
 * The repository's GitHub Releases, highest version first.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Updates;

use Pixonaut\WooCommerce\Vendor\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi;
use Pixonaut\WooCommerce\Vendor\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Reference;

/**
 * The GitHub client of plugin-update-checker takes the first release in the API's
 * list that passes the filter, and GitHub lists releases by publication date.
 * A maintenance `1.4.1` published after `2.0.0` would then hide `2.0.0`. This
 * client sorts the list by version first, so the first match is the highest
 * eligible version, and remembers the release it chose for its notes.
 */
final class GitHubReleases extends GitHubApi {

	/**
	 * The release list's API path, as the parent class requests it.
	 */
	private const RELEASES_PATH = '/repos/:user/:repo/releases';

	/**
	 * At most this many characters of an upgrade notice show on wordpress.org;
	 * `bin/release/readme.mjs` cuts the readme's notices to the same length.
	 */
	public const UPGRADE_NOTICE_MAX_LENGTH = 300;

	/**
	 * The heading release-please gives breaking changes, after its warning sign.
	 */
	private const BREAKING_HEADING = '/^#{2,}\s.*BREAKING CHANGES\s*$/u';

	/**
	 * The release the last getLatestRelease() chose, as the API returned it.
	 *
	 * @var object|null
	 */
	private ?object $latest_release = null;

	/**
	 * The highest eligible release; also remembers it for latest_release().
	 *
	 * @return Reference|null
	 */
	public function getLatestRelease() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- overrides the library's method.
		$reference = parent::getLatestRelease();
		// A magic property of the library's Reference, which declares no type for it.
		$release              = $reference instanceof Reference ? $reference->__get( 'apiResponse' ) : null;
		$this->latest_release = is_object( $release ) ? $release : null;
		return $reference;
	}

	/**
	 * The release the last check chose, or null before one found any.
	 */
	public function latest_release(): ?object {
		return $this->latest_release;
	}

	/**
	 * A release's upgrade notice: the bullets of the BREAKING CHANGES section
	 * release-please writes into its notes, as plain text. Empty when the
	 * release has no breaking changes.
	 *
	 * @param string $body The release's notes, in Markdown.
	 */
	public static function upgrade_notice( string $body ): string {
		$items      = array();
		$in_section = false;
		$lines      = preg_split( '/\R/u', $body );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( 1 === preg_match( '/^#/', $line ) ) {
				$in_section = 1 === preg_match( self::BREAKING_HEADING, $line );
			} elseif ( ! $in_section ) {
				continue;
			} elseif ( 1 === preg_match( '/^[*-] (.+)$/u', $line, $item ) ) {
				$items[] = self::plain( $item[1] );
			} elseif ( array() !== $items && 1 === preg_match( '/^\s+\S/u', $line ) ) {
				// A bullet's continuation line.
				$items[ count( $items ) - 1 ] .= ' ' . self::plain( $line );
			}
		}
		$notice = implode( ' ', $items );
		if ( mb_strlen( $notice ) <= self::UPGRADE_NOTICE_MAX_LENGTH ) {
			return $notice;
		}
		// Leaves room for the ellipsis.
		return rtrim( mb_substr( $notice, 0, self::UPGRADE_NOTICE_MAX_LENGTH - 1 ) ) . '…';
	}

	/**
	 * GitHub API request; the release list comes back highest version first.
	 *
	 * @param string               $url          API path with `:user` and `:repo` placeholders.
	 * @param array<string, mixed> $query_params Query arguments.
	 * @return mixed|\WP_Error
	 */
	protected function api( $url, $query_params = array() ) {
		$result = parent::api( $url, $query_params );
		if ( self::RELEASES_PATH === $url && is_array( $result ) ) {
			usort(
				$result,
				static fn ( $a, $b ): int => version_compare( self::version_of( $b ), self::version_of( $a ) )
			);
		}
		return $result;
	}

	/**
	 * @param mixed $release A release from the API.
	 */
	private static function version_of( mixed $release ): string {
		$tag = is_object( $release ) && isset( $release->tag_name ) && is_string( $release->tag_name ) ? $release->tag_name : '';
		return ltrim( $tag, 'v' );
	}

	/**
	 * One release-please bullet as plain text: links keep their text, commit
	 * hash references go, `**scope:**` loses its emphasis.
	 *
	 * @param string $text Markdown.
	 */
	private static function plain( string $text ): string {
		$text = (string) preg_replace( '/\s*\(\[[0-9a-f]{7,40}\]\([^)]*\)\)/', '', $text );
		$text = (string) preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', $text );
		$text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '$1', $text );
		return trim( $text );
	}
}
