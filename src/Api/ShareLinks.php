<?php
/**
 * Links to Pixonaut's hosted pages.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

final class ShareLinks {

	/**
	 * `kind` of a link to the proof page, where its holder approves or
	 * rejects a design.
	 */
	public const KIND_PROOF_PAGE = 'proof-page';

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * `POST /v1/share-links` for a proof page the customer may decide on.
	 * Asking for changes there sends the customer to `$return_url` with
	 * `$state`.
	 *
	 * @param string      $design_id  The design the page shows.
	 * @param int         $days       How long the link works.
	 * @param string      $return_url Where the page sends a customer who asks for changes; on the store's origin.
	 * @param string      $state      Carried back to `$return_url` untouched.
	 * @param string|null $locale The page's language, one of `DesignerLanguage::languages()`; null for the workspace's default.
	 *
	 * @return array{url: string, expiresAt: int|null} The page's address, and when the link stops working in epoch milliseconds.
	 *
	 * @throws ApiError When Pixonaut refused (`return_url_off_store`, `expiry_too_long`, …) or could not be reached.
	 */
	public function proof_page( string $design_id, int $days, string $return_url, string $state, ?string $locale ): array {
		$response = $this->client->call(
			Operations::CREATE_SHARE_LINK,
			array(),
			array(),
			array_filter(
				array(
					'kind'          => self::KIND_PROOF_PAGE,
					'subjectId'     => $design_id,
					'mayDecide'     => true,
					'expiresInDays' => $days,
					'returnUrl'     => $return_url,
					'state'         => $state,
					'locale'        => $locale,
				),
				// Absent, the page uses the workspace's default language.
				static fn ( mixed $value ): bool => null !== $value
			)
		);
		$link = $this->client->json( Operations::CREATE_SHARE_LINK, $response );
		$url  = $link['url'] ?? null;
		if ( ! is_string( $url ) || '' === $url ) {
			throw ApiError::unexpected( Operations::CREATE_SHARE_LINK, $response );
		}
		$expires_at = $link['expiresAt'] ?? null;
		return array(
			'url'       => $url,
			'expiresAt' => is_int( $expires_at ) || is_float( $expires_at ) ? (int) $expires_at : null,
		);
	}
}
