<?php
/**
 * Answers WordPress HTTP requests to the GitHub API from fixtures.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * Hooks `pre_http_request` for `api.github.com`, so the update checker runs
 * end to end without leaving the machine. Serves a repository's release list;
 * every other path is a 404, as for a file the repository does not have.
 */
final class FakeGitHub {

	public const HOST = 'api.github.com';

	/**
	 * Requests in the order they were made.
	 *
	 * @var list<array{url: string, path: string, headers: array<string, string>}>
	 */
	public array $requests = array();

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $releases = array();

	/**
	 * @param string $repository `owner/name`.
	 */
	public function __construct( private readonly string $repository ) {
	}

	public function start(): self {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		return $this;
	}

	public function stop(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
	}

	/**
	 * Adds a release, most recently published first, with the release zip and
	 * its checksum as assets.
	 *
	 * @param string      $version    The version, without the tag's `v`.
	 * @param bool        $prerelease Whether GitHub marks it as a pre-release.
	 * @param string|null $body       The release notes; null for a one-line features list.
	 * @param bool        $zip        Whether the release zip is attached yet.
	 */
	public function release( string $version, bool $prerelease = false, ?string $body = null, bool $zip = true ): self {
		$id               = count( $this->releases ) + 1;
		$name             = 'pixonaut-for-woocommerce-' . $version . '.zip';
		$assets           = array( $this->asset( $id * 10 + 1, $name . '.sha256' ) );
		$this->releases[] = array(
			'tag_name'    => 'v' . $version,
			'draft'       => false,
			'prerelease'  => $prerelease,
			'created_at'  => '2026-10-01T12:00:00Z',
			'zipball_url' => 'https://' . self::HOST . '/repos/' . $this->repository . '/zipball/v' . $version,
			'body'        => $body ?? '### Features' . "\n\n" . '* Version ' . $version,
			'assets'      => $zip ? array( ...$assets, $this->asset( $id * 10 + 2, $name ) ) : $assets,
		);
		return $this;
	}

	/**
	 * Attaches the wordpress.org zip and its checksum to the release added
	 * last, ahead of its other assets.
	 */
	public function with_wporg_zip(): self {
		$index   = count( $this->releases ) - 1;
		$release = $this->releases[ $index ];
		$name    = 'pixonaut-for-woocommerce-' . substr( (string) $release['tag_name'], 1 ) . '-wporg.zip';
		$id      = ( $index + 1 ) * 10;
		$assets  = is_array( $release['assets'] ) ? $release['assets'] : array();

		$this->releases[ $index ]['assets'] = array( $this->asset( $id + 3, $name ), $this->asset( $id + 4, $name . '.sha256' ), ...$assets );
		return $this;
	}

	/**
	 * The paths requested, without query strings.
	 *
	 * @return list<string>
	 */
	public function paths(): array {
		return array_column( $this->requests, 'path' );
	}

	/**
	 * @param false|array<string, mixed>|\WP_Error $response A response another filter returned, or false.
	 * @param array<string, mixed>                 $args     Request arguments.
	 * @param string                               $url      Request URL.
	 * @return false|array<string, mixed>|\WP_Error
	 */
	public function intercept( $response, array $args, string $url ) {
		if ( self::HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $response;
		}
		$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
		$headers          = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$this->requests[] = array(
			'url'     => $url,
			'path'    => $path,
			'headers' => $headers,
		);
		if ( '/repos/' . $this->repository . '/releases' === $path ) {
			return self::json( 200, $this->releases );
		}
		return self::json( 404, array( 'message' => 'Not Found' ) );
	}

	/**
	 * @param int    $id   Asset id.
	 * @param string $name File name.
	 * @return array<string, mixed>
	 */
	private function asset( int $id, string $name ): array {
		return array(
			'name'                 => $name,
			'url'                  => 'https://' . self::HOST . '/repos/' . $this->repository . '/releases/assets/' . $id,
			'browser_download_url' => 'https://github.com/' . $this->repository . '/releases/download/' . $name,
			'download_count'       => 0,
		);
	}

	/**
	 * @param int   $status Status.
	 * @param mixed $body   JSON body.
	 * @return array<string, mixed>
	 */
	private static function json( int $status, mixed $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
