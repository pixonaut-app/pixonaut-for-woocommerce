<?php
/**
 * The store-level credential Pixonaut calls the shop tools with.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Closure;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreHoldAccessRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;

/**
 * The switch "Allow Pixel to make changes to your store". On, the store has
 * issued one credential, bound to no user, and handed it to Pixonaut; only
 * its hash is kept, so the secret is never in the database. Off, no credential
 * is valid: revoking is deleting the hash, which needs nothing from Pixonaut.
 *
 * Everything kept belongs to one connection, by its `site`: a new connection
 * starts off, and starts able to be turned on from the studio.
 *
 * Turned off in WordPress, the switch is `cut`: the studio asking for it to
 * come back on is ignored until it is turned on in WordPress again.
 *
 * Every change is a compare-and-swap on the option's row, not a lock: turning
 * it off must never wait for a holder, nor for a call to Pixonaut.
 */
final class StoreAccess {

	public const OPTION = Schema::STORE_ACCESS_OPTION;

	/**
	 * Sent when the switch is turned off in the studio; Pixonaut has deleted its copy.
	 */
	public const EVENT_OFF = 'store_access.off';

	/**
	 * Sent when the switch is turned on in the studio.
	 */
	public const EVENT_REQUESTED = 'store_access.requested';

	/**
	 * The field of `store_access.requested` naming the request.
	 */
	private const REQUEST_ID = 'requestId';

	/**
	 * Bytes of randomness in a credential; written as hex.
	 */
	private const SECRET_BYTES = 32;

	private const MILLISECONDS_PER_SECOND = 1000;

	/**
	 * How many times a change is tried against a row others keep changing,
	 * before it counts as not saved.
	 */
	private const MAX_TRANSITION_ATTEMPTS = 5;

	/**
	 * The option's `autoload`: only the route and the card read it.
	 */
	private const NOT_AUTOLOADED = 'off';

	private readonly Closure $clock;

	/**
	 * Whether turning it on failed in this request.
	 *
	 * @var bool
	 */
	private bool $failed = false;

	/**
	 * @param ConnectionStore $store  The stored connection the credential belongs to.
	 * @param Pixonaut        $api    Hands the credential to Pixonaut, or takes it back.
	 * @param LoggerInterface $logger Logs what Pixonaut did not take; never the credential.
	 * @param Closure|null    $clock  `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly ConnectionStore $store,
		private readonly Pixonaut $api,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'pixonaut_wc_connection_disconnected', array( $this, 'forget' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_connected', array( $this, 'forget_other_connections' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_paused', array( $this, 'on_paused' ), 10, 1 );
	}

	/**
	 * The webhook handlers, by event type.
	 *
	 * @return array<string, callable(Event): void>
	 */
	public function handlers(): array {
		return array(
			self::EVENT_OFF       => array( $this, 'on_studio_off' ),
			self::EVENT_REQUESTED => array( $this, 'on_studio_requested' ),
		);
	}

	/**
	 * Whether the switch is on. Never for a paused connection, nor for a
	 * copied site: its database holds the hash of the site it was copied from.
	 */
	public function is_on(): bool {
		return null !== $this->stored_hash() && null === $this->store->paused();
	}

	/**
	 * Whether the switch was turned off in WordPress, so only WordPress can
	 * turn it back on.
	 */
	public function is_cut(): bool {
		return true === ( $this->state()['cut'] ?? false );
	}

	/**
	 * Whether a call with this secret is the store's credential.
	 *
	 * @param string $secret The secret a call carries.
	 */
	public function accepts( string $secret ): bool {
		$hash = $this->stored_hash();
		return null !== $hash && null === $this->store->paused() && hash_equals( $hash, self::hash( $secret ) );
	}

	/**
	 * Turns the switch on in WordPress: issues a new credential, never the
	 * old one, and hands it to Pixonaut. When it could not be handed over,
	 * the switch stays as it was, a cut included. When it could not be saved,
	 * Pixonaut is not sent it.
	 *
	 * @return bool Whether Pixonaut took it.
	 */
	public function turn_on(): bool {
		try {
			$this->issue( fn ( array $kept, string $hash ): array => $this->on_state( $hash, $this->now() ) );
		} catch ( ApiException $e ) {
			$this->failed = true;
			$this->log_refusal( 'Pixonaut did not take the store\'s access for Pixel: %s.', 'connectedStore.holdAccess', $e );
			return false;
		} catch ( RuntimeException $e ) {
			$this->failed = true;
			$this->logger->error( 'The store could not save its access for Pixel: ' . $e->getMessage() );
			return false;
		}
		return true;
	}

