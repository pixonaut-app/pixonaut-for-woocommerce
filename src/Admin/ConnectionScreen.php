<?php
/**
 * The connection card on the Pixonaut settings page, and its actions.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Api\Answers\ConnectedStore;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Connection\ConnectFailed;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use RuntimeException;

/**
 * Connect, the return from the studio, disconnect, allowing the `www.`
 * counterpart, choosing the store's brand, the switch for Pixel's access to
 * the store and testing the webhook endpoint are `admin-post.php` actions.
 * Each ends by sending the admin back to the card with `RESULT_ARG`
 * naming what happened, which the card shows.
 */
final class ConnectionScreen {

	/**
	 * The settings field type the card renders as.
	 */
	public const FIELD_TYPE = 'pixonaut_connection';

	/**
	 * Where the guide on connecting the store explains developing locally.
	 */
	public const TUNNEL_DOCS_ANCHOR = '#develop-locally-with-a-tunnel';

	public const CONNECT_ACTION = 'pixonaut_wc_connect';

	public const DISCONNECT_ACTION = 'pixonaut_wc_disconnect';

	public const ALLOW_WWW_ACTION = 'pixonaut_wc_allow_www';

	public const PING_ACTION = 'pixonaut_wc_ping_webhook';

	public const BRAND_ACTION = 'pixonaut_wc_choose_brand';

	public const STORE_ACCESS_ACTION = 'pixonaut_wc_store_access';

	/**
	 * The field of the connect form and of the switch's form: `1` to allow
	 * Pixel to make changes to the store, `0` to stop it.
	 */
	public const STORE_ACCESS_ARG = 'store_access';

	/**
	 * The brand action's form field: a brand's id, or empty for the
	 * workspace's default brand.
	 */
	public const BRAND_ARG = 'brand';

	/**
	 * The connect link's query argument naming the side of the workspace to offer.
	 */
	public const ENVIRONMENT_ARG = 'environment';

	/**
	 * The `www.` action's query argument: `1` to allow it, `0` to take it away.
	 */
	public const ALLOW_ARG = 'allow';

	/**
	 * Query argument carrying the outcome back to the card.
	 */
	public const RESULT_ARG = 'pixonaut_connect';

	public const CONNECTED = 'connected';

	public const DISCONNECTED = 'disconnected';

	/**
	 * Disconnected here, but Pixonaut could not be told.
	 */
	public const DISCONNECTED_LOCALLY = 'disconnected_locally';

	/**
	 * A copied site forgot the connection it was copied with; the site it
	 * was copied from stays connected.
	 */
	public const FORGOTTEN = 'forgotten';

	public const WWW_ALLOWED = 'www_allowed';

	public const WWW_REMOVED = 'www_removed';

	/**
	 * Pixonaut did not change the origins.
	 */
	public const WWW_FAILED = 'www_failed';

	public const PINGED = 'pinged';

	public const STORE_ACCESS_ON = 'store_access_on';

	public const STORE_ACCESS_OFF = 'store_access_off';

	/**
	 * Turned off here, but Pixonaut could not be told.
	 */
	public const STORE_ACCESS_OFF_LOCALLY = 'store_access_off_locally';

	/**
	 * Pixonaut did not take the credential, so the switch stays off.
	 */
	public const STORE_ACCESS_FAILED = 'store_access_failed';

	/**
	 * Turning it off could not be saved, so the credential may still be valid.
	 */
	public const STORE_ACCESS_NOT_SAVED = 'store_access_not_saved';

	/**
	 * The store connected, but the access the admin allowed could not be handed over.
	 */
	public const CONNECTED_WITHOUT_STORE_ACCESS = 'connected_without_store_access';

	public const BRAND_CHOSEN = 'brand_chosen';

	/**
	 * The brand chosen is no longer one of the workspace's.
	 */
	public const BRAND_NOT_FOUND = 'brand_not_found';

