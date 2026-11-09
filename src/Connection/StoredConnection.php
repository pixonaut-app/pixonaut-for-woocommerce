<?php
/**
 * What the store holds about its connection.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreExchange;
use UnexpectedValueException;

/**
 * The keys and ids `POST /v1/connect/token` answered, with the `<site>` id
 * minted for this connection and the address the store connected from. Kept
 * only encrypted (`ConnectionStore`).
 */
final class StoredConnection {

	public const LIVE = 'live';

	public const SANDBOX = 'sandbox';

	/**
	 * @param string   $site                  UUID minted at connect; `<site>` in refs and idempotency keys. New on every connect.
	 * @param string   $home_url              `home_url()` when the store connected.
	 * @param int      $connected_at          Unix time of the connect.
	 * @param string   $tenant_id             Workspace id.
	 * @param string   $tenant_name           Workspace name.
	 * @param string   $environment           `live` or `sandbox`; an open set.
	 * @param string   $connection_id         Pixonaut's id for this connection.
	 * @param string   $integration_key       The `ik_` key; secret.
	 * @param string   $integration_key_id    Its id.
	 * @param string   $integration_key_label Its name when it was issued.
	 * @param string   $publishable_key       The `pk_` key.
	 * @param string   $publishable_key_id    Its id.
	 * @param string[] $allowed_origins       Origins the publishable key was bound to.
	 * @param string   $webhook_id            The webhook endpoint's id.
	 * @param string   $webhook_url           Where Pixonaut delivers webhooks.
	 * @param string   $webhook_secret        Their signing secret; secret.
	 * @param ?string  $tenant_slug           The workspace's slug, which the studio's pages sit under; null for a connection made before Pixonaut answered one.
	 * @phpstan-param list<string> $allowed_origins
	 */
	public function __construct(
		public readonly string $site,
		public readonly string $home_url,
		public readonly int $connected_at,
		public readonly string $tenant_id,
		public readonly string $tenant_name,
		public readonly string $environment,
		public readonly string $connection_id,
		public readonly string $integration_key,
		public readonly string $integration_key_id,
		public readonly string $integration_key_label,
		public readonly string $publishable_key,
		public readonly string $publishable_key_id,
		public readonly array $allowed_origins,
		public readonly string $webhook_id,
		public readonly string $webhook_url,
		public readonly string $webhook_secret,
		public readonly ?string $tenant_slug = null,
	) {
	}

	/**
	 * From the `POST /v1/connect/token` answer.
	 *
	 * @param ConnectedStoreExchange $answer       The `201` answer.
	 * @param string                 $site         The `<site>` id minted for this connect.
	 * @param string                 $home_url     `home_url()` now.
	 * @param int                    $connected_at Unix time.
	 *
	 * @throws UnexpectedValueException When a key or id is empty.
	 */
	public static function from_exchange( ConnectedStoreExchange $answer, string $site, string $home_url, int $connected_at ): self {
		return new self(
			$site,
			$home_url,
			$connected_at,
			self::filled( $answer->tenant_id, 'tenantId' ),
			self::filled( $answer->tenant_name, 'tenantName' ),
			self::filled( $answer->environment, 'environment' ),
			self::filled( $answer->connection_id, 'connectionId' ),
			self::filled( $answer->integration_key->key, 'key' ),
			self::filled( $answer->integration_key->id, 'id' ),
			self::filled( $answer->integration_key->label, 'label' ),
			self::filled( $answer->publishable_key->key, 'key' ),
			self::filled( $answer->publishable_key->id, 'id' ),
			$answer->publishable_key->allowed_origins,
			self::filled( $answer->webhook->id, 'id' ),
			self::filled( $answer->webhook->url, 'url' ),
			self::filled( $answer->webhook->secret, 'secret' ),
			'' === $answer->tenant_slug ? null : $answer->tenant_slug,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * @param array<string, mixed> $stored From `to_array()`.
	 * @throws UnexpectedValueException When a field is missing or of the wrong type.
	 */
	public static function from_array( array $stored ): self {
		if ( ! is_int( $stored['connected_at'] ?? null ) ) {
			throw new UnexpectedValueException( 'The stored connection has no connected_at.' );
		}
		return new self(
			self::string( $stored, 'site' ),
			self::string( $stored, 'home_url' ),
			$stored['connected_at'],
			self::string( $stored, 'tenant_id' ),
			self::string( $stored, 'tenant_name' ),
			self::string( $stored, 'environment' ),
			self::string( $stored, 'connection_id' ),
			self::string( $stored, 'integration_key' ),
			self::string( $stored, 'integration_key_id' ),
			self::string( $stored, 'integration_key_label' ),
			self::string( $stored, 'publishable_key' ),
			self::string( $stored, 'publishable_key_id' ),
			self::strings( $stored, 'allowed_origins' ),
			self::string( $stored, 'webhook_id' ),
			self::string( $stored, 'webhook_url' ),
			self::string( $stored, 'webhook_secret' ),
			self::optional_string( $stored, 'tenant_slug' ),
		);
	}

	/**
	 * What may be shown or passed to hooks: everything but the secrets.
	 *
	 * @return array{site: string, home_url: string, connected_at: int, tenant_id: string, tenant_name: string, environment: string, connection_id: string, integration_key_label: string}
	 */
	public function summary(): array {
		return array(
			'site'                  => $this->site,
			'home_url'              => $this->home_url,
			'connected_at'          => $this->connected_at,
			'tenant_id'             => $this->tenant_id,
			'tenant_name'           => $this->tenant_name,
			'environment'           => $this->environment,
			'connection_id'         => $this->connection_id,
			'integration_key_label' => $this->integration_key_label,
		);
	}

	/**
	 * @param array<string, mixed> $from Where to read.
	 * @param string               $name Field.
	 * @throws UnexpectedValueException When it is missing or empty.
	 */
	private static function string( array $from, string $name ): string {
		$value = $from[ $name ] ?? null;
		if ( ! is_string( $value ) || '' === $value ) {
			throw new UnexpectedValueException( sprintf( 'The connection has no %s.', $name ) );
		}
		return $value;
	}

	/**
	 * @param string $value A field of the connect answer.
	 * @param string $name  Its name.
	 * @throws UnexpectedValueException When it is empty.
	 */
	private static function filled( string $value, string $name ): string {
		if ( '' === $value ) {
			throw new UnexpectedValueException( sprintf( 'The connection has no %s.', $name ) );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $from Where to read.
	 * @param string               $name Field.
	 * @return string|null Its value; null when it is missing, empty or not a string.
	 */
	private static function optional_string( array $from, string $name ): ?string {
		$value = $from[ $name ] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * @param array<string, mixed> $from Where to read.
	 * @param string               $name Field.
	 * @return list<string>
	 * @throws UnexpectedValueException When it is not a list of strings.
	 */
	private static function strings( array $from, string $name ): array {
		$value = $from[ $name ] ?? null;
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() !== array_filter( $value, static fn ( $item ): bool => ! is_string( $item ) ) ) {
			throw new UnexpectedValueException( sprintf( 'The connection has no list %s.', $name ) );
		}
		return $value;
	}
}
