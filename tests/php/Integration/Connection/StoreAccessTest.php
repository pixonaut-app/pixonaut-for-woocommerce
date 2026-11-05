<?php
/**
 * The switch that lets Pixel make changes to the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Connection;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Webhooks\Event;
use RuntimeException;
use WP_UnitTestCase;

final class StoreAccessTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const NOW = 1790000000;

	private const MILLISECONDS = 1000;

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private StoreAccess $access;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$client         = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$this->access   = new StoreAccess( $this->store, new Connect( $client ), new WcLogger(), static fn (): int => self::NOW );
		$this->connect();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		delete_option( StoreAccess::OPTION );
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	private function connect( string $site = 'site-a' ): void {
		$this->store->save(
			StoredConnection::from_array(
				array(
					'site'                  => $site,
					'home_url'              => Connection::site_url(),
					'connected_at'          => self::NOW,
					'tenant_id'             => 'tenant',
					'tenant_name'           => 'Acme',
					'environment'           => 'live',
					'connection_id'         => 'sc_1',
					'integration_key_id'    => 'key-id',
					'integration_key'       => 'ik_live_0123456789abcdef01234567',
					'integration_key_label' => 'Shop',
					'publishable_key_id'    => 'pub-id',
					'publishable_key'       => 'pk_live_89abcdef0123456789abcdef',
					'allowed_origins'       => array( 'http://example.org' ),
					'webhook_id'            => 'hook',
					'webhook_url'           => 'http://example.org/hook',
					'webhook_secret'        => 'whsec_0123456789abcdef0123456789abcdef',
				)
			)
		);
	}

	/**
	 * Turns the switch on, answered as Pixonaut answers.
	 *
	 * @return string The credential Pixonaut was handed.
	 */
	private function turn_on(): string {
		$this->recorder->raw( 204, '', 'application/json' );
		$before = count( $this->recorder->requests );
		$this->assertTrue( $this->access->turn_on() );
		$request = $this->recorder->requests[ $before ];
		$body    = json_decode( (string) $request['body'], true );
		$this->assertIsArray( $body );
		$this->assertIsString( $body['credential'] );
		return $body['credential'];
	}

	/**
	 * An event about the switch, made at a time relative to the test's clock.
	 *
	 * @param string $type          Its type.
	 * @param int    $after_seconds How long after the clock's now it was made.
	 */
	private static function event( string $type, int $after_seconds = 1 ): Event {
		return new Event( 'evt_' . $type, $type, ( self::NOW + $after_seconds ) * self::MILLISECONDS, array( 'requestId' => 'req_1' ) );
	}

	public function test_it_is_off_until_turned_on_also_for_a_connection_made_before_the_switch(): void {
		$this->assertFalse( $this->access->is_on() );
		$this->assertFalse( $this->access->is_cut() );
		$this->assertFalse( $this->access->accepts( 'anything' ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_turning_on_hands_over_a_credential_and_keeps_only_its_hash(): void {
		$credential = $this->turn_on();

		$this->assertSame( 'PUT', $this->recorder->requests[0]['method'] );
		$this->assertStringEndsWith( '/v1/connect/store-access', $this->recorder->requests[0]['url'] );
		$this->assertTrue( $this->access->is_on() );
		$this->assertTrue( $this->access->accepts( $credential ) );
		$this->assertFalse( $this->access->accepts( $credential . 'x' ) );
		$this->assertStringNotContainsString( $credential, (string) wp_json_encode( get_option( StoreAccess::OPTION ) ) );
	}

	public function test_turning_on_again_issues_a_new_credential_never_the_old_one(): void {
		$first  = $this->turn_on();
		$second = $this->turn_on();

		$this->assertNotSame( $first, $second );
		$this->assertFalse( $this->access->accepts( $first ) );
		$this->assertTrue( $this->access->accepts( $second ) );
	}

	public function test_turning_on_that_pixonaut_does_not_take_stays_off(): void {
		$this->recorder->fail();

		$this->assertFalse( $this->access->turn_on() );

		$this->assertTrue( $this->access->failed() );
		$this->assertFalse( $this->access->is_on() );
		$this->assertFalse( $this->access->is_cut() );
	}

	public function test_turning_off_revokes_at_once_and_tells_pixonaut(): void {
		$credential = $this->turn_on();
		$this->recorder->raw( 204, '', 'application/json' );

		$this->assertTrue( $this->access->turn_off() );

		$this->assertSame( 'DELETE', $this->recorder->requests[1]['method'] );
		$this->assertStringEndsWith( '/v1/connect/store-access', $this->recorder->requests[1]['url'] );
		$this->assertFalse( $this->access->is_on() );
		$this->assertTrue( $this->access->is_cut() );
		$this->assertFalse( $this->access->accepts( $credential ) );
	}

	public function test_turning_off_works_with_pixonaut_unreachable(): void {
		$credential = $this->turn_on();
		$this->recorder->fail();

		$this->assertFalse( $this->access->turn_off() );

		$this->assertFalse( $this->access->accepts( $credential ) );
		$this->assertTrue( $this->access->is_cut() );
	}

	public function test_the_studio_cannot_turn_it_back_on_after_a_cut_in_wordpress(): void {
		$this->turn_on();
		$this->recorder->raw( 204, '', 'application/json' );
		$this->access->turn_off();
		$sent = count( $this->recorder->requests );

		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) );

		$this->assertCount( $sent, $this->recorder->requests, 'answers nothing' );
		$this->assertFalse( $this->access->is_on() );
	}

	public function test_turning_on_in_wordpress_lifts_the_cut(): void {
		$this->turn_on();
		$this->recorder->raw( 204, '', 'application/json' );
		$this->access->turn_off();

		$this->turn_on();

		$this->assertTrue( $this->access->is_on() );
		$this->assertFalse( $this->access->is_cut() );
	}

	public function test_the_studio_turns_it_on_with_a_new_credential(): void {
		$this->recorder->raw( 204, '', 'application/json' );

		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) );

		$this->assertTrue( $this->access->is_on() );
		$this->assertSame( 'PUT', $this->recorder->requests[0]['method'] );
	}

	public function test_a_failed_request_from_the_studio_changes_nothing_and_is_tried_again(): void {
		$this->recorder->fail();

		try {
			$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) );
			$this->fail( 'The job must fail, so it runs again.' );
		} catch ( ApiError $e ) {
			$this->assertFalse( $this->access->is_on() );
		}
	}

	public function test_answering_the_studio_echoes_its_request_and_an_opt_in_sends_none(): void {
		$this->recorder->raw( 204, '', 'application/json' );
		$this->access->on_studio_requested( new Event( 'evt_1', StoreAccess::EVENT_REQUESTED, ( self::NOW + 1 ) * self::MILLISECONDS, array( 'requestId' => 'req_1' ) ) );

		$this->assertSame( 'req_1', json_decode( (string) $this->recorder->requests[0]['body'], true )['requestId'] ?? null );

		$this->turn_on();

		$this->assertArrayNotHasKey( 'requestId', (array) json_decode( (string) $this->recorder->requests[1]['body'], true ) );
	}

	public function test_a_stale_request_is_not_tried_again_and_revokes_what_it_issued(): void {
		$this->recorder->json( 409, array( 'error' => 'store_access_request_stale' ) );

		$this->access->on_studio_requested( new Event( 'evt_1', StoreAccess::EVENT_REQUESTED, ( self::NOW + 1 ) * self::MILLISECONDS, array( 'requestId' => 'req_old' ) ) );

		$this->assertFalse( $this->access->is_on() );
		$this->assertFalse( $this->access->is_cut() );
		$this->assertCount( 1, $this->recorder->requests, 'not tried again' );
		$sent = json_decode( (string) $this->recorder->requests[0]['body'], true );
		$this->assertIsArray( $sent );
		$this->assertFalse( $this->access->accepts( $sent['credential'] ) );
	}

	public function test_a_stale_request_leaves_the_credential_pixonaut_still_holds(): void {
		$held = $this->turn_on();
		$this->recorder->json( 409, array( 'error' => 'store_access_request_stale' ) );

		$this->access->on_studio_requested( new Event( 'evt_1', StoreAccess::EVENT_REQUESTED, ( self::NOW + 1 ) * self::MILLISECONDS, array( 'requestId' => 'req_old' ) ) );

		$this->assertTrue( $this->access->accepts( $held ) );
	}

	/**
	 * While the handover is out, a request elsewhere changes the switch, as
	 * the recorder's answer arrives.
	 *
	 * @param callable $change What happens meanwhile.
	 */
	private function during_the_handover( callable $change ): void {
		add_filter(
			'pre_http_request',
			static function ( mixed $preempt ) use ( $change ): mixed {
				static $ran = false;
				if ( ! $ran ) {
					$ran = true;
					$change();
				}
				return $preempt;
			},
			1
		);
	}

	public function test_a_cut_made_during_the_handover_survives_a_stale_answer_and_a_failure(): void {
		foreach ( array( 'stale', 'failed' ) as $outcome ) {
			delete_option( StoreAccess::OPTION );
			$this->during_the_handover(
				function (): void {
					$cut = get_option( StoreAccess::OPTION );
					$this->assertIsArray( $cut );
					update_option(
						StoreAccess::OPTION,
						array_merge(
							$cut,
							array(
								'hash' => null,
								'cut'  => true,
							)
						),
						false
					);
				}
			);
			if ( 'stale' === $outcome ) {
				$this->recorder->json( 409, array( 'error' => 'store_access_request_stale' ) );
				$this->access->on_studio_requested( new Event( 'evt_1', StoreAccess::EVENT_REQUESTED, ( self::NOW + 1 ) * self::MILLISECONDS, array( 'requestId' => 'req_old' ) ) );
			} else {
				$this->recorder->fail();
				$this->assertFalse( $this->access->turn_on() );
			}

			$this->assertTrue( $this->access->is_cut(), $outcome );
			$this->assertFalse( $this->access->is_on(), $outcome );
		}
	}

	/**
	 * Runs something just before a write to the option reaches the database,
	 * as another request would between the read and the conditional write.
	 *
	 * @param callable $change What happens meanwhile.
	 * @param int      $nth    Which write, from now on, to run it before.
	 */
	private function before_write( callable $change, int $nth = 1 ): void {
		$seen = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( $change, $nth, &$seen ): string {
				if ( self::writes_the_option( $query ) && ++$seen === $nth ) {
					$change();
				}
				return $query;
			}
		);
	}

	/**
	 * The database refuses writes to the option, from the nth on.
	 *
	 * @param int $from Which write, from now on, is the first refused.
	 */
	private function refuse_writes( int $from = 1 ): void {
		$seen = 0;
		add_filter(
			'query',
			static function ( string $query ) use ( $from, &$seen ): string {
				return self::writes_the_option( $query ) && ++$seen >= $from ? '' : $query;
			}
		);
	}

	private static function writes_the_option( string $query ): bool {
		return str_contains( $query, StoreAccess::OPTION ) && 1 === preg_match( '/^\s*(UPDATE|INSERT)/i', $query );
	}

	/**
	 * Cuts the switch by writing the row directly, as another request would.
	 */
	private function cut_in_another_request(): void {
		$cut = maybe_serialize(
			array(
				'hash'       => null,
				'cut'        => true,
				'site'       => 'site-a',
				'changed_at' => self::NOW * self::MILLISECONDS,
			)
		);
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- behind the plugin's back, on purpose.
			$wpdb->prepare( "REPLACE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", StoreAccess::OPTION, $cut )
		);
	}

	public function test_a_cut_landing_before_the_update_stays_cut_and_nothing_is_sent(): void {
		$this->on_then_off_in_the_studio();
		$sent = count( $this->recorder->requests );
		$this->before_write( fn () => $this->cut_in_another_request() );

		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED, 1 ) );

		$this->assertTrue( $this->access->is_cut() );
		$this->assertFalse( $this->access->is_on() );
		$this->assertCount( $sent, $this->recorder->requests );
	}

	public function test_a_cut_landing_before_the_first_insert_stays_cut_and_nothing_is_sent(): void {
		$this->before_write( fn () => $this->cut_in_another_request() );

		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) );

		$this->assertTrue( $this->access->is_cut() );
		$this->assertFalse( $this->access->is_on() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_cut_landing_before_the_take_back_stays_cut(): void {
		$this->on_then_off_in_the_studio();
		$this->before_write( fn () => $this->cut_in_another_request(), 2 );
		$this->recorder->json( 409, array( 'error' => 'store_access_request_stale' ) );

		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED, 1 ) );

		$this->assertTrue( $this->access->is_cut() );
		$this->assertFalse( $this->access->is_on() );
	}

	/**
	 * Leaves the switch off with a row kept, and not cut.
	 */
	private function on_then_off_in_the_studio(): void {
		$this->turn_on();
		$this->access->on_studio_off( self::event( StoreAccess::EVENT_OFF, 0 ) );
	}

	public function test_turning_off_that_cannot_be_saved_throws_and_still_tells_pixonaut(): void {
		$credential = $this->turn_on();
		$this->refuse_writes();
		$this->recorder->raw( 204, '', 'application/json' );

		$this->assert_not_saved( fn () => $this->access->turn_off() );

		$this->assertSame( 'DELETE', $this->recorder->requests[1]['method'] );
		$this->assertTrue( $this->access->accepts( $credential ), 'the hash is still there, which is what the admin must be told' );
	}

	public function test_turning_off_that_cannot_be_saved_throws_that_when_pixonaut_is_unreachable_too(): void {
		$this->turn_on();
		$this->refuse_writes();
		$this->recorder->fail();

		$this->assert_not_saved( fn () => $this->access->turn_off() );

		$this->assertCount( 2, $this->recorder->requests, 'tried' );
	}

	public function test_a_credential_that_cannot_be_saved_is_not_sent(): void {
		$this->refuse_writes();

		$this->assertFalse( $this->access->turn_on() );

		$this->assertTrue( $this->access->failed() );
		$this->assertSame( array(), $this->recorder->requests );

		$this->assert_not_saved( fn () => $this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) ) );

		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_studio_off_that_cannot_be_saved_fails_the_job(): void {
		$this->turn_on();
		$this->refuse_writes();

		$this->assert_not_saved( fn () => $this->access->on_studio_off( self::event( StoreAccess::EVENT_OFF ) ) );
	}

	public function test_a_stale_answer_that_cannot_be_taken_back_is_still_not_tried_again(): void {
		$this->refuse_writes( 2 );
		$this->recorder->json( 409, array( 'error' => 'store_access_request_stale' ) );

		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) );

		$this->assertCount( 1, $this->recorder->requests );
	}

	/**
	 * @param callable $call What must report that it could not save.
	 */
	private function assert_not_saved( callable $call ): void {
		try {
			$call();
		} catch ( ApiError $e ) {
			$this->fail( 'Pixonaut\'s answer is not what failed: ' . $e->getMessage() );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'could not be saved', $e->getMessage() );
			return;
		}
		$this->fail( 'It must say it could not save.' );
	}

	public function test_an_off_then_a_request_from_before_they_were_processed_ends_on(): void {
		$this->recorder->raw( 204, '', 'application/json' );

		$this->access->on_studio_off( self::event( StoreAccess::EVENT_OFF, -20 ) );
		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED, -10 ) );

		$this->assertTrue( $this->access->is_on() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_request_with_no_usable_request_id_sends_nothing(): void {
		$payloads = array(
			'missing'    => array(),
			'empty'      => array( 'requestId' => '' ),
			'not string' => array( 'requestId' => 42 ),
		);
		foreach ( $payloads as $case => $payload ) {
			$this->access->on_studio_requested( new Event( 'evt_1', StoreAccess::EVENT_REQUESTED, ( self::NOW + 1 ) * self::MILLISECONDS, $payload ) );

			$this->assertSame( array(), $this->recorder->requests, $case );
			$this->assertFalse( $this->access->is_on(), $case );
			$this->assertFalse( get_option( StoreAccess::OPTION ), $case );
		}
	}

	public function test_a_failed_turn_on_after_a_cut_stays_cut(): void {
		$this->turn_on();
		$this->recorder->raw( 204, '', 'application/json' );
		$this->access->turn_off();
		$this->recorder->fail();

		$this->assertFalse( $this->access->turn_on() );

		$this->assertTrue( $this->access->is_cut() );
	}

	public function test_the_studio_turning_it_off_revokes_it_and_it_can_turn_it_back_on(): void {
		$credential = $this->turn_on();
		$sent       = count( $this->recorder->requests );

		$this->access->on_studio_off( self::event( StoreAccess::EVENT_OFF ) );

		$this->assertFalse( $this->access->accepts( $credential ) );
		$this->assertFalse( $this->access->is_cut() );
		$this->assertCount( $sent, $this->recorder->requests, 'Pixonaut has deleted its copy already' );

		$this->recorder->raw( 204, '', 'application/json' );
		$this->access->on_studio_requested( self::event( StoreAccess::EVENT_REQUESTED ) );
		$this->assertTrue( $this->access->is_on() );
	}

	public function test_an_event_from_before_the_last_change_says_nothing(): void {
		$credential = $this->turn_on();

		$this->access->on_studio_off( self::event( StoreAccess::EVENT_OFF, -1 ) );

		$this->assertTrue( $this->access->accepts( $credential ) );
	}

	public function test_disconnecting_revokes_it(): void {
		$credential = $this->turn_on();
		$this->access->register();

		do_action( 'pixonaut_wc_connection_disconnected', true );
		remove_action( 'pixonaut_wc_connection_disconnected', array( $this->access, 'forget' ), 10 );

		$this->assertFalse( $this->access->accepts( $credential ) );
		$this->assertFalse( get_option( StoreAccess::OPTION ) );
	}

	public function test_a_new_connection_starts_off_and_not_cut(): void {
		$credential = $this->turn_on();
		$this->recorder->raw( 204, '', 'application/json' );
		$this->access->turn_off();

		$this->connect( 'site-b' );

		$this->assertFalse( $this->access->accepts( $credential ) );
		$this->assertFalse( $this->access->is_cut() );
	}

	public function test_pixonaut_refusing_the_stores_key_revokes_it(): void {
		$credential = $this->turn_on();

		$this->access->on_paused( Connection::PAUSED_UNAUTHORIZED );

		$this->assertFalse( $this->access->accepts( $credential ) );
	}

	public function test_a_copied_site_does_not_answer_with_the_credential_it_was_copied_with(): void {
		$credential = $this->turn_on();
		add_filter( 'home_url', static fn (): string => 'https://staging.example.org' );

		$fresh = new ConnectionStore( Cipher::for_site(), static fn (): string => 'https://staging.example.org' );
		$this->assertSame( ConnectionStore::PAUSED_SITE_CHANGED, $fresh->paused() );
		$copied = new StoreAccess( $fresh, new Connect( new Client( self::BASE_URL, Operations::plugin(), $fresh, new WpHttpTransport(), new WcLogger(), new Deprecations() ) ), new WcLogger() );

		$this->assertFalse( $copied->is_on() );
		$this->assertFalse( $copied->accepts( $credential ) );
	}

	public function test_a_connect_keeps_what_the_admin_chose_until_the_return(): void {
		$attempts = new ConnectAttempts();
		$created  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin    = is_int( $created ) ? $created : 0;

		$begun = $attempts->begin( $admin, 'http://example.org/return', self::NOW, true );
		$this->assertTrue( $attempts->take( $admin, $begun['state'], self::NOW )['store_access'] ?? null );

		$begun = $attempts->begin( $admin, 'http://example.org/return', self::NOW );
		$this->assertFalse( $attempts->take( $admin, $begun['state'], self::NOW )['store_access'] ?? null );
	}
}
