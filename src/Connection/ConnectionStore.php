<?php
/**
 * Where the connection is kept.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Closure;
use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\CredentialProvider;
use Pixonaut\WooCommerce\Schema;
use RuntimeException;
use UnexpectedValueException;

/**
 * One option: the connection encrypted as a whole, so it is written in one
 * go, and the reason it is paused, if it is. A paused connection hands the
 * API client no credential, so nothing reaches Pixonaut until the store
 * connects again.
 *
 * A connection is also paused, without a write, while the site is not the one
 * that connected: its address is not the one stored, or what is stored cannot
 * be decrypted with its salts. That is a copy of the database, a staging site
 * for one, and its calls would act for the site it was copied from.
 */
final class ConnectionStore implements CredentialProvider {

	public const OPTION = Schema::CONNECTION_OPTION;

	public const LOCK_OPTION = Schema::CONNECTION_LOCK_OPTION;

	/**
	 * A lock older than this was left by a request that died; a connect or
	 * disconnect takes well under a minute.
	 */
	public const LOCK_TTL_SECONDS = 60;

	/**
	 * The site is not the one that connected.
	 */
	public const PAUSED_SITE_CHANGED = 'site_changed';

	/**
	 * Why this request paused the connection, kept in memory too so the
	 * request stops sending even when the write failed.
	 *
	 * @var string|null
	 */
	private ?string $paused_here = null;

	/**
	 * The `site` of the connection whose key this request last handed out.
	 *
	 * @var string|null
	 */
	private ?string $credential_site = null;

	/**
	 * The sealed value last opened, and what it opened to, so a request
	 * decrypts the connection once.
	 *
	 * @var array{0: string, 1: StoredConnection|null}|null
	 */
	private ?array $opened = null;

	private readonly Closure $site_url;

	/**
	 * @param Cipher       $cipher   Seals and opens the stored connection.
	 * @param Closure|null $site_url `fn(): string` the site's address now, as a connection stores it; `Connection::site_url()` when null.
	 */
	public function __construct( private readonly Cipher $cipher, ?Closure $site_url = null ) {
		$this->site_url = $site_url ?? static fn (): string => Connection::site_url();
	}

	/**
	 * The connection, or null when the store is not connected or what is
	 * stored cannot be read.
	 */
	public function load(): ?StoredConnection {
		return $this->open( $this->stored() );
	}

	/**
	 * @param array<string, mixed> $stored The option.
	 */
	private function open( array $stored ): ?StoredConnection {
		$sealed = $stored['sealed'] ?? null;
		if ( ! is_string( $sealed ) ) {
			return null;
		}
		if ( null === $this->opened || $this->opened[0] !== $sealed ) {
			$this->opened = array( $sealed, $this->decode( $sealed ) );
		}
		return $this->opened[1];
	}

	/**
	 * @param string $sealed The stored, encrypted connection.
	 */
	private function decode( string $sealed ): ?StoredConnection {
		$plain = $this->cipher->decrypt( $sealed );
		$data  = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		try {
			return StoredConnection::from_array( $data );
		} catch ( UnexpectedValueException $e ) {
			return null;
		}
	}

	/**
	 * Replaces the stored connection, unpaused.
	 *
	 * @param StoredConnection $connection The new connection.
	 *
	 * @throws RuntimeException When it could not be written; what was stored stays.
	 */
	public function save( StoredConnection $connection ): void {
		$written = $this->write(
			array(
				'sealed' => $this->cipher->encrypt( (string) wp_json_encode( $connection->to_array() ) ),
				'paused' => null,
			)
		);
		// The sealed value never repeats (its nonce is random), so false is a failed write.
		if ( ! $written ) {
			throw new RuntimeException( 'The Pixonaut connection could not be saved.' );
		}
		$this->paused_here = null;
	}

	/**
	 * Why the connection is paused, or null when it is not or nothing is
	 * stored. A pause written to the option comes first; then
	 * `PAUSED_SITE_CHANGED` while the site is not the one that connected.
	 */
	public function paused(): ?string {
		if ( null !== $this->paused_here ) {
			return $this->paused_here;
		}
		$stored = $this->stored();
		if ( ! isset( $stored['sealed'] ) ) {
			return null;
		}
		$paused = $stored['paused'] ?? null;
		if ( is_string( $paused ) ) {
			return $paused;
		}
		$connection = $this->open( $stored );
		if ( null === $connection || untrailingslashit( $connection->home_url ) !== ( $this->site_url )() ) {
			return self::PAUSED_SITE_CHANGED;
		}
		return null;
	}

	/**
	 * Pauses the stored connection. Nothing happens when none is stored.
	 * This request stays paused even when the write fails.
	 *
	 * @param string $reason Why, e.g. `unauthorized`.
	 */
	public function pause( string $reason ): void {
		$stored = $this->stored();
		if ( ! isset( $stored['sealed'] ) ) {
			return;
		}
		$this->paused_here = $reason;
		$stored['paused']  = $reason;
		$this->write( $stored );
	}

	/**
	 * Pauses the connection only when it is still the one with that `site`:
	 * a refusal of an old connection's key must not pause a newer one.
	 * Reads the option afresh, as another request may have replaced it.
	 *
	 * @param string $site   The `site` of the connection whose key was refused.
	 * @param string $reason Why, e.g. `unauthorized`.
	 *
	 * @return bool Whether it paused the connection.
	 */
	public function pause_if_current( string $site, string $reason ): bool {
		wp_cache_delete( self::OPTION, 'options' );
		$stored = $this->stored();
		if ( isset( $stored['paused'] ) || $this->open( $stored )?->site !== $site ) {
			return false;
		}
		$this->pause( $reason );
		return true;
	}

	/**
	 * Takes the lock that lets one request at a time connect or disconnect.
	 * The add_option() call inserts only when the option is absent, which
	 * the options table's unique name makes atomic.
	 *
	 * @return bool Whether it took it; false while another request holds it.
	 */
	public function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		$held_since = (int) get_option( self::LOCK_OPTION, 0 );
		if ( time() - $held_since < self::LOCK_TTL_SECONDS ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, time(), '', false );
	}

	public function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * The `site` of the connection whose key this request last handed to
	 * the client, or null when it handed out none.
	 */
	public function credential_site(): ?string {
		return $this->credential_site;
	}

	/**
	 * Forgets the connection.
	 *
	 * @return bool Whether nothing is stored any more.
	 */
	public function clear(): bool {
		delete_option( self::OPTION );
		$cleared = null === get_option( self::OPTION, null );
		if ( $cleared ) {
			$this->paused_here = null;
		}
		return $cleared;
	}

	public function secret( Credential $credential ): ?string {
		if ( Credential::None === $credential || null !== $this->paused() ) {
			return null;
		}
		$connection            = $this->load();
		$this->credential_site = $connection?->site;
		return Credential::PublishableKey === $credential ? $connection?->publishable_key : $connection?->integration_key;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Not autoloaded: only requests that call Pixonaut read it.
	 *
	 * @param array<string, mixed> $value The option.
	 */
	private function write( array $value ): bool {
		return update_option( self::OPTION, $value, false );
	}
}
