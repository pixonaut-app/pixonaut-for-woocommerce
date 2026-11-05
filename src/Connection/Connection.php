<?php
/**
 * Connecting the store to Pixonaut, and disconnecting it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Closure;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Api\Operations;
use RuntimeException;
use UnexpectedValueException;

/**
 * The connect flow is OAuth's authorization code flow with PKCE: the admin
 * is sent to the studio's consent screen with a challenge, comes back with a
 * code, and the store exchanges code and verifier for its keys, which it
 * stores in one encrypted write. A `401` on any call pauses the connection
 * until the store connects again.
 */
final class Connection {

	/**
	 * The notice shown while the connection is paused.
	 */
	public const PAUSED_NOTICE = 'connection_paused';

	/**
	 * Pixonaut refused the store's key.
	 */
	public const PAUSED_UNAUTHORIZED = 'unauthorized';

	/**
	 * The site is not the one that connected: its address changed, or its
	 * stored keys cannot be decrypted here. Detected, never written.
	 */
	public const PAUSED_SITE_CHANGED = ConnectionStore::PAUSED_SITE_CHANGED;

	/**
	 * The sides of a workspace a connect may be limited to.
	 */
	public const ENVIRONMENTS = array( StoredConnection::LIVE, StoredConnection::SANDBOX );

	/**
	 * What the studio's consent screen is told the store runs.
	 */
	public const CLIENT = 'woocommerce';

	/**
	 * The `admin-post.php` action the studio sends the admin back to.
	 */
	public const RETURN_ACTION = 'pixonaut_wc_connect_return';

	/**
	 * The host prefix of a site's counterpart.
	 */
	private const WWW = 'www.';

	/**
	 * What the site's name is decoded to; Pixonaut takes UTF-8.
	 */
	private const CHARSET = 'UTF-8';

	private readonly Closure $clock;

