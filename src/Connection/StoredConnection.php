<?php
/**
 * What the store holds about its connection.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

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
	 * @param array<string, mixed> $answer       The `201` body.
	 * @param string               $site         The `<site>` id minted for this connect.
	 * @param string               $home_url     `home_url()` now.
	 * @param int                  $connected_at Unix time.
	 *
	 * @throws UnexpectedValueException When a field is missing or not a string.
	 */
	public static function from_exchange( array $answer, string $site, string $home_url, int $connected_at ): self {
		$integration = self::object( $answer, 'integrationKey' );
		$publishable = self::object( $answer, 'publishableKey' );
		$webhook     = self::object( $answer, 'webhook' );
		return new self(
			$site,
			$home_url,
			$connected_at,
			self::string( $answer, 'tenantId' ),
			self::string( $answer, 'tenantName' ),
			self::string( $answer, 'environment' ),
			self::string( $answer, 'connectionId' ),
			self::string( $integration, 'key' ),
			self::string( $integration, 'id' ),
			self::string( $integration, 'label' ),
			self::string( $publishable, 'key' ),
			self::string( $publishable, 'id' ),
			self::strings( $publishable, 'allowedOrigins' ),
			self::string( $webhook, 'id' ),
			self::string( $webhook, 'url' ),
			self::string( $webhook, 'secret' ),
			self::optional_string( $answer, 'tenantSlug' ),
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

	/**
	 * @param array<string, mixed> $from Where to read.
	 * @param string               $name Field.
	 * @return array<string, mixed>
	 * @throws UnexpectedValueException When it is not an object.
	 */
	private static function object( array $from, string $name ): array {
		$value = $from[ $name ] ?? null;
		if ( ! is_array( $value ) ) {
			throw new UnexpectedValueException( sprintf( 'The connection has no %s.', $name ) );
		}
		return $value;
	}
}
