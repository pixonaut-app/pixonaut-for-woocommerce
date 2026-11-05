<?php
/**
 * Tells the merchant when design commits at add to cart keep failing.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Closure;
use Pixonaut\WooCommerce\Admin\HelpLinks;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Schema;

/**
 * A shopper whose design could not be saved sees a message; the merchant
 * sees one admin notice, and one log line, once `THRESHOLD` commits failed
 * within `WINDOW_SECONDS`, or as soon as Pixonaut answers that the workspace
 * is suspended. Neither repeats while the cause stays the same, and the
 * notice goes once a commit succeeds again.
 */
final class CommitFailures {

	public const OPTION = Schema::COMMIT_FAILURES_OPTION;

	public const LOCK_OPTION = Schema::COMMIT_FAILURES_LOCK_OPTION;

	/**
	 * A lock older than this was left by a request that died; a change
	 * takes a few queries.
	 */
	public const LOCK_TTL_SECONDS = 10;

	public const NOTICE = 'commits_failing';

	/**
	 * Failed commits within the window that raise the notice: a few, so one
	 * shopper retrying a slow request does not.
	 */
	public const THRESHOLD = 5;

	/**
	 * How far back failed commits count towards the threshold, in minutes.
	 */
	public const WINDOW_MINUTES = 15;

	/**
	 * The window in seconds. Not `MINUTE_IN_SECONDS`, which is not defined
	 * outside WordPress.
	 */
	public const WINDOW_SECONDS = self::WINDOW_MINUTES * 60;

	/**
	 * The notice's cause: commits keep failing.
	 */
	public const FAILING = 'failing';

	/**
	 * The notice's cause: Pixonaut suspended the workspace.
	 */
	public const SUSPENDED = 'suspended';

	private readonly Closure $clock;

	/**
	 * @param Notices      $notices Admin notices.
	 * @param Logger       $logger  Logs the cause once.
	 * @param Closure|null $clock   `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly Notices $notices,
		private readonly Logger $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Counts a commit Pixonaut refused or did not answer.
	 *
	 * @param ApiError $error Why it failed.
	 */
	public function failed( ApiError $error ): void {
		// Best effort: while another request holds the lock, this failure goes uncounted.
		if ( ! $this->lock() ) {
			return;
		}
		try {
			$state = $this->state();
			$now   = ( $this->clock )();
			if ( ErrorCodes::WORKSPACE_SUSPENDED === $error->error_code ) {
				if ( self::SUSPENDED !== $state['raised'] ) {
					$state['raised'] = self::SUSPENDED;
					$this->raise( self::SUSPENDED, $error );
				}
				$this->save( $state );
				return;
			}
			$recent   = array_values( array_filter( $state['failures'], static fn ( int $at ): bool => $at > $now - self::WINDOW_SECONDS ) );
			$recent[] = $now;
			// Only the last THRESHOLD matter.
			$state['failures'] = array_slice( $recent, -self::THRESHOLD );
			// A suspension lifted while commits keep failing for another reason is a new cause.
			if ( self::FAILING !== $state['raised'] && count( $state['failures'] ) >= self::THRESHOLD ) {
				$state['raised'] = self::FAILING;
				$this->raise( self::FAILING, $error );
			}
			$this->save( $state );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Every side of a line committed: forgets the failures and takes the
	 * notice away.
	 */
	public function succeeded(): void {
		if ( false === get_option( self::OPTION ) || ! $this->lock() ) {
			return;
		}
		try {
			delete_option( self::OPTION );
			$this->notices->remove( self::NOTICE );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Takes the lock. The add_option() call inserts only when the option is
	 * absent, which the options table's unique name makes atomic.
	 *
	 * @return bool Whether it took it; false while another request holds it.
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK_OPTION, ( $this->clock )(), '', false ) ) {
			// What another request wrote while this one waited is read afresh.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}
		if ( ( $this->clock )() - (int) get_option( self::LOCK_OPTION, 0 ) < self::LOCK_TTL_SECONDS ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return $this->lock();
	}

	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * @param string   $cause FAILING or SUSPENDED.
	 * @param ApiError $error The failure that raised it.
	 */
	private function raise( string $cause, ApiError $error ): void {
		$message = self::SUSPENDED === $cause
			/* translators: 1: link to the Pixonaut studio, 2: link to the troubleshooting guide. */
			? __( 'Customers cannot add personalised products to the cart: Pixonaut suspended the workspace, so it saves no designs. Check the workspace\'s plan and billing in the <a href="%1$s" target="_blank" rel="noopener noreferrer">Pixonaut studio</a>. <a href="%2$s" target="_blank" rel="noopener noreferrer">Troubleshooting</a>', 'pixonaut-for-woocommerce' )
			/* translators: 1: link to the Pixonaut studio, 2: link to the troubleshooting guide. */
			: __( 'Customers cannot add personalised products to the cart: Pixonaut keeps refusing or not answering when the store saves their designs. Check the workspace in the <a href="%1$s" target="_blank" rel="noopener noreferrer">Pixonaut studio</a>, and the pixonaut-api log under WooCommerce > Status > Logs. <a href="%2$s" target="_blank" rel="noopener noreferrer">Troubleshooting</a>', 'pixonaut-for-woocommerce' );
		$this->notices->add( self::NOTICE, sprintf( $message, esc_url( Hosts::studio() ), esc_url( HelpLinks::docs( HelpLinks::TROUBLESHOOTING ) ) ), 'error' );
		$this->logger->error(
			self::SUSPENDED === $cause
				? 'Pixonaut suspended the workspace: designs cannot be saved at add to cart.'
				: sprintf( '%d design commits failed at add to cart within %d minutes; the last: %s', self::THRESHOLD, self::WINDOW_MINUTES, $error->getMessage() ),
			array(
				'operation'  => $error->operation,
				'status'     => $error->status,
				'error_code' => $error->error_code,
			)
		);
	}

	/**
	 * @return array{failures: list<int>, raised: string|null}
	 */
	private function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$raised = $stored['raised'] ?? null;
		return array(
			'failures' => array_values( array_map( 'intval', is_array( $stored['failures'] ?? null ) ? $stored['failures'] : array() ) ),
			'raised'   => is_string( $raised ) ? $raised : null,
		);
	}

	/**
	 * @param array{failures: list<int>, raised: string|null} $state What to keep.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
