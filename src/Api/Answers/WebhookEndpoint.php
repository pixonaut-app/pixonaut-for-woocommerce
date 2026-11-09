<?php
/**
 * A webhook endpoint, as Pixonaut lists it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Model\Decode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of an item of `GET /v1/webhooks` the store reads.
 */
final class WebhookEndpoint {

	/**
	 * @param string $id           `id`.
	 * @param bool   $active       `active`.
	 * @param bool   $failing      `failing`: deliveries are failing now.
	 * @param float  $failed_count `failedCount`: deliveries that failed for good.
	 * @param string $url          `url`, the address Pixonaut delivers to.
	 */
	public function __construct(
		public readonly string $id,
		public readonly bool $active,
		public readonly bool $failing,
		public readonly float $failed_count,
		public readonly string $url,
	) {
	}

	/**
	 * `GET /v1/webhooks`: the tenant's endpoints.
	 *
	 * @param Sdk $pixonaut The SDK.
	 *
	 * @return list<self>
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function all( Sdk $pixonaut ): array {
		return $pixonaut->call( 'webhookEndpoints.list' )->decode(
			static fn ( mixed $json ): array => Decode::list(
				$json,
				'$',
				static fn ( mixed $item, string $at ): self => self::from_answer( Decode::object( $item, $at ), $at )
			)
		);
	}

	/**
	 * Whether the endpoint is active and has deliveries that failed for good.
	 */
	public function has_failed(): bool {
		return $this->active && 0 < $this->failed_count;
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			active: Read::bool( $data, 'active', $path ),
			failing: Read::bool( $data, 'failing', $path ),
			failed_count: Read::float( $data, 'failedCount', $path ),
			url: Read::string( $data, 'url', $path ),
		);
	}
}
