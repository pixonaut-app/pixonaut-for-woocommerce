<?php
/**
 * The outcome of a test request Pixonaut sent the store's webhook endpoint.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `POST /v1/webhooks/{id}/ping` the store reads.
 */
final class WebhookPing {

	/**
	 * @param bool        $delivered `delivered`.
	 * @param string|null $error     `error`, why it was not delivered; sent only with a request that was not.
	 */
	public function __construct(
		public readonly bool $delivered,
		public readonly ?string $error,
	) {
	}

	/**
	 * `POST /v1/webhooks/{id}/ping`.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       The endpoint.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function send( Sdk $pixonaut, string $id ): self {
		return Read::answer( $pixonaut->call( 'webhookEndpoints.ping', array( 'id' => $id ) ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		$delivered = Read::bool( $data, 'delivered', $path );
		return new self( $delivered, $delivered ? null : Read::string( $data, 'error', $path ) );
	}
}