	/**
	 * Whether turning it on failed in this request.
	 */
	public function failed(): bool {
		return $this->failed;
	}

	/**
	 * Turns the switch off in WordPress: the credential is revoked first, so
	 * this works with Pixonaut unreachable, and Pixonaut is told after. Told
	 * also when the store could not save it: that still cuts access on
	 * Pixonaut's side.
	 *
	 * @return bool Whether Pixonaut was told.
	 *
	 * @throws RuntimeException When the store could not save it, so the credential may still be valid.
	 */
	public function turn_off(): bool {
		$not_saved = null;
		try {
			$this->transition( fn (): array => $this->off_state( true, $this->now() ) );
		} catch ( RuntimeException $e ) {
			$not_saved = $e;
		}
		$told = $this->tell_pixonaut_it_is_off();
		if ( null !== $not_saved ) {
			throw $not_saved;
		}
		return $told;
	}

	/**
	 * @return bool Whether Pixonaut was told.
	 */
	private function tell_pixonaut_it_is_off(): bool {
		if ( null !== $this->store->paused() ) {
			return false;
		}
		try {
			// The credential is revoked already, and an admin is waiting.
			$this->api->sdk( Pixonaut::SHORT_TIMEOUT_SECONDS )->connect()->cutAccess();
		} catch ( ApiException $e ) {
			$this->log_refusal( 'Pixonaut was not told that Pixel lost the store\'s access: %s.', 'connectedStore.cutAccess', $e );
			return false;
		}
		return true;
	}

	/**
	 * The studio turned the switch off, and Pixonaut deleted its copy.
	 *
	 * @param Event $event `store_access.off`.
	 *
	 * @throws RuntimeException When the store could not save it, so the job tries again.
	 */
	public function on_studio_off( Event $event ): void {
		$this->transition(
			fn ( array $kept ): ?array => self::is_older_than_the_last_change( $event, $kept )
				? null
				: $this->off_state( true === ( $kept['cut'] ?? false ), $event->createdAt )
		);
	}

	/**
	 * The studio asks to turn the switch on. Ignored once it was turned off
	 * in WordPress. A request Pixonaut no longer stands by is dropped; any
	 * other failure lets the job try the event again, with a new secret, which
	 * the same `requestId` lets replace one whose answer was lost.
	 *
	 * A request naming no `requestId` is dropped too: a `PUT` without one is an
	 * opt-in in WordPress, which always wins over a turn off in the studio.
	 *
	 * A `RuntimeException` from saving it passes through too, so the job tries again.
	 *
	 * @param Event $event `store_access.requested`.
	 *
	 * @throws ApiException When Pixonaut did not take the credential, and may yet.
	 */
	public function on_studio_requested( Event $event ): void {
		$request_id = Payload::string( $event, self::REQUEST_ID );
		if ( null === $request_id ) {
			$this->logger->warning( 'A request from the studio for the store\'s access named no requestId, and was dropped.', array( 'event' => $event->id ) );
			return;
		}
		if ( null === $this->store->load() || null !== $this->store->paused() ) {
			return;
		}
		try {
			$this->issue(
				fn ( array $kept, string $hash ): ?array => true === ( $kept['cut'] ?? false ) || self::is_older_than_the_last_change( $event, $kept )
					? null
					: $this->on_state( $hash, $event->createdAt ),
				$request_id
			);
		} catch ( ApiException $e ) {
			if ( ErrorCode::STORE_ACCESS_REQUEST_STALE !== $e->errorCode ) {
				throw $e;
			}
		}
	}

	/**
	 * Forgets everything kept: the connection it belonged to has gone. Best
	 * effort: what stays is keyed by that connection's `site`, so no other
	 * connection reads it, and a paused or gone connection accepts nothing.
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * A new connection starts off: what an earlier connection left is dropped.
	 */
	public function forget_other_connections(): void {
		$site = $this->store->load()?->site;
		$kept = get_option( self::OPTION, array() );
		if ( is_array( $kept ) && ( $kept['site'] ?? null ) !== $site ) {
			$this->forget();
		}
	}