	/**
	 * Pixonaut refused the brand, or could not be reached or answered
	 * unreadably, so the change may or may not have been made.
	 */
	public const BRAND_FAILED = 'brand_failed';

	/**
	 * @param Connection             $connection   The connection.
	 * @param Sdk                    $api          `GET /v1/connect`, for the card.
	 * @param Endpoint               $endpoint     The store's webhook endpoint.
	 * @param Deprecations           $deprecations Deprecations Pixonaut announced.
	 * @param Templates              $templates    Renders the card.
	 * @param string                 $settings_url The settings page's tab with the card.
	 * @param WorkspaceSettings|null $workspace Keeps the store's brand and accent colour the card reads or chooses; null keeps nothing.
	 * @param StoreAccess|null       $store_access The switch for Pixel's access to the store; null shows none.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $api,
		private readonly Endpoint $endpoint,
		private readonly Deprecations $deprecations,
		private readonly Templates $templates,
		private readonly string $settings_url,
		private readonly ?WorkspaceSettings $workspace = null,
		private readonly ?StoreAccess $store_access = null,
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . Connection::RETURN_ACTION, array( $this, 'handle_return' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::ALLOW_WWW_ACTION, array( $this, 'handle_allow_www' ) );
		add_action( 'admin_post_' . self::PING_ACTION, array( $this, 'handle_ping' ) );
		add_action( 'admin_post_' . self::BRAND_ACTION, array( $this, 'handle_choose_brand' ) );
		add_action( 'admin_post_' . self::STORE_ACCESS_ACTION, array( $this, 'handle_store_access' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
	}

	/**
	 * The settings page's tab with the card.
	 */
	public static function settings_url(): string {
		return SettingsPage::url( SettingsPage::CONNECTION );
	}

	/**
	 * The card's settings field, for the settings page.
	 *
	 * @return array<string, mixed>
	 */
	public static function field(): array {
		return array(
			'id'        => 'pixonaut_wc_connection',
			'type'      => self::FIELD_TYPE,
			'is_option' => false,
		);
	}

	public function render(): void {
		$this->templates->render( 'admin/connection-card.php', $this->card() );
	}

