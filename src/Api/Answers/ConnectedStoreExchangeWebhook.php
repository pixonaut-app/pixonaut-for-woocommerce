<?php
/**
 * The webhook endpoint a connect sets up, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connect answer's `webhook`.
 */
final class ConnectedStoreExchangeWebhook {

	/**
	 * @param string $id     `id`.
	 * @param string $secret `secret`, the signing secret; answered only this once.
	 * @param string $url    `url`, where Pixonaut delivers webhooks.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $secret,
		public readonly string $url,
	) {
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			secret: Read::string( $data, 'secret', $path ),
			url: Read::string( $data, 'url', $path ),
		);
	}
}
