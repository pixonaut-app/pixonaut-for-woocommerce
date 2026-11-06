<?php
/**
 * The abilities that act on the store's connection to Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Connection\ConnectFailed;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use WP_Error;

/**
 * One of the connection card's actions, as an ability: choosing the
 * store's brand, allowing the site's `www.` counterpart, sending the
 * webhook endpoint a test request, or forgetting the connection a copied
 * site was copied with. Each acts through what the card's action does, and
 * answers with the connection as get-connection-status reads it. Connecting
 * and connecting again need a browser and are left to the card.
 */
final class ConnectionAction implements Ability {

	public const CHOOSE_BRAND = 'choose-brand';

	public const ALLOW_WWW = 'allow-www';

	public const PING_WEBHOOK = 'ping-webhook';

	public const FORGET_COPIED = 'forget-copied-connection';

	public const ACTIONS = array( self::CHOOSE_BRAND, self::ALLOW_WWW, self::PING_WEBHOOK, self::FORGET_COPIED );

	/**
	 * @param string            $action    One of ACTIONS.
	 * @param Connection        $connection The store's connection.
	 * @param Sdk               $api       `PATCH /v1/connect`, for the brand.
	 * @param Endpoint          $endpoint  The store's webhook endpoint.
	 * @param WorkspaceSettings $workspace Keeps the brand chosen and its accent colour.
	 * @param ConnectionStatus  $status    What each answers with.
	 */
	public function __construct(
		private readonly string $action,
		private readonly Connection $connection,
		private readonly Sdk $api,
		private readonly Endpoint $endpoint,
		private readonly WorkspaceSettings $workspace,
		private readonly ConnectionStatus $status,
	) {
	}

	public function name(): string {
		return $this->action;
	}