	/**
	 * @param ConnectionStore  $store    The stored connection.
	 * @param ConnectAttempts  $attempts Connects in progress.
	 * @param Connect          $api      `/v1/connect` operations.
	 * @param Notices          $notices  Admin notices.
	 * @param Logger           $logger   Logs why a connect or a revoke failed; never keys or codes.
	 * @param string           $settings_url The screen with the connection card, for the paused notice.
	 * @param Closure|null     $clock    `fn(): int` Unix time; `time()` when null.
	 * @param StoreAccess|null $store_access Turned on when the admin who connects allowed it; null never turns it on.
	 */
	public function __construct(
		private readonly ConnectionStore $store,
		private readonly ConnectAttempts $attempts,
		private readonly Connect $api,
		private readonly Notices $notices,
		private readonly Logger $logger,
		private readonly string $settings_url,
		?Closure $clock = null,
		private readonly ?StoreAccess $store_access = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'pixonaut_wc_api_unauthorized', array( $this, 'on_unauthorized' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'sync_site_pause' ), 10, 0 );
	}

	/**
	 * The stored connection, paused or not; null when not connected.
	 */
	public function current(): ?StoredConnection {
		return $this->store->load();
	}

	/**
	 * Why the connection is paused, or null when it is not or the store is
	 * not connected.
	 */
	public function paused(): ?string {
		return $this->store->paused();
	}

	/**
	 * Whether the site can be connected now. Pixonaut sends the admin back
	 * to `wp-admin/` and registers the webhook endpoint under the REST API,
	 * and takes both only on the origin of the site's own address.
	 */
	public function can_connect(): bool {
		$home = self::origin( home_url() );
		return self::origin( admin_url() ) === $home && self::origin( rest_url() ) === $home;
	}

	/**
	 * Starts a connect for an admin.
	 *
	 * @param int    $user_id     The admin.
	 * @param string $environment `live` or `sandbox` to offer only that side of the workspace; anything else lets the member pick.
	 * @param bool   $store_access Whether the admin allowed Pixel to make changes to the store, once it is connected.
	 * @return string The studio's consent screen to send them to.
	 *
	 * @throws ConnectFailed When the site cannot be connected.
	 */
	public function start( int $user_id, string $environment = '', bool $store_access = false ): string {
		if ( ! $this->can_connect() ) {
			throw new ConnectFailed( ConnectFailed::ORIGIN_MISMATCH );
		}
		$redirect_uri = self::redirect_uri();
		$attempt      = $this->attempts->begin( $user_id, $redirect_uri, ( $this->clock )(), $store_access );
		$args         = array(
			'client'                => self::CLIENT,
			'site_url'              => self::site_url(),
			'redirect_uri'          => $redirect_uri,
			'state'                 => $attempt['state'],
			'code_challenge'        => Pkce::challenge( $attempt['verifier'] ),
			'code_challenge_method' => Pkce::METHOD,
			// `?rest_route=/` under plain permalinks, which do not serve `/wp-json/`.
			'rest_url'              => rest_url(),
		);
		$name         = self::site_name();
		if ( '' !== $name ) {
			$args['site_name'] = $name;
		}
		if ( in_array( $environment, self::ENVIRONMENTS, true ) ) {
			$args['environment'] = $environment;
		}

		/**
		 * Filters the query sent to the studio's consent screen when a
		 * store connects.
		 *
		 * `redirect_uri`, `state` and the PKCE challenge are checked when
		 * the admin returns; change them and the connect fails. A changed
		 * `site_url` connects the store under an address the plugin does
		 * not store, and a changed `rest_url` registers the webhook endpoint
		 * where the store does not receive, so leave them too. `site_name`
		 * (the store's name in the key's name) and `environment` (`live` or
		 * `sandbox`, to offer only one; set when the admin chose one) are
		 * the ones worth changing.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $args Query parameters.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_connection_connect_args', function ( $args ) {
		 *     if ( 'staging' === wp_get_environment_type() ) {
		 *         $args['environment'] = 'sandbox';
		 *     }
		 *     return $args;
		 * } );
		 */
		$args = (array) apply_filters( 'pixonaut_wc_connection_connect_args', $args );

		return Hosts::studio() . '/connect?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Finishes a connect when the studio sends the admin back.
	 *
	 * @param int                   $user_id The admin the return came to.
	 * @param array<string, string> $query   The return's `state`, and `code` or `error`.
	 *
	 * @throws ConnectFailed When it does not connect the store; what was stored stays.
	 */
	public function complete( int $user_id, array $query ): StoredConnection {
		$now     = ( $this->clock )();
		$attempt = $this->attempts->take( $user_id, $query['state'] ?? '', $now );
		if ( null === $attempt ) {
			throw new ConnectFailed( ConnectFailed::INVALID_STATE );
		}
		if ( isset( $query['error'] ) ) {
			throw new ConnectFailed( ConnectFailed::ACCESS_DENIED === $query['error'] ? ConnectFailed::ACCESS_DENIED : ConnectFailed::REFUSED );
		}
		$code = $query['code'] ?? '';
		if ( '' === $code ) {
			throw new ConnectFailed( ConnectFailed::REFUSED );
		}

		if ( ! $this->store->lock() ) {
			throw new ConnectFailed( ConnectFailed::BUSY );
		}
		try {
			$connection = $this->exchange( $code, $attempt, $now );
			try {
				$this->store->save( $connection );
			} catch ( RuntimeException $e ) {
				throw new ConnectFailed( ConnectFailed::STORAGE_FAILED, $e );
			}
		} finally {
			$this->store->unlock();
		}
		$this->notices->remove( self::PAUSED_NOTICE );

		/**
		 * Fires after the store connected to Pixonaut, or connected again.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $connection `site`, `home_url`, `connected_at`, `tenant_id`, `tenant_name`, `environment`, `connection_id`, `integration_key_label`; no keys.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_connection_connected', function ( $connection ) {
		 *     error_log( 'Connected to ' . $connection['tenant_name'] . ' (' . $connection['environment'] . ')' );
		 * } );
		 */
		do_action( 'pixonaut_wc_connection_connected', $connection->summary() );

		if ( $attempt['store_access'] ) {
			$this->store_access?->turn_on();
		}

		return $connection;
	}

	/**
	 * @param string                                                            $code    The return's code.
	 * @param array{verifier: string, redirect_uri: string, store_access: bool} $attempt The connect it finishes.
	 * @param int                                                               $now     Unix time.
	 * @return StoredConnection The connection.
	 *
	 * @throws ConnectFailed When Pixonaut did not hand over the keys.
	 */
	private function exchange( string $code, array $attempt, int $now ): StoredConnection {
		try {
			return StoredConnection::from_exchange( $this->api->exchange( $code, $attempt['verifier'], $attempt['redirect_uri'] ), wp_generate_uuid4(), self::site_url(), $now );
		} catch ( ApiError $e ) {
			$this->logger->warning(
				sprintf( 'Pixonaut did not hand over the store\'s keys: %s.', $e->getMessage() ),
				array(
					'operation'  => $e->operation,
					'status'     => $e->status,
					'error_code' => $e->error_code,
				)
			);
			throw new ConnectFailed( ErrorCodes::PLAN_LIMIT_REACHED === $e->error_code ? ConnectFailed::PLAN_LIMIT_REACHED : ConnectFailed::EXCHANGE_FAILED, $e );
		} catch ( UnexpectedValueException $e ) {
			// The message names the missing field, never a value.
			$this->logger->warning(
				sprintf( 'Pixonaut handed over keys the plugin cannot use: %s', $e->getMessage() ),
				array( 'operation' => Operations::EXCHANGE_CONNECT_CODE )
			);
			throw new ConnectFailed( ConnectFailed::EXCHANGE_FAILED, $e );
		}
	}

	/**
	 * Disconnects the store: revokes its keys in Pixonaut, then forgets
	 * them. They are forgotten even when Pixonaut cannot be reached.
	 *
	 * @return bool Whether Pixonaut revoked them, or they no longer worked.
	 *
	 * @throws ConnectFailed When another request is connecting or disconnecting, or the store could not forget them.
	 */
	public function disconnect(): bool {
		if ( ! $this->store->lock() ) {
			throw new ConnectFailed( ConnectFailed::BUSY );
		}
		try {
			$revoked = $this->revoke();
			if ( ! $this->store->clear() ) {
				throw new ConnectFailed( ConnectFailed::DISCONNECT_FAILED );
			}
		} finally {
			$this->store->unlock();
		}
		$this->notices->remove( self::PAUSED_NOTICE );

		/**
		 * Fires after the store disconnected from Pixonaut and forgot its keys.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $revoked Whether Pixonaut revoked the keys; false when it could not be reached.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_connection_disconnected', function ( $revoked ) {
		 *     if ( ! $revoked ) {
		 *         error_log( 'Remove the store in the Pixonaut studio too.' );
		 *     }
		 * } );
		 */
		do_action( 'pixonaut_wc_connection_disconnected', $revoked );

		return $revoked;
	}

	/**
	 * The site's counterpart with or without `www.`, same scheme and port:
	 * on the site's own domain, so the store adds it to its publishable key
	 * itself. Null for a site on an IP address.
	 */
	public static function www_origin(): ?string {
		$origin = self::origin( self::site_url() );
		$host   = (string) wp_parse_url( $origin, PHP_URL_HOST );
		// An IPv6 host keeps its brackets: `[::1]`.
		if ( '' === $host || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$other = str_starts_with( $host, self::WWW ) ? substr( $host, strlen( self::WWW ) ) : self::WWW . $host;
		return str_replace( '://' . $host, '://' . $other, $origin );
	}

	/**
	 * Lets the publishable key take the site's `www.` counterpart too, or
	 * takes it away.
	 *
	 * @param bool $allow Whether to allow it.
	 *
	 * @return array<string, mixed> The connection as `PATCH /v1/connect` answers it: `allowedOrigins`, the workspace settings, ….
	 *
	 * @throws ApiError When Pixonaut refused (`400 invalid_origin`), could not be reached, or the store is not connected.
	 */
	public function allow_www( bool $allow ): array {
		$other = self::www_origin();
		return $this->api->allow_origins( $allow && null !== $other ? array( $other ) : array() );
	}

	/**
	 * Asks Pixonaut to revoke the stored keys.
	 *
	 * @return bool Whether it did, or they no longer worked.
	 */
	private function revoke(): bool {
		// Never while paused: a refused key is revoked already, and a copied
		// site's keys are the keys of the site it was copied from.
		if ( null === $this->current() || null !== $this->store->paused() ) {
			return true;
		}
		try {
			$this->api->delete();
		} catch ( ApiError $e ) {
			// A key Pixonaut refuses is already revoked.
			if ( 401 === $e->status ) {
				return true;
			}
			$this->logger->warning(
				sprintf( 'Pixonaut did not revoke the store\'s keys on disconnect: %s.', $e->getMessage() ),
				array(
					'operation'  => $e->operation,
					'status'     => $e->status,
					'error_code' => $e->error_code,
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Pauses the connection: the client sends nothing until the store
	 * connects again, and every admin screen says so.
	 *
	 * @param string $reason Why, e.g. `PAUSED_UNAUTHORIZED`.
	 */
	public function pause( string $reason ): void {
		if ( null === $this->current() || null !== $this->store->paused() ) {
			return;
		}
		$this->store->pause( $reason );
		$this->announce_pause( $reason );
	}

	/**
	 * Pauses the connection whose key Pixonaut just refused, unless another
	 * has replaced it since.
	 */
	public function on_unauthorized(): void {
		$site = $this->store->credential_site();
		if ( null === $site ) {
			return;
		}
		if ( $this->store->pause_if_current( $site, self::PAUSED_UNAUTHORIZED ) ) {
			$this->announce_pause( self::PAUSED_UNAUTHORIZED );
		}
	}

	/**
	 * Raises the paused notice while the site is not the one that
	 * connected, and takes it away once it is again. Nothing is written to
	 * the connection: putting the site's address back lifts the pause.
	 */
	public function sync_site_pause(): void {
		$paused = $this->paused();
		if ( self::PAUSED_SITE_CHANGED === $paused ) {
			if ( ! $this->notices->has( self::PAUSED_NOTICE ) ) {
				$this->announce_pause( $paused );
			}
			return;
		}
		if ( null === $paused ) {
			$this->notices->remove( self::PAUSED_NOTICE );
		}
	}

	/**
	 * Says the connection is paused: on every admin screen, and to hooks.
	 *
	 * @param string $reason Why.
	 */
	private function announce_pause( string $reason ): void {
		$message = self::PAUSED_SITE_CHANGED === $reason
			/* translators: %s: link to the Pixonaut settings. */
			? __( 'Site URL changed: this site is not the one that connected to Pixonaut, so Pixonaut is paused here and nothing is sent for the site it was copied from. Personalised products and orders are paused until you <a href="%s">connect this site</a>. For a staging site, pick Sandbox.', 'pixonaut-for-woocommerce' )
			/* translators: %s: link to the Pixonaut settings. */
			: __( 'Pixonaut no longer accepts this store\'s key: the store was disconnected in Pixonaut, or its key was revoked. Personalised products and orders are paused until you <a href="%s">connect the store again</a>.', 'pixonaut-for-woocommerce' );
		$this->notices->add( self::PAUSED_NOTICE, sprintf( $message, esc_url( $this->settings_url ) ), 'error', false );

		/**
		 * Fires when the connection was paused.
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason Why: `unauthorized` when Pixonaut refused the store's key, `site_changed` when the site is not the one that connected.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_connection_paused', function ( $reason ) {
		 *     wp_mail( get_option( 'admin_email' ), 'Pixonaut paused', $reason );
		 * } );
		 */
		do_action( 'pixonaut_wc_connection_paused', $reason );
	}

	/**
	 * The site's name as text, which Pixonaut names the store's key after.
	 * WordPress keeps it HTML-escaped (`Acme &amp; Co`), and Pixonaut
	 * escapes what it shows itself, so it is sent decoded.
	 */
	public static function site_name(): string {
		return html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, self::CHARSET );
	}

	/**
	 * The store's address as Pixonaut knows it: `home_url()`, no trailing slash.
	 */
	public static function site_url(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * `scheme://host[:port]` of a URL, the host in lower case; empty when it
	 * has no scheme or host.
	 *
	 * @param string $url An absolute URL.
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . $port;
	}

	/**
	 * Where the studio sends the admin back.
	 */
	public static function redirect_uri(): string {
		return add_query_arg( 'action', self::RETURN_ACTION, admin_url( 'admin-post.php' ) );
	}
}
