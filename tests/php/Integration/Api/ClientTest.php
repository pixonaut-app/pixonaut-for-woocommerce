<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Api;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operation;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\PreparedRequest;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\TransportFailure;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\StaticCredentials;
use WP_UnitTestCase;

final class ClientTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	/**
	 * Loopback too, outside the recorder's base, on a port nothing listens on.
	 */
	private const CLOSED_PORT_URL = 'http://127.0.0.1:1/';

	private const CONNECTION = array(
		'allowedOrigins'    => array( 'https://shop.acme.example' ),
		'connectionId'      => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'environment'       => 'sandbox',
		'name'              => 'WooCommerce · Acme Shop (shop.acme.example)',
		'siteUrl'           => 'https://shop.acme.example',
		'tenantId'          => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'        => 'Acme',
		'tenantSlug'        => ConnectionAnswers::TENANT_SLUG,
		'webhookEndpointId' => 'wh_5f2a9c1d7e3b4a6f8c0d2e4a',
	) + ConnectionAnswers::WORKSPACE_SETTINGS;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		parent::tear_down();
	}

	private function client(): Client {
		return new Client(
			self::BASE_URL,
			new Operations(
				array(
					new Operation( 'GET', '/v1/connect', Credential::IntegrationKey ),
					new Operation( 'POST', '/v1/designs', Credential::IntegrationKey, true ),
				)
			),
			new StaticCredentials(),
			new WpHttpTransport(),
			new WcLogger(),
			new Deprecations()
		);
	}

	public function test_get_connect_over_the_wordpress_http_api(): void {
		$this->recorder->json( 200, self::CONNECTION, array( 'Deprecation' => '@1798761600' ) );

		$connection = ( new Connect( $this->client() ) )->get();

		$this->assertSame( self::CONNECTION, $connection );
		$this->assertSame( 'Bearer ' . StaticCredentials::INTEGRATION_KEY, $this->recorder->requests[0]['headers']['Authorization'] );
		$this->assertSame( '@1798761600', ( new Deprecations() )->all()[ Operations::GET_CONNECT ]['deprecation'] );
	}

	public function test_requests_sent_together_pass_the_wordpress_http_api_filters(): void {
		$this->recorder->json( 200, self::CONNECTION );
		add_filter(
			'http_request_args',
			static function ( array $args ): array {
				$args['headers']['X-Filtered'] = 'yes';
				return $args;
			}
		);

		$answers = ( new WpHttpTransport() )->send_all(
			array(
				array(
					'method'  => 'GET',
					'url'     => self::BASE_URL . '/v1/connect',
					'headers' => array( 'Authorization' => 'Bearer ' . StaticCredentials::INTEGRATION_KEY ),
				),
				// Refused by the tests' host guard, a `pre_http_request` filter.
				array(
					'method'  => 'GET',
					'url'     => 'https://pixonaut.example/v1/connect',
					'headers' => array(),
				),
				// Not answered by a filter: sent, to a port nothing listens on.
				array(
					'method'  => 'GET',
					'url'     => self::CLOSED_PORT_URL,
					'headers' => array(),
				),
			),
			Client::TIMEOUT_SECONDS
		);

		$this->assertInstanceOf( \Pixonaut\WooCommerce\Api\Response::class, $answers[0] );
		$this->assertSame( 200, $answers[0]->status );
		$this->assertSame( 'yes', $this->recorder->requests[0]['headers']['X-Filtered'] );
		$this->assertInstanceOf( TransportFailure::class, $answers[1] );
		$this->assertStringContainsString( 'pixonaut.example', $answers[1]->getMessage() );
		$this->assertInstanceOf( TransportFailure::class, $answers[2] );
		$this->assertCount( 3, $answers );
	}

	public function test_each_request_sent_together_passes_the_curl_hooks(): void {
		$handles = 0;
		$count   = static function () use ( &$handles ): void {
			++$handles;
		};
		add_action( 'http_api_curl', $count );

		$answers = ( new WpHttpTransport() )->send_all( array( self::closed_port(), self::closed_port() ), Client::TIMEOUT_SECONDS );

		remove_action( 'http_api_curl', $count );
		$this->assertInstanceOf( TransportFailure::class, $answers[0] );
		$this->assertInstanceOf( TransportFailure::class, $answers[1] );
		$this->assertSame( 2, $handles );
	}

	public function test_an_unsafe_url_is_refused_before_it_is_sent(): void {
		$handles = 0;
		$count   = static function () use ( &$handles ): void {
			++$handles;
		};
		add_action( 'http_api_curl', $count );
		add_filter( 'http_request_reject_unsafe_urls', '__return_true' );

		$answers = ( new WpHttpTransport() )->send_all( array( self::closed_port() ), Client::TIMEOUT_SECONDS );

		remove_filter( 'http_request_reject_unsafe_urls', '__return_true' );
		remove_action( 'http_api_curl', $count );
		$this->assertInstanceOf( TransportFailure::class, $answers[0] );
		$this->assertSame( 0, $handles );
	}

	public function test_401_fires_the_unauthorized_action(): void {
		$this->recorder->json( 401, array( 'error' => 'unauthorized' ) );
		$fired = did_action( 'pixonaut_wc_api_unauthorized' );

		try {
			( new Connect( $this->client() ) )->get();
			$this->fail( 'Expected an ApiError.' );
		} catch ( ApiError $e ) {
			$this->assertSame( ErrorCodes::UNAUTHORIZED, $e->error_code );
		}
		$this->assertSame( $fired + 1, did_action( 'pixonaut_wc_api_unauthorized' ) );
	}

	public function test_a_snapshot_stored_in_the_database_resends_the_same_request(): void {
		$this->recorder
			->raw( 500, 'Internal Server Error', 'text/plain' )
			->json(
				201,
				array(
					'designId'      => '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
					'lowResolution' => array(),
					'printFloorDpi' => 150,
				)
			);
		$client  = $this->client();
		$request = $client->prepare(
			'POST /v1/designs',
			array(),
			array(),
			array(
				'proofToken'  => 'pt_1',
				'externalRef' => 'cart:42',
			),
			'wc:site:commit:abc'
		);
		try {
			$client->send( $request );
			$this->fail( 'Expected the first attempt to fail.' );
		} catch ( ApiError $e ) {
			$this->assertTrue( $e->retryable );
		}
		update_option( 'pixonaut_test_snapshot', $request->to_array() );

		$client->send( PreparedRequest::from_array( (array) get_option( 'pixonaut_test_snapshot' ) ) );

		[ $first, $second ] = $this->recorder->requests;
		$this->assertSame( $first['body'], $second['body'] );
		$this->assertSame( 'wc:site:commit:abc', $second['headers']['Idempotency-Key'] );
	}

	/**
	 * A GET no filter answers, to a port nothing listens on.
	 *
	 * @return array{method: string, url: string, headers: array<string, string>}
	 */
	private static function closed_port(): array {
		return array(
			'method'  => 'GET',
			'url'     => self::CLOSED_PORT_URL,
			'headers' => array(),
		);
	}
}
