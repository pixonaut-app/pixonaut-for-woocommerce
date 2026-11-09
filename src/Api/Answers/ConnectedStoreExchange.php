<?php
/**
 * The keys a connect hands over, as the store reads them from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `POST /v1/connect/token` the store reads: the answer for a
 * store, with its keys and webhook endpoint. The answer of a member key
 * alone carries none of them, and is not one a store can connect with.
 */
final class ConnectedStoreExchange {

	/**
	 * @param string                               $connection_id   `connectionId`.
	 * @param string                               $environment     `environment`: `live` or `sandbox`.
	 * @param ConnectedStoreExchangeIntegrationKey $integration_key `integrationKey`.
	 * @param ConnectedStoreExchangePublishableKey $publishable_key `publishableKey`.
	 * @param string                               $tenant_id       `tenantId`.
	 * @param string                               $tenant_name     `tenantName`.
	 * @param string                               $tenant_slug     `tenantSlug`, which the studio's pages sit under.
	 * @param ConnectedStoreExchangeWebhook        $webhook         `webhook`, the store's webhook endpoint.
	 */
	public function __construct(
		public readonly string $connection_id,
		public readonly string $environment,
		public readonly ConnectedStoreExchangeIntegrationKey $integration_key,
		public readonly ConnectedStoreExchangePublishableKey $publishable_key,
		public readonly string $tenant_id,
		public readonly string $tenant_name,
		public readonly string $tenant_slug,
		public readonly ConnectedStoreExchangeWebhook $webhook,
	) {
	}

	/**
	 * `POST /v1/connect/token`.
	 *
	 * @param Sdk                               $pixonaut The SDK.
	 * @param ConnectedStoreExchangeCodeRequest $request  The code and its verifier.
	 *
	 * @throws ApiException When Pixonaut refused (`invalid_grant`, `plan_limit_reached`, …), could not be reached, or answered without a field the store reads.
	 */
	public static function exchange( Sdk $pixonaut, ConnectedStoreExchangeCodeRequest $request ): self {
		return Read::answer( $pixonaut->call( 'connectedStore.exchangeCode', body: $request->toArray() ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			connection_id: Read::string( $data, 'connectionId', $path ),
			environment: Read::string( $data, 'environment', $path ),
			integration_key: Read::object( $data, 'integrationKey', $path, ConnectedStoreExchangeIntegrationKey::from_answer( ... ) ),
			publishable_key: Read::object( $data, 'publishableKey', $path, ConnectedStoreExchangePublishableKey::from_answer( ... ) ),
			tenant_id: Read::string( $data, 'tenantId', $path ),
			tenant_name: Read::string( $data, 'tenantName', $path ),
			tenant_slug: Read::string( $data, 'tenantSlug', $path ),
			webhook: Read::object( $data, 'webhook', $path, ConnectedStoreExchangeWebhook::from_answer( ... ) ),
		);
	}
}