	public function definition(): array {
		return array(
			'label'               => $this->label(),
			'description'         => $this->description(),
			'input_schema'        => $this->input_schema(),
			'output_schema'       => ConnectionStatus::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ConnectionStatus::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => self::PING_WEBHOOK !== $this->action,
				'idempotent'  => self::PING_WEBHOOK !== $this->action,
			),
		);
	}

	/**
	 * @param mixed $input The action's input.
	 * @return array<string, mixed>|WP_Error The connection, or why the action was not done.
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input = is_array( $input ) ? $input : array();
		$done  = match ( $this->action ) {
			self::CHOOSE_BRAND => $this->choose_brand( $input['brand_id'] ?? null ),
			self::ALLOW_WWW => $this->allow_www( false !== ( $input['allow'] ?? true ) ),
			self::PING_WEBHOOK => $this->ping(),
			default => $this->forget_copied(),
		};
		if ( $done instanceof WP_Error ) {
			return $done;
		}
		// A change Pixonaut confirmed is answered as it confirmed it, not read again.
		return is_array( $done ) ? $this->status->of( $done ) : $this->status->execute();
	}

	/**
	 * @param mixed $brand A brand's id, or null for the workspace's default.
	 * @return array<string, mixed>|WP_Error The connection as Pixonaut answered the change.
	 */
	private function choose_brand( mixed $brand ): array|WP_Error {
		$working = $this->working();
		if ( null !== $working ) {
			return $working;
		}
		try {
			$answer = $this->api->connect()->update( new ConnectedStoreUpdateRequest( brandId: is_string( $brand ) && '' !== $brand ? $brand : null ) )->raw();
		} catch ( ApiException $e ) {
			return ErrorCode::BRAND_NOT_FOUND === $e->errorCode
				? new WP_Error( 'pixonaut_wc_ability_brand_not_found', __( 'That brand is not in the Pixonaut workspace, so the store\'s brand did not change. Reading the connection lists the brands.', 'pixonaut-for-woocommerce' ) )
				: self::failed( $e );
		}
		$this->workspace->remember( $answer );
		return $answer;
	}

	/**
	 * @param bool $allow Whether to allow the `www.` counterpart, or take it away.
	 * @return array<string, mixed>|WP_Error The connection as Pixonaut answered the change.
	 */
	private function allow_www( bool $allow ): array|WP_Error {
		$working = $this->working();
		if ( null !== $working ) {
			return $working;
		}
		if ( $allow && null === Connection::www_origin() ) {
			return new WP_Error( 'pixonaut_wc_ability_no_www', __( 'The site is on an IP address, so it has no www. counterpart to allow.', 'pixonaut-for-woocommerce' ) );
		}
		try {
			return $this->connection->allow_www( $allow );
		} catch ( ApiException $e ) {
			return self::failed( $e );
		}
	}

	private function ping(): ?WP_Error {
		$working = $this->working();
		$stored  = $this->connection->current();
		if ( null !== $working || null === $stored ) {
			return $working;
		}
		// How it went is kept, and get-connection-status answers with it.
		$this->endpoint->ping( $stored );
		return null;
	}

	private function forget_copied(): ?WP_Error {
		if ( Connection::PAUSED_SITE_CHANGED !== $this->connection->paused() ) {
			return new WP_Error( 'pixonaut_wc_ability_not_copied', __( 'The connection is not paused for a changed site address, so there is no copied connection to forget. Disconnecting is done on the connection card.', 'pixonaut-for-woocommerce' ) );
		}
		try {
			// A copied site's keys are the keys of the site it was copied from: they are forgotten here, never revoked.
			$this->connection->disconnect();
		} catch ( ConnectFailed $e ) {
			return new WP_Error( 'pixonaut_wc_ability_forget_failed', __( 'The store could not forget the connection now. Try again.', 'pixonaut-for-woocommerce' ) );
		}
		return null;
	}

	/**
	 * Why the action cannot be done with the connection as it is; null when it can.
	 */
	private function working(): ?WP_Error {
		if ( null === $this->connection->current() ) {
			return new WP_Error( 'pixonaut_wc_ability_not_connected', __( 'The store is not connected to Pixonaut. Connect it on the Pixonaut settings page, in a browser.', 'pixonaut-for-woocommerce' ) );
		}
		if ( null !== $this->connection->paused() ) {
			return new WP_Error( 'pixonaut_wc_ability_paused', __( 'The connection to Pixonaut is paused. Reading the connection says why.', 'pixonaut-for-woocommerce' ) );
		}
		return null;
	}

	/**
	 * @param ApiException $e Why Pixonaut did not make the change.
	 */
	private static function failed( ApiException $e ): WP_Error {
		/* translators: %s: the error code Pixonaut answered with, e.g. rate_limited. */
		return new WP_Error( 'pixonaut_wc_ability_connection_failed', sprintf( __( 'Pixonaut did not confirm the change (%s). Reading the connection shows it as it is.', 'pixonaut-for-woocommerce' ), $e->errorCode ) );
	}

	private function label(): string {
		return match ( $this->action ) {
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			self::CHOOSE_BRAND => __( 'Choose the store\'s Pixonaut brand', 'pixonaut-for-woocommerce' ),
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store; www. is the start of a web address. */
			self::ALLOW_WWW => __( 'Allow the site\'s www. address', 'pixonaut-for-woocommerce' ),
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			self::PING_WEBHOOK => __( 'Test the Pixonaut webhook', 'pixonaut-for-woocommerce' ),
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			default => __( 'Forget a copied site\'s Pixonaut connection', 'pixonaut-for-woocommerce' ),
		};
	}

	private function description(): string {
		return match ( $this->action ) {
			self::CHOOSE_BRAND => sprintf(
				/* translators: %s: the name of the ability that reads the connection. */
				__( 'Chooses the workspace brand the store\'s proofs, corrections and designer links are drawn in, as the connection card does: `brand_id`, one of the brands %s lists, or null to follow the workspace\'s default.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( ConnectionStatus::NAME )
			),
			self::ALLOW_WWW => __( 'Lets the store\'s publishable key take the site\'s counterpart with or without www. too, so the designer works on both addresses; `allow` false takes it away.', 'pixonaut-for-woocommerce' ),
			self::PING_WEBHOOK => __( 'Has Pixonaut send the store\'s webhook endpoint a test request, and answers with how it went under `last_ping`.', 'pixonaut-for-woocommerce' ),
			default => __( 'Forgets the connection a copied site was copied with, when the connection is paused because the site\'s address changed (`site_changed`). The site it was copied from stays connected. This site then connects on its own, in a browser.', 'pixonaut-for-woocommerce' ),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input_schema(): array {
		$properties = match ( $this->action ) {
			self::CHOOSE_BRAND => array(
				'brand_id' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'A brand\'s id; null follows the workspace\'s default brand.', 'pixonaut-for-woocommerce' ),
				),
			),
			self::ALLOW_WWW => array(
				'allow' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			default => array(),
		};
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( self::CHOOSE_BRAND === $this->action ) {
			$schema['required'] = array( 'brand_id' );
		} else {
			$schema['default'] = array();
		}
		return $schema;
	}
}