	/**
	 * Pixonaut refusing the store's key means it was disconnected there,
	 * which revokes the credential. A copied site only holds it back.
	 *
	 * @param string $reason Why the connection paused.
	 */
	public function on_paused( string $reason ): void {
		if ( Connection::PAUSED_UNAUTHORIZED === $reason ) {
			$this->forget();
		}
	}

	/**
	 * Issues a new credential and hands it over. The hash is saved first:
	 * Pixonaut may call the moment it has it. When Pixonaut does not take it
	 * the switch goes back to what it was, unless it changed meanwhile: a cut
	 * or a newer credential stands. When the hash could not be saved, Pixonaut
	 * is not sent it.
	 *
	 * @param Closure     $next       `fn(array<string, mixed> $kept, string $hash): ?array` what to keep with this hash; null issues nothing.
	 * @param string|null $request_id The studio's request this answers; null for an opt-in in WordPress.
	 *
	 * @throws ApiException When Pixonaut did not take it, or the store is not connected.
	 * @phpstan-throws ApiException|RuntimeException A `RuntimeException` when the hash could not be saved.
	 */
	private function issue( Closure $next, ?string $request_id = null ): void {
		$secret = bin2hex( random_bytes( self::SECRET_BYTES ) );
		$hash   = self::hash( $secret );
		$before = array();
		$issued = $this->transition(
			static function ( array $kept ) use ( $next, $hash, &$before ): ?array {
				$before = $kept;
				return $next( $kept, $hash );
			}
		);
		if ( ! $issued ) {
			return;
		}
		try {
			$this->api->sdk()->connect()->holdAccess( new ConnectedStoreHoldAccessRequest( credential: $secret, requestId: $request_id ) );
		} catch ( ApiException $e ) {
			$this->take_back( $hash, $before );
			throw $e;
		}
	}

	/**
	 * Puts the switch back after Pixonaut did not take a credential, unless it
	 * changed meanwhile. Pixonaut's answer is what the caller goes on: when this
	 * cannot be saved, the hash that stays is of a secret nobody holds.
	 *
	 * @param string               $hash   The hash just issued.
	 * @param array<string, mixed> $before What was kept before it; empty keeps an off state that no event is older than.
	 */
	private function take_back( string $hash, array $before ): void {
		try {
			$this->transition(
				function ( array $kept ) use ( $hash, $before ): ?array {
					if ( ( $kept['hash'] ?? null ) !== $hash ) {
						return null;
					}
					return array() === $before ? $this->off_state( false, 0 ) : $before;
				}
			);
		} catch ( RuntimeException $e ) {
			$this->logger->error( 'The store could not take back the access Pixonaut did not take: ' . $e->getMessage() );
		}
	}

	/**
	 * @param string $hash       The credential's hash.
	 * @param int    $changed_at When it changed, in milliseconds.
	 *
	 * @return array<string, mixed>
	 */
	private function on_state( string $hash, int $changed_at ): array {
		return $this->kept_state( $hash, false, $changed_at );
	}

	/**
	 * @param bool $cut        Whether only WordPress can turn it back on.
	 * @param int  $changed_at When it changed, in milliseconds.
	 *
	 * @return array<string, mixed>
	 */
	private function off_state( bool $cut, int $changed_at ): array {
		return $this->kept_state( null, $cut, $changed_at );
	}

	/**
	 * @param string|null $hash       The credential's hash; null when off.
	 * @param bool        $cut        Whether only WordPress can turn it back on.
	 * @param int         $changed_at When it changed, in milliseconds.
	 *
	 * @return array<string, mixed>
	 */
	private function kept_state( ?string $hash, bool $cut, int $changed_at ): array {
		return array(
			'hash'       => $hash,
			'cut'        => $cut,
			'site'       => $this->store->load()?->site,
			'changed_at' => $changed_at,
		);
	}

	/**
	 * The clock, as a change made here is dated. An event's change is dated by
	 * the event, not by when it was processed: an off and a newer request read
	 * together must not count the request as older.
	 */
	private function now(): int {
		return ( $this->clock )() * self::MILLISECONDS_PER_SECOND;
	}

