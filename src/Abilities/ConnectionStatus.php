<?php
/**
 * The ability that reads the store's connection to Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Webhooks\Endpoint;

/**
 * Whether the store is connected to a Pixonaut workspace, which one,
 * whether the connection is paused, and what the connection card shows of
 * it: the origins its publishable key takes, the last test request to the
 * webhook endpoint, and, read from Pixonaut while the connection works, the
 * workspace's brands and settings. Never a key. Whoever may manage the
 * store may read it. The connection abilities answer with it too.
 */
final class ConnectionStatus implements Ability {

	/**
	 * @param Connection $connection The store's connection.
	 * @param Sdk        $api        `GET /v1/connect`, for the workspace's settings.
	 * @param Endpoint   $endpoint   The store's webhook endpoint.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $api,
		private readonly Endpoint $endpoint,
	) {
	}

	public const NAME = 'get-connection-status';

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the Pixonaut connection', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Says whether the store is connected to a Pixonaut workspace, which workspace and environment, whether the connection is paused and why, which origins its key takes, how the last test request to the webhook went, and the workspace\'s brands and settings.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => self::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( self::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Whether the user may read and change the connection, as on its card.
	 */
	public static function can_manage(): bool {
		return current_user_can( Notices::CAPABILITY );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		$text_or_null = array( 'type' => array( 'string', 'null' ) );
		$date_or_null = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'connected'          => array( 'type' => 'boolean' ),
				'paused'             => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Why the connection is paused: `unauthorized` (Pixonaut refused the store\'s key; connect again in the admin) or `site_changed` (the site\'s address changed: put it back, or forget the connection this copy of the site was copied with); null when it is not.', 'pixonaut-for-woocommerce' ),
				),
				'workspace'          => $text_or_null,
				'workspace_id'       => $text_or_null,
				'environment'        => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( '`live` or `sandbox`.', 'pixonaut-for-woocommerce' ),
				),
				'site'               => $text_or_null,
				'connected_at'       => $date_or_null,
				'allowed_origins'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'The origins the store\'s publishable key takes.', 'pixonaut-for-woocommerce' ),
				),
				'www_origin'         => array(
					'type'        => array( 'string', 'null' ),
					'description' => sprintf(
						/* translators: %s: the name of the ability that allows the www. address. */
						__( 'The site\'s counterpart with or without `www.`, which %s adds to the origins; null for a site on an IP address.', 'pixonaut-for-woocommerce' ),
						Abilities::tool( ConnectionAction::ALLOW_WWW )
					),
				),
				'www_allowed'        => array( 'type' => 'boolean' ),
				'last_ping'          => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'The last test request to the store\'s webhook endpoint: whether it arrived, why not, and when it was sent; null when none was sent.', 'pixonaut-for-woocommerce' ),
					'properties'  => array(
						'delivered' => array( 'type' => 'boolean' ),
						'error'     => array( 'type' => 'string' ),
						'at'        => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
					),
				),
				'workspace_settings' => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'The workspace\'s settings as Pixonaut answered them: `brands` the store may choose from (the default first), `brand_id` the brand its pages are drawn in, `chosen_brand_id` the store\'s own choice (null while it follows the default), its logo and accent colour, the proof watermark, where orders ship, the least print resolution and the default language. Null while the connection is paused or Pixonaut could not be reached.', 'pixonaut-for-woocommerce' ),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		$live = null;
		if ( null !== $this->connection->current() && null === $this->connection->paused() ) {
			try {
				$live = $this->api->connect()->get()->raw();
			} catch ( ApiException $e ) {
				// A 401 has paused the connection by now; either way the workspace's settings cannot be told.
				$live = null;
			}
		}
		return $this->of( $live );
	}

	/**
	 * The connection, with what Pixonaut last answered of it.
	 *
	 * @param array<string, mixed>|null $live The connection as `GET` or `PATCH /v1/connect` answered it; null when it could not be read.
	 * @return array<string, mixed>
	 */
	public function of( ?array $live ): array {
		$stored  = $this->connection->current();
		$summary = $stored?->summary();
		$origins = is_array( $live['allowedOrigins'] ?? null ) ? array_values( array_filter( $live['allowedOrigins'], 'is_string' ) ) : ( $stored->allowed_origins ?? array() );
		$www     = null === $stored ? null : Connection::www_origin();
		$ping    = null === $stored ? null : $this->endpoint->last_ping();
		return array(
			'connected'          => null !== $summary,
			'paused'             => null === $summary ? null : $this->connection->paused(),
			'workspace'          => $summary['tenant_name'] ?? null,
			'workspace_id'       => $summary['tenant_id'] ?? null,
			'environment'        => $summary['environment'] ?? null,
			'site'               => $summary['site'] ?? null,
			'connected_at'       => null === $summary ? null : gmdate( DATE_ATOM, $summary['connected_at'] ),
			'allowed_origins'    => $origins,
			'www_origin'         => $www,
			'www_allowed'        => null !== $www && in_array( $www, $origins, true ),
			'last_ping'          => null === $ping ? null : array(
				'delivered' => $ping['delivered'],
				'error'     => $ping['error'],
				'at'        => gmdate( DATE_ATOM, $ping['at'] ),
			),
			'workspace_settings' => null === $live ? null : WorkspaceSettings::shown( $live ),
		);
	}
}
