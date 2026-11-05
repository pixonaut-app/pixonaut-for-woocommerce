<?php
/**
 * The designer links waiting to be withdrawn.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\DesignerLinks;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Schema;

/**
 * Withdraws designer links the store no longer keeps. A link Pixonaut could
 * not be asked to withdraw (it was not reachable, or answered an error) is
 * kept in an option, and a job asks again after WITHDRAW_RETRY_SECONDS, then
 * twice as long each time, up to WITHDRAW_RETRY_MAX_SECONDS, until it is
 * withdrawn. While the connection is paused the links wait; disconnecting
 * forgets them, since Pixonaut withdraws a store's links when it disconnects.
 */
final class DesignerLinkWithdrawals {

	/**
	 * The job asking Pixonaut again to withdraw the links it could not be
	 * asked to before.
	 */
	public const WITHDRAW_ACTION = 'pixonaut_wc_storefront_withdraw_links';

	public const GROUP = Schema::ACTION_GROUP;

	public const WITHDRAWALS_OPTION = Schema::DESIGNER_LINK_WITHDRAWALS_OPTION;

	/**
	 * How long the first try again waits: a minute. Each later one waits
	 * twice as long as the last.
	 */
	public const WITHDRAW_RETRY_SECONDS = 60;

	/**
	 * The longest wait between two tries: six hours.
	 */
	public const WITHDRAW_RETRY_MAX_SECONDS = 6 * 60 * 60;

	private readonly Closure $clock;

	/**
	 * @param DesignerLinks $api        Withdraws designer links.
	 * @param Connection    $connection Whether the store is connected, and not paused.
	 * @param Logger        $logger     Logs a link that could not be withdrawn, or a job that could not be queued.
	 * @param Closure|null  $clock      `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly DesignerLinks $api,
		private readonly Connection $connection,
		private readonly Logger $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( self::WITHDRAW_ACTION, array( $this, 'withdraw_pending' ) );
	}

	/**
	 * The links waiting to be withdrawn.
	 *
	 * @return list<string>
	 * @phpstan-impure
	 */
	public static function pending_withdrawals(): array {
		$stored = get_option( self::WITHDRAWALS_OPTION );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Asks Pixonaut again to withdraw the links waiting to be, and asks
	 * again later, each time waiting twice as long, while any are left.
	 * While the connection is paused they wait; once the store disconnected
	 * there are none, since Pixonaut withdrew them.
	 *
	 * @param mixed $attempt Which try this is, from 1.
	 */
	public function withdraw_pending( mixed $attempt = 1 ): void {
		$attempt = max( 1, (int) $attempt );
		if ( null === $this->connection->current() ) {
			return;
		}
		$withdrawn = array();
		if ( null === $this->connection->paused() ) {
			foreach ( self::pending_withdrawals() as $id ) {
				if ( $this->withdraw( $id ) ) {
					$withdrawn[] = $id;
				}
			}
		}
		// Another request may have added links meanwhile.
		wp_cache_delete( self::WITHDRAWALS_OPTION, 'options' );
		$left = array_values( array_diff( self::pending_withdrawals(), $withdrawn ) );
		if ( array() === $left ) {
			delete_option( self::WITHDRAWALS_OPTION );
			return;
		}
		update_option( self::WITHDRAWALS_OPTION, $left, false );
		$this->queue_withdrawals( $attempt + 1, min( self::WITHDRAW_RETRY_MAX_SECONDS, self::WITHDRAW_RETRY_SECONDS * 2 ** $attempt ) );
	}

	/**
	 * Withdraws links, keeping those Pixonaut could not be asked about for
	 * the job to ask again.
	 *
	 * @param string[] $ids The links.
	 */
	public function withdraw_or_keep( array $ids ): void {
		$this->queue( array_values( array_filter( $ids, fn ( string $id ): bool => ! $this->withdraw( $id ) ) ) );
	}

	/**
	 * Adds links to those waiting to be withdrawn, for the job to ask
	 * Pixonaut about.
	 *
	 * @param string[] $ids The links.
	 */
	public function queue( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}
		update_option( self::WITHDRAWALS_OPTION, array_values( array_unique( array_merge( self::pending_withdrawals(), $ids ) ) ), false );
		if ( false === as_next_scheduled_action( self::WITHDRAW_ACTION, null, self::GROUP ) ) {
			$this->queue_withdrawals( 1, self::WITHDRAW_RETRY_SECONDS );
		}
	}

	/**
	 * Forgets every link waiting to be withdrawn: Pixonaut withdraws a
	 * store's links when it disconnects.
	 */
	public function forget_all(): void {
		delete_option( self::WITHDRAWALS_OPTION );
	}

	/**
	 * @param int $attempt Which try the job is.
	 * @param int $delay   Seconds to wait.
	 */
	private function queue_withdrawals( int $attempt, int $delay ): void {
		if ( 0 === as_schedule_single_action( ( $this->clock )() + $delay, self::WITHDRAW_ACTION, array( 'attempt' => $attempt ), self::GROUP ) ) {
			$this->logger->error( 'Asking Pixonaut again to withdraw designer links could not be queued.', array( 'links' => self::pending_withdrawals() ) );
		}
	}

	/**
	 * @param string $id The link.
	 * @return bool Whether it no longer works: Pixonaut withdrew it now or before.
	 */
	private function withdraw( string $id ): bool {
		try {
			$this->api->withdraw( $id );
			return true;
		} catch ( ApiError $error ) {
			$this->logger->warning( sprintf( 'Could not withdraw designer link %s: %s', $id, $error->getMessage() ), array( 'code' => $error->error_code ) );
			return false;
		}
	}
}
