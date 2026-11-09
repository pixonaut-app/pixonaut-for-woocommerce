<?php
/**
 * A share link, as Pixonaut lists it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * The fields of an item of `links` in the answer of `GET /v1/share-links` the store reads.
 */
final class ShareLink {

	/**
	 * @param string      $id  `id`.
	 * @param string|null $url `url`, what the link opens now; null when Pixonaut lists none.
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?string $url,
	) {
	}

	/**
	 * @param array<string, mixed> $data The link.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			url: Read::nullable_string( $data, 'url', $path ),
		);
	}
}