	/**
	 * What the card shows.
	 *
	 * @return array<string, mixed>
	 */
	public function card(): array {
		$stored  = $this->connection->current();
		$paused  = $this->connection->paused();
		$state   = null === $stored ? 'disconnected' : 'connected';
		$live    = null;
		$webhook = null;
		if ( null !== $stored && null === $paused ) {
			try {
				$live = ConnectedStore::get( $this->api );
				$this->workspace?->remember( $live );
			} catch ( ApiException $e ) {
				// A 401 has paused the connection by now; anything else leaves it as it was.
				$state = 'unreachable';
			}
			if ( 'connected' === $state && null === $this->connection->paused() ) {
				// Read before the state is settled: a 401 here pauses the connection too.
				$webhook = $this->webhook_health( $stored );
			}
		}
		$paused = $this->connection->paused();
		if ( null !== $paused ) {
			$state   = 'paused';
			$webhook = null;
		}
		$origins   = null !== $live ? $live->allowed_origins : ( $stored->allowed_origins ?? array() );
		$www       = Connection::www_origin();
		$connected = 'connected' === $state;

		return array(
			'state'              => $state,
			'paused_reason'      => $paused ?? '',
			'name'               => $live->name ?? $stored->integration_key_label ?? '',
			'workspace'          => $live->tenant_name ?? $stored->tenant_name ?? '',
			'environment'        => $live->environment ?? $stored->environment ?? '',
			'site_url'           => $live->site_url ?? $stored->home_url ?? '',
			'origins'            => $origins,
			'www_origin'         => $connected && null !== $www ? $www : '',
			'www_allowed'        => null !== $www && in_array( $www, $origins, true ),
			'www_url'            => $this->action_url( self::ALLOW_WWW_ACTION ),
			'brand_url'          => $connected ? $this->action_url( self::BRAND_ACTION ) : '',
			'store_access'       => null === $this->store_access ? null : array(
				'on'  => $this->store_access->is_on(),
				'cut' => $this->store_access->is_cut(),
				// Unreachable still takes it off: that needs nothing from Pixonaut.
				'url' => $connected || 'unreachable' === $state ? $this->action_url( self::STORE_ACCESS_ACTION ) : '',
			),
			'webhook'            => $connected ? $webhook : null,
			'webhook_reachable'  => ! $connected || false !== $live?->reachable,
			'last_ping'          => null === $stored || null !== $paused ? null : $this->endpoint->last_ping(),
			'ping_url'           => $connected ? $this->action_url( self::PING_ACTION ) : '',
			'can_connect'        => $this->connection->can_connect(),
			'home_origin'        => Connection::origin( home_url() ),
			'admin_origin'       => Connection::origin( admin_url() ),
			'connect_url'        => $this->action_url( self::CONNECT_ACTION ),
			'connect_urls'       => array_combine(
				Connection::ENVIRONMENTS,
				array_map( fn ( string $environment ): string => add_query_arg( self::ENVIRONMENT_ARG, $environment, $this->action_url( self::CONNECT_ACTION ) ), Connection::ENVIRONMENTS )
			),
			'disconnect_url'     => $this->action_url( self::DISCONNECT_ACTION ),
			'studio_url'         => Hosts::studio(),
			'docs_url'           => HelpLinks::docs( HelpLinks::CONNECT ),
			'tunnel_docs_url'    => HelpLinks::docs( HelpLinks::CONNECT ) . self::TUNNEL_DOCS_ANCHOR,
			'custom_hosts'       => Hosts::custom(),
			'result'             => $this->result(),
			'deprecations'       => $this->deprecations->all(),
			// Read only: what Pixonaut answered of the workspace's settings, null when it could not be read.
			'workspace_settings' => null === $live ? null : WorkspaceSettings::shown( $live ),
		);
	}

	/**
	 * The endpoint's health, or null when Pixonaut could not say.
	 *
	 * @param StoredConnection $stored The connection.
	 *
	 * @return array{active: bool, failing: bool, failed_count: int, url: string}|null
	 */
	private function webhook_health( StoredConnection $stored ): ?array {
		try {
			return $this->endpoint->health( $stored );
		} catch ( ApiException $e ) {
			return null;
		}
	}

