<?php
/**
 * The store's connection, as the store reads it from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/connect` the store reads; `PATCH /v1/connect`
 * answers the same.
 */
final class ConnectedStore {

	/**
	 * @param string[]                           $allowed_origins   `allowedOrigins`, the publishable key's origins.
	 * @param string|null                        $brand_id          `brandId`, the brand chosen for the store; null while it follows the workspace's default.
	 * @param ConnectedStoreBranding             $branding          `branding`, what the store's hosted pages are drawn in.
	 * @param ConnectedStoreBrand[]              $brands            `brands`, the workspace's brands, the default first.
	 * @param string                             $connection_id     `connectionId`.
	 * @param string                             $default_language  `defaultLanguage`.
	 * @param ConnectedStoreDeliverOrdersTo|null $deliver_orders_to `deliverOrdersTo`; null when orders ship to their customer.
	 * @param string                             $environment       `environment`: `live` or `sandbox`.
	 * @param string                             $name              `name`, the integration key's name.
	 * @param ConnectedStorePrinting             $printing          `printing`, the workspace's print defaults.
	 * @param bool                               $proof_watermark   `proofWatermark`.
	 * @param bool                               $reachable         `reachable`, whether the store's webhook address answered when last checked.
	 * @param string                             $site_url          `siteUrl`.
	 * @param string                             $tenant_name       `tenantName`.
	 * @param string                             $tenant_slug       `tenantSlug`, which the studio's pages sit under.
	 * @phpstan-param list<ConnectedStoreBrand> $brands
	 * @phpstan-param list<string> $allowed_origins
	 */
	public function __construct(
		public readonly array $allowed_origins,
		public readonly ?string $brand_id,
		public readonly ConnectedStoreBranding $branding,
		public readonly array $brands,
		public readonly string $connection_id,
		public readonly string $default_language,
		public readonly ?ConnectedStoreDeliverOrdersTo $deliver_orders_to,
		public readonly string $environment,
		public readonly string $name,
		public readonly ConnectedStorePrinting $printing,
		public readonly bool $proof_watermark,
		public readonly bool $reachable,
		public readonly string $site_url,
		public readonly string $tenant_name,
		public readonly string $tenant_slug,
	) {
	}

	/**
	 * `GET /v1/connect`.
	 *
	 * @param Sdk $pixonaut The SDK.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function get( Sdk $pixonaut ): self {
		return Read::answer( $pixonaut->call( 'connectedStore.get' ), self::from_answer( ... ) );
	}

	/**
	 * `PATCH /v1/connect`.
	 *
	 * @param Sdk                         $pixonaut The SDK.
	 * @param ConnectedStoreUpdateRequest $request  The change.
	 *
	 * @throws ApiException When Pixonaut refused (`invalid_origin`, `brand_not_found`, …), could not be reached, or answered without a field the store reads.
	 */
	public static function update( Sdk $pixonaut, ConnectedStoreUpdateRequest $request ): self {
		return Read::answer( $pixonaut->call( 'connectedStore.update', body: $request->toArray() ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			allowed_origins: Read::strings( $data, 'allowedOrigins', $path ),
			brand_id: Read::nullable_string( $data, 'brandId', $path ),
			branding: Read::object( $data, 'branding', $path, ConnectedStoreBranding::from_answer( ... ) ),
			brands: Read::objects( $data, 'brands', $path, ConnectedStoreBrand::from_answer( ... ) ),
			connection_id: Read::string( $data, 'connectionId', $path ),
			default_language: Read::string( $data, 'defaultLanguage', $path ),
			deliver_orders_to: Read::nullable_object( $data, 'deliverOrdersTo', $path, ConnectedStoreDeliverOrdersTo::from_answer( ... ) ),
			environment: Read::string( $data, 'environment', $path ),
			name: Read::string( $data, 'name', $path ),
			printing: Read::object( $data, 'printing', $path, ConnectedStorePrinting::from_answer( ... ) ),
			proof_watermark: Read::bool( $data, 'proofWatermark', $path ),
			reachable: Read::bool( $data, 'reachable', $path ),
			site_url: Read::string( $data, 'siteUrl', $path ),
			tenant_name: Read::string( $data, 'tenantName', $path ),
			tenant_slug: Read::string( $data, 'tenantSlug', $path ),
		);
	}
}