	/**
	 * Changes what is kept only if the row still holds what the change was
	 * worked out from; otherwise works it out again. What a change depends on is
	 * checked inside `$change`, so nothing can land between the check and the write.
	 *
	 * @param Closure $change `fn(array<string, mixed> $kept): ?array` the new state; null changes nothing. `$kept` is empty when nothing is kept for this connection.
	 *
	 * @return bool Whether the state is now what `$change` returned; false when it returned null.
	 *
	 * @throws RuntimeException When it could not be saved.
	 */
	private function transition( Closure $change ): bool {
		for ( $attempt = 0; $attempt < self::MAX_TRANSITION_ATTEMPTS; $attempt++ ) {
			$raw  = $this->read_row();
			$next = $change( $this->for_this_connection( null === $raw ? array() : maybe_unserialize( $raw ) ) );
			if ( null === $next ) {
				return false;
			}
			$value = maybe_serialize( $next );
			// MySQL counts writing the same value as no row changed, which is not losing a race.
			if ( $value === $raw || $this->write_row( $raw, $value ) ) {
				return true;
			}
		}
		throw new RuntimeException( 'The store\'s access for Pixel kept changing while it was saved.' );
	}

	/**
	 * The option's row as stored, past every cache: a change is compared to it.
	 *
	 * @return string|null Its value; null when there is no row.
	 *
	 * @throws RuntimeException When it could not be read.
	 */
	private function read_row(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a compare-and-swap reads the row itself.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( null === $raw && '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'The store\'s access for Pixel could not be read.' );
		}
		return $raw;
	}

	/**
	 * Writes the row, only if it still holds `$expected`. Not `add_option()`:
	 * its `ON DUPLICATE KEY UPDATE` overwrites a row that appeared meanwhile.
	 *
	 * @param string|null $expected The row's value as read; null when there was none.
	 * @param string      $value    What to write.
	 *
	 * @return bool Whether it was written; false when the row had changed.
	 *
	 * @throws RuntimeException When the database refused it.
	 */
	private function write_row( ?string $expected, string $value ): bool {
		global $wpdb;
		$written = null === $expected
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a compare-and-swap; the caches are cleared below.
			? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::OPTION, $value, self::NOT_AUTOLOADED ) )
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a compare-and-swap; the caches are cleared below.
			: $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION, $expected ) );
		if ( false === $written ) {
			throw new RuntimeException( 'The store\'s access for Pixel could not be saved.' );
		}
		if ( 0 === (int) $written ) {
			return false;
		}
		wp_cache_delete( self::OPTION, 'options' );
		if ( null === $expected ) {
			wp_cache_delete( 'notoptions', 'options' );
		}
		return true;
	}

	/**
	 * A delivery can arrive late, or be read again from the stream: one from
	 * before the switch last changed says nothing about it now.
	 *
	 * @param Event                $event An event about the switch.
	 * @param array<string, mixed> $kept  What is kept.
	 */
	private static function is_older_than_the_last_change( Event $event, array $kept ): bool {
		$changed_at = $kept['changed_at'] ?? null;
		return is_int( $changed_at ) && $event->createdAt < $changed_at;
	}

	private function stored_hash(): ?string {
		$hash = $this->state()['hash'] ?? null;
		return is_string( $hash ) ? $hash : null;
	}

	/**
	 * What is kept for the current connection.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		// Read afresh: a job in another process may have just revoked it.
		wp_cache_delete( self::OPTION, 'options' );
		return $this->for_this_connection( get_option( self::OPTION, array() ) );
	}

	/**
	 * @param mixed $kept What the option holds.
	 *
	 * @return array<string, mixed> It, when it belongs to the current connection; otherwise empty.
	 */
	private function for_this_connection( mixed $kept ): array {
		$site = $this->store->load()?->site;
		return is_array( $kept ) && null !== $site && ( $kept['site'] ?? null ) === $site ? $kept : array();
	}

	/**
	 * @param string $secret A credential.
	 */
	private static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * @param string       $message   What did not happen, with a `%s` for the reason.
	 * @param string       $operation The operation.
	 * @param ApiException $error     Why.
	 */
	private function log_refusal( string $message, string $operation, ApiException $error ): void {
		$this->logger->warning(
			sprintf( $message, $error->getMessage() ),
			array(
				'operation'  => $operation,
				'status'     => $error->status,
				'error_code' => $error->errorCode,
			)
		);
	}
}
