<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Cart\CommitFailures;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;

final class CommitFailuresTest extends PersonalisedCartTestCase {

	private const SERVER_ERROR = 500;

	private const FORBIDDEN = 403;

	private const NOW = 1_800_000_000;

	private RecordingLogger $logger;

	private int $now = self::NOW;

	private CommitFailures $failures;

	public function set_up(): void {
		parent::set_up();
		$this->logger   = new RecordingLogger();
		$this->failures = new CommitFailures( $this->plugin->notices(), $this->logger, fn (): int => $this->now );
	}

	public function tear_down(): void {
		delete_option( CommitFailures::OPTION );
		delete_option( CommitFailures::LOCK_OPTION );
		parent::tear_down();
	}

	private static function error( string $code, int $status = self::SERVER_ERROR ): ApiException {
		return new ApiException( 'designs.commit', $status, $code, false );
	}

	/**
	 * @return list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private function errors_logged(): array {
		return array_values( array_filter( $this->logger->entries, static fn ( array $entry ): bool => 'error' === $entry['level'] ) );
	}

	public function test_failures_below_the_threshold_raise_nothing(): void {
		for ( $i = 1; $i < CommitFailures::THRESHOLD; $i++ ) {
			$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
		}

		$this->assertFalse( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
		$this->assertSame( array(), $this->errors_logged() );
	}

	public function test_the_threshold_within_the_window_raises_one_notice_and_one_log_line(): void {
		for ( $i = 0; $i < CommitFailures::THRESHOLD * 2; $i++ ) {
			$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
			++$this->now;
		}

		$notice = $this->plugin->notices()->all()[ CommitFailures::NOTICE ] ?? null;
		$this->assertNotNull( $notice );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'keeps refusing or not answering', $notice['message'] );
		$this->assertCount( 1, $this->errors_logged() );
	}

	public function test_failures_spread_beyond_the_window_raise_nothing(): void {
		for ( $i = 0; $i < CommitFailures::THRESHOLD * 2; $i++ ) {
			$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
			$this->now += CommitFailures::WINDOW_SECONDS / ( CommitFailures::THRESHOLD - 1 );
		}

		$this->assertFalse( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
	}

	public function test_a_suspended_workspace_raises_the_notice_at_once_and_once(): void {
		$this->failures->failed( self::error( ErrorCode::WORKSPACE_SUSPENDED, self::FORBIDDEN ) );
		$this->failures->failed( self::error( ErrorCode::WORKSPACE_SUSPENDED, self::FORBIDDEN ) );

		$notice = $this->plugin->notices()->all()[ CommitFailures::NOTICE ] ?? null;
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'suspended the workspace', $notice['message'] );
		$this->assertCount( 1, $this->errors_logged() );
	}

	public function test_commits_failing_after_a_lifted_suspension_replace_its_notice(): void {
		$this->failures->failed( self::error( ErrorCode::WORKSPACE_SUSPENDED, self::FORBIDDEN ) );
		$this->plugin->notices()->remove( CommitFailures::NOTICE );

		for ( $i = 0; $i < CommitFailures::THRESHOLD; $i++ ) {
			$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
		}

		$notice = $this->plugin->notices()->all()[ CommitFailures::NOTICE ] ?? null;
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'keeps refusing or not answering', $notice['message'] );
		$this->assertCount( 2, $this->errors_logged() );
	}

	public function test_a_failure_while_another_request_holds_the_lock_is_not_counted(): void {
		for ( $i = 1; $i < CommitFailures::THRESHOLD; $i++ ) {
			$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
		}
		add_option( CommitFailures::LOCK_OPTION, $this->now );

		$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
		$this->assertFalse( $this->plugin->notices()->has( CommitFailures::NOTICE ) );

		delete_option( CommitFailures::LOCK_OPTION );
		$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
		$this->assertTrue( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
	}

	public function test_a_dismissed_notice_stays_away_while_commits_keep_failing(): void {
		for ( $i = 0; $i < CommitFailures::THRESHOLD; $i++ ) {
			$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );
		}
		$this->plugin->notices()->remove( CommitFailures::NOTICE );

		$this->failures->failed( self::error( ErrorCode::DB_ERROR ) );

		$this->assertFalse( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
		$this->assertCount( 1, $this->errors_logged() );
	}

	public function test_a_successful_commit_clears_the_notice_and_the_count(): void {
		$this->failures->failed( self::error( ErrorCode::WORKSPACE_SUSPENDED, self::FORBIDDEN ) );

		$this->failures->succeeded();

		$this->assertFalse( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
		$this->assertFalse( get_option( CommitFailures::OPTION ) );
	}

	public function test_add_to_cart_tells_the_merchant_once_commits_keep_failing_and_clears_on_success(): void {
		for ( $i = 0; $i < CommitFailures::THRESHOLD; $i++ ) {
			$this->recorder->json( self::SERVER_ERROR, array( 'error' => ErrorCode::DB_ERROR ) );
			$this->assertFalse( $this->post_add_to_cart() );
		}
		$this->assertSame( $this->plugin->labels()->get( Labels::DESIGN_NOT_SAVED ), self::errors()[0] );
		$this->assertTrue( $this->plugin->notices()->has( CommitFailures::NOTICE ) );

		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );

		$this->assertFalse( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
	}

	public function test_add_to_cart_tells_the_merchant_when_the_workspace_is_suspended(): void {
		$this->recorder->json( self::FORBIDDEN, array( 'error' => ErrorCode::WORKSPACE_SUSPENDED ) );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
		$this->assertTrue( $this->plugin->notices()->has( CommitFailures::NOTICE ) );
	}
}