	public function handle_connect(): void {
		check_admin_referer( self::CONNECT_ACTION );
		$this->require_capability();
		$environment  = isset( $_GET[ self::ENVIRONMENT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ENVIRONMENT_ARG ] ) ) : '';
		$store_access = isset( $_POST[ self::STORE_ACCESS_ARG ] ) && '1' === sanitize_key( wp_unslash( $_POST[ self::STORE_ACCESS_ARG ] ) );
		try {
			$url = $this->connection->start( get_current_user_id(), $environment, $store_access );
		} catch ( ConnectFailed $e ) {
			$this->back( $e->reason );
		}
		$this->redirect_to_studio( $url );
	}

	/**
	 * The studio's return. Its `state`, single use and bound to the admin
	 * who started the connect, stands in for a nonce.
	 */
	public function handle_return(): void {
		$this->require_capability();
		$query = array();
		foreach ( array( 'state', 'code', 'error' ) as $name ) {
			if ( isset( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by `state`.
				$query[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by `state`.
			}
		}
		try {
			$this->connection->complete( get_current_user_id(), $query );
		} catch ( ConnectFailed $e ) {
			$this->back( $e->reason );
		}
		$this->back( true === $this->store_access?->failed() ? self::CONNECTED_WITHOUT_STORE_ACCESS : self::CONNECTED );
	}

	public function handle_disconnect(): void {
		check_admin_referer( self::DISCONNECT_ACTION );
		$this->require_capability();
		$copied = Connection::PAUSED_SITE_CHANGED === $this->connection->paused();
		try {
			$revoked = $this->connection->disconnect();
		} catch ( ConnectFailed $e ) {
			$this->back( $e->reason );
		}
		if ( $copied ) {
			$this->back( self::FORGOTTEN );
		}
		$this->back( $revoked ? self::DISCONNECTED : self::DISCONNECTED_LOCALLY );
	}

	public function handle_allow_www(): void {
		check_admin_referer( self::ALLOW_WWW_ACTION );
		$this->require_capability();
		$allow = isset( $_GET[ self::ALLOW_ARG ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::ALLOW_ARG ] ) );
		try {
			$this->connection->allow_www( $allow );
		} catch ( ApiException $e ) {
			$this->back( self::WWW_FAILED );
		}
		$this->back( $allow ? self::WWW_ALLOWED : self::WWW_REMOVED );
	}

	/**
	 * Sets the brand the store's hosted pages are drawn in, and keeps it
	 * with its accent colour, which the designer takes when its own accent
	 * is set to none.
	 */
	public function handle_choose_brand(): void {
		check_admin_referer( self::BRAND_ACTION );
		$this->require_capability();
		if ( null === $this->connection->current() || null !== $this->connection->paused() ) {
			$this->back( self::BRAND_FAILED );
		}
		$brand = isset( $_POST[ self::BRAND_ARG ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::BRAND_ARG ] ) ) : '';
		try {
			$answer = ConnectedStore::update( $this->api, new ConnectedStoreUpdateRequest( brandId: '' === $brand ? null : $brand ) );
		} catch ( ApiException $e ) {
			$this->back( ErrorCode::BRAND_NOT_FOUND === $e->errorCode ? self::BRAND_NOT_FOUND : self::BRAND_FAILED );
		}
		$this->workspace?->remember( $answer );
		$this->back( self::BRAND_CHOSEN );
	}

	/**
	 * The switch: turning it off works whatever Pixonaut answers.
	 */
	public function handle_store_access(): void {
		check_admin_referer( self::STORE_ACCESS_ACTION );
		$this->require_capability();
		if ( null === $this->store_access || null === $this->connection->current() || null !== $this->connection->paused() ) {
			$this->back( self::STORE_ACCESS_FAILED );
		}
		if ( isset( $_POST[ self::STORE_ACCESS_ARG ] ) && '1' === sanitize_key( wp_unslash( $_POST[ self::STORE_ACCESS_ARG ] ) ) ) {
			$this->back( $this->store_access->turn_on() ? self::STORE_ACCESS_ON : self::STORE_ACCESS_FAILED );
		}
		try {
			$told = $this->store_access->turn_off();
		} catch ( RuntimeException $e ) {
			$this->back( self::STORE_ACCESS_NOT_SAVED );
		}
		$this->back( $told ? self::STORE_ACCESS_OFF : self::STORE_ACCESS_OFF_LOCALLY );
	}

	public function handle_ping(): void {
		check_admin_referer( self::PING_ACTION );
		$this->require_capability();
		$stored = $this->connection->current();
		if ( null !== $stored && null === $this->connection->paused() ) {
			$this->endpoint->ping( $stored );
		}
		$this->back( self::PINGED );
	}

	/**
	 * The outcome the last action sent back, as a key.
	 */
	private function result(): string {
		return isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/**
	 * @param string $action An `admin-post.php` action.
	 */
	private function action_url( string $action ): string {
		return wp_nonce_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ), $action );
	}

	private function require_capability(): void {
		if ( ! current_user_can( Notices::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change the Pixonaut connection.', 'pixonaut-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * @param string $result What happened, for the card.
	 */
	private function back( string $result ): never {
		wp_safe_redirect( add_query_arg( self::RESULT_ARG, $result, $this->settings_url ) );
		exit;
	}

	/**
	 * @param string $url The consent screen.
	 */
	private function redirect_to_studio( string $url ): never {
		$studio = (string) wp_parse_url( Hosts::studio(), PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			static fn ( array $hosts ): array => array_merge( $hosts, array( $studio ) )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
