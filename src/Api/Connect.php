<?php
/**
 * The store's connection, as Pixonaut sees it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

final class Connect {

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * `GET /v1/connect`: the connection as it is now. A `401` (an `ApiError`
	 * with `error_code` `unauthorized`) means it was removed in Pixonaut.
	 *
	 * @return array<string, mixed> `connectionId`, `name`, `siteUrl`, `tenantId`, `tenantName`, `tenantSlug`, `environment`, `webhookEndpointId`, `webhook` (`id`, `url`, `active`, `reachable`), `reachable` (false when Pixonaut cannot reach the store and delivers it no webhooks), `allowedOrigins`, the store's `brandId` and the workspace's `brands`, and the workspace settings: `branding`, `proofWatermark`, `deliverOrdersTo`, `printing`, `assetTtlHours`.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function get(): array {
		return $this->client->json( Operations::GET_CONNECT, $this->client->call( Operations::GET_CONNECT ) );
	}

	/**
	 * `POST /v1/connect/token`: exchanges the code the studio sent the
	 * merchant back with for the store's keys. A code works once; every
	 * refusal is `400 invalid_grant`.
	 *
	 * @param string $code          The `code` from the return to `redirect_uri`.
	 * @param string $code_verifier The PKCE verifier the challenge was made from.
	 * @param string $redirect_uri  The `redirect_uri` the merchant was sent with.
	 *
	 * @return array<string, mixed> `tenantId`, `tenantName`, `tenantSlug`, `environment`, `connectionId`, `integrationKey{id,key,label}`, `publishableKey{id,key,allowedOrigins}`, `webhook{id,url,secret}`, and `memberKey{key,memberId,memberName,memberEmail}` linking the member who approved to the admin who connected.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function exchange( string $code, string $code_verifier, string $redirect_uri ): array {
		$response = $this->client->call(
			Operations::EXCHANGE_CONNECT_CODE,
			array(),
			array(),
			array(
				'code'          => $code,
				'code_verifier' => $code_verifier,
				'redirect_uri'  => $redirect_uri,
			)
		);
		return $this->client->json( Operations::EXCHANGE_CONNECT_CODE, $response );
	}

	/**
	 * `PATCH /v1/connect`: sets the origins the publishable key takes
	 * besides the site's own. Pixonaut takes up to ten on the site's own
	 * registrable domain, with its scheme and port; anything else is
	 * `400 invalid_origin`, one past the limit `too_many_origins`.
	 *
	 * @param string[] $origins The other origins; empty to take them away.
	 * @phpstan-param list<string> $origins
	 *
	 * @return array<string, mixed> The connection as `get()` answers it.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function allow_origins( array $origins ): array {
		$response = $this->client->call( Operations::UPDATE_CONNECT, array(), array(), array( 'additionalOrigins' => $origins ) );
		return $this->client->json( Operations::UPDATE_CONNECT, $response );
	}

	/**
	 * `PATCH /v1/connect`: sets the brand the store's hosted pages are drawn
	 * in from now on. An id that is not one of the workspace's brands is
	 * `404 brand_not_found`, and nothing changes.
	 *
	 * @param string|null $brand_id One of `get()`'s `brands`, or null for the workspace's default.
	 *
	 * @return array<string, mixed> The connection as `get()` answers it.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function choose_brand( ?string $brand_id ): array {
		$response = $this->client->call( Operations::UPDATE_CONNECT, array(), array(), array( 'brandId' => $brand_id ) );
		return $this->client->json( Operations::UPDATE_CONNECT, $response );
	}

	/**
	 * `PUT /v1/connect/store-access`: hands Pixonaut the store's credential,
	 * replacing any it holds. Answering a request from the studio that is no
	 * longer current is `409 store_access_request_stale`, and changes nothing.
	 *
	 * @param string      $credential The secret Pixonaut calls the shop tools with.
	 * @param string|null $request_id The `requestId` of the `store_access.requested` it answers; null for an opt-in in WordPress, which always wins.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function set_store_access( string $credential, ?string $request_id = null ): void {
		$body = array( 'credential' => $credential );
		if ( null !== $request_id ) {
			$body['requestId'] = $request_id;
		}
		$this->client->call( Operations::SET_STORE_ACCESS, array(), array(), $body );
	}

	/**
	 * `DELETE /v1/connect/store-access`: has Pixonaut delete the credential it
	 * holds; nothing happens when it holds none.
	 *
	 * @param int $timeout_seconds Give up after this long.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function delete_store_access( int $timeout_seconds = Client::TIMEOUT_SECONDS ): void {
		$this->client->send( $this->client->prepare( Operations::DELETE_STORE_ACCESS ), $timeout_seconds );
	}

	/**
	 * `DELETE /v1/connect`: revokes the store's integration key, publishable
	 * key and webhook endpoint.
	 *
	 * @param int $timeout_seconds Give up after this long.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function delete( int $timeout_seconds = Client::TIMEOUT_SECONDS ): void {
		$this->client->send( $this->client->prepare( Operations::DELETE_CONNECT ), $timeout_seconds );
	}
}
