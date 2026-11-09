<?php
/**
 * A page of the store's share links.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/share-links` the store reads.
 */
final class ShareLinkPage {

	/**
	 * @param ShareLink[] $links `links` of the page.
	 * @phpstan-param list<ShareLink> $links
	 */
	public function __construct(
		public readonly array $links,
	) {
	}

	/**
	 * `GET /v1/share-links`.
	 *
	 * @param Sdk                        $pixonaut The SDK.
	 * @param array<string, scalar|null> $query    The narrowing and the page wanted.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function get( Sdk $pixonaut, array $query ): self {
		return Read::answer( $pixonaut->call( 'shareLinks.list', query: $query ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self( Read::objects( $data, 'links', $path, ShareLink::from_answer( ... ) ) );
	}
}
