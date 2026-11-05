<?php
/**
 * Pixonaut's answers about the store's connection, as the spec shapes them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Connection\WorkspaceSettings;

final class ConnectionAnswers {

	/**
	 * The workspace's slug, which the studio's pages sit under.
	 */
	public const TENANT_SLUG = 'acme-prints';

	/**
	 * Keeps TENANT_SLUG as the workspace's, as a connect does, so the
	 * studio links the plugin builds carry it.
	 */
	public static function keep_tenant_slug(): void {
		update_option( WorkspaceSettings::OPTION, array( 'tenant_slug' => self::TENANT_SLUG ), false );
	}

	/**
	 * Where the studio's pages of the workspace TENANT_SLUG start, after the studio's address.
	 */
	public static function studio_path(): string {
		return '/w/' . self::TENANT_SLUG . '/';
	}

	/**
	 * The workspace settings `GET /v1/connect` answers beside the
	 * connection: its webhook endpoint, no branding, the workspace's default brand, a site address
	 * that answered, no saved delivery address, Pixonaut's default
	 * print resolution and upload retention, and English, the default language until the workspace chooses one.
	 */
	public const WORKSPACE_SETTINGS = array(
		'webhook'                => array(
			'id'        => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'url'       => 'http://example.org/wp-json/pixonaut/v1/webhook',
			'active'    => true,
			'reachable' => true,
		),
		'branding'               => array(
			'logoUrl'     => null,
			'accentColor' => null,
			'brandId'     => null,
		),
		'brandId'                => null,
		'brands'                 => array(),
		'reachable'              => true,
		'proofWatermark'         => false,
		'deliverOrdersTo'        => null,
		'defaultLanguage'        => 'en',
		'printFileForm'          => null,
		'printFileTextTolerance' => null,
		'printing'               => array(
			'minPrintDpi'            => 150,
			'defaultBleedMm'         => 3,
			'defaultSafeAreaMm'      => 3,
			'printFileForm'          => 'flat',
			'printFileTextTolerance' => array(
				'unit'  => 'px',
				'value' => 1,
			),
		),
		'assetTtlHours'          => 168,
	);

	/**
	 * What `GET /v1/connect` answers for a connected store.
	 *
	 * @param bool $reachable Whether the store's address answered.
	 * @return array<string, mixed>
	 */
	public static function connection( bool $reachable ): array {
		return array(
			'allowedOrigins'    => array( 'http://example.org' ),
			'connectionId'      => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
			'environment'       => 'sandbox',
			'name'              => 'WooCommerce · Acme Shop',
			'siteUrl'           => 'http://example.org',
			'tenantId'          => '5c3a1f6e-8b2d-4e7a-9c1b-000000000002',
			'tenantName'        => 'Acme',
			'tenantSlug'        => self::TENANT_SLUG,
			'webhookEndpointId' => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'webhook'           => array(
				'id'        => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
				'url'       => 'http://example.org/wp-json/pixonaut/v1/webhook',
				'active'    => true,
				'reachable' => $reachable,
			),
			'reachable'         => $reachable,
		) + self::WORKSPACE_SETTINGS;
	}
}
