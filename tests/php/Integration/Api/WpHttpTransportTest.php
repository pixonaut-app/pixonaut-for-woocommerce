<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Api;

use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\NetworkFailure;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\StoredRequest;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Request;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use WP_UnitTestCase;

final class WpHttpTransportTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	/**
	 * Loopback too, outside the recorder's base, on a port nothing listens on.
	 */
	private const CLOSED_PORT_URL = 'http://127.0.0.1:1/';

	private const SHORT_TIMEOUT_SECONDS = 3;

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

	/**
	 * How long `slow_server()` takes to answer, in seconds.
	 */
	private const SLOW_ANSWER_SECONDS = 1;

	/**
	 * The share of a wait a request may keep the CPU busy for: Requests'
	 * own loop over `curl_multi_exec()` keeps it busy for all of it.
	 */
	private const BUSY_SHARE = 0.25;

	/**
	 * How long `slow_server()` waits for PHP's server to listen, in microseconds per try, and how many tries.
	 */
	private const LISTEN_WAIT_MICROSECONDS = 50000;

	private const LISTEN_TRIES = 100;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		parent::tear_down();
	}

	private function pixonaut(): Pixonaut {
		return new Pixonaut( ScriptedClient::credentials(), new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL );
	}

	private function sdk(): Sdk {
		return $this->pixonaut()->sdk();
	}

	public function test_get_connect_over_the_wordpress_http_api(): void {
		$this->recorder->json( 200, self::CONNECTION, array( 'Deprecation' => '@1798761600' ) );

		$connection = $this->sdk()->connect()->get();

		$this->assertSame( self::CONNECTION, $connection->raw() );
		$sent = $this->recorder->requests[0];
		$this->assertSame( 'GET', $sent['method'] );
		$this->assertSame( self::BASE_URL . '/v1/connect', $sent['url'] );
		$this->assertSame( 'Bearer ' . ScriptedClient::INTEGRATION_KEY, $sent['headers']['Authorization'] );
		$this->assertSame( 'application/json', $sent['headers']['Accept'] );
		$this->assertStringStartsWith( 'pixonaut-for-woocommerce/', $sent['headers']['User-Agent'] );
		$this->assertSame( (float) WpHttpTransport::TIMEOUT_SECONDS, $sent['timeout'] );
		$this->assertSame( '@1798761600', ( new Deprecations() )->all()['connectedStore.get']['deprecation'] );
	}

	public function test_a_body_and_its_headers_are_sent_as_built(): void {
		$this->recorder->json(
			201,
			array(
				'designId'      => '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
				'lowResolution' => array(),
				'printFloorDpi' => 150,
			)
		);

		$this->sdk()->designs()->commit( new DesignsCommitRequestOption1( proofToken: 'pt_1', externalRef: 'cart:42' ), idempotencyKey: 'wc:site:commit:abc' );

		$sent = $this->recorder->requests[0];
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertSame( '{"proofToken":"pt_1","externalRef":"cart:42"}', $sent['body'] );
		$this->assertSame( 'application/json', $sent['headers']['Content-Type'] );
		$this->assertSame( 'wc:site:commit:abc', $sent['headers']['Idempotency-Key'] );
	}

	public function test_each_client_gives_up_after_its_own_timeout(): void {
		$this->recorder->json( 200, self::CONNECTION );

		$this->pixonaut()->sdk( self::SHORT_TIMEOUT_SECONDS )->connect()->get();

		$this->assertSame( (float) self::SHORT_TIMEOUT_SECONDS, $this->recorder->requests[0]['timeout'] );
	}

	public function test_a_request_that_gets_no_answer_is_a_transport_failure(): void {
		$this->recorder->fail( 'cURL error 28: Operation timed out' );

		try {
			$this->sdk()->connect()->get();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::TRANSPORT_FAILED, $e->errorCode );
			$this->assertSame( 0, $e->status );
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_a_request_wordpress_refuses_is_a_network_failure(): void {
		// The tests' host guard refuses a host off this machine, as a `pre_http_request` filter blocking it would.
		$this->expectException( NetworkFailure::class );
		$this->expectExceptionMessage( 'pixonaut.example' );

		( new WpHttpTransport() )->sendRequest( new Request( 'GET', 'https://pixonaut.example/v1/connect' ) );
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
				new Request( 'GET', self::BASE_URL . '/v1/connect', array( 'Authorization' => 'Bearer ' . ScriptedClient::INTEGRATION_KEY ) ),
				// Refused by the tests' host guard, a `pre_http_request` filter.
				new Request( 'GET', 'https://pixonaut.example/v1/connect' ),
				// Not answered by a filter: sent, to a port nothing listens on.
				new Request( 'GET', self::CLOSED_PORT_URL ),
			)
		);

		$this->assertInstanceOf( ResponseInterface::class, $answers[0] );
		$this->assertSame( 200, $answers[0]->getStatusCode() );
		$this->assertSame( 'yes', $this->recorder->requests[0]['headers']['X-Filtered'] );
		$this->assertInstanceOf( NetworkFailure::class, $answers[1] );
		$this->assertStringContainsString( 'pixonaut.example', $answers[1]->getMessage() );
		$this->assertInstanceOf( NetworkFailure::class, $answers[2] );
		$this->assertCount( 3, $answers );
	}

	public function test_each_request_sent_together_passes_the_curl_hooks(): void {
		$handles = 0;
		$count   = static function () use ( &$handles ): void {
			++$handles;
		};
		add_action( 'http_api_curl', $count );

		$answers = ( new WpHttpTransport() )->send_all( array( new Request( 'GET', self::CLOSED_PORT_URL ), new Request( 'GET', self::CLOSED_PORT_URL ) ) );

		remove_action( 'http_api_curl', $count );
		$this->assertInstanceOf( NetworkFailure::class, $answers[0] );
		$this->assertInstanceOf( NetworkFailure::class, $answers[1] );
		$this->assertSame( 2, $handles );
	}

	public function test_requests_sent_together_wait_for_their_answers_without_keeping_a_cpu_busy(): void {
		$server = $this->slow_server();
		try {
			$started = microtime( true );
			$cpu     = self::cpu_seconds();
			$answers = ( new WpHttpTransport() )->send_all( array( new Request( 'GET', $server['url'] ) ) );
			$used    = self::cpu_seconds() - $cpu;
			$waited  = microtime( true ) - $started;
		} finally {
			proc_terminate( $server['process'] );
			proc_close( $server['process'] );
		}

		$this->assertInstanceOf( ResponseInterface::class, $answers[0] );
		$this->assertSame( 'drawn', (string) $answers[0]->getBody() );
		$this->assertGreaterThanOrEqual( self::SLOW_ANSWER_SECONDS, $waited );
		$this->assertLessThan( self::SLOW_ANSWER_SECONDS * self::BUSY_SHARE, $used, 'the wait sleeps rather than spinning' );
	}

	public function test_an_unsafe_url_is_refused_before_it_is_sent(): void {
		$handles = 0;
		$count   = static function () use ( &$handles ): void {
			++$handles;
		};
		add_action( 'http_api_curl', $count );
		add_filter( 'http_request_reject_unsafe_urls', '__return_true' );

		$answers = ( new WpHttpTransport() )->send_all( array( new Request( 'GET', self::CLOSED_PORT_URL ) ) );

		remove_filter( 'http_request_reject_unsafe_urls', '__return_true' );
		remove_action( 'http_api_curl', $count );
		$this->assertInstanceOf( NetworkFailure::class, $answers[0] );
		$this->assertSame( 0, $handles );
	}

	public function test_401_fires_the_unauthorized_action(): void {
		$this->recorder->json( 401, array( 'error' => 'unauthorized' ) );
		$fired = did_action( 'pixonaut_wc_api_unauthorized' );

		try {
			$this->sdk()->connect()->get();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ErrorCode::UNAUTHORIZED, $e->errorCode );
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
		$designs = $this->sdk()->designs();
		$request = $designs->prepareCommit( new DesignsCommitRequestOption1( proofToken: 'pt_1', externalRef: 'cart:42' ), idempotencyKey: 'wc:site:commit:abc' );
		try {
			$designs->sendCommit( $request );
			$this->fail( 'Expected the first attempt to fail.' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->retryable );
		}
		update_option( 'pixonaut_test_snapshot', $request->toArray() );

		$designs->sendCommit( StoredRequest::restore( (array) get_option( 'pixonaut_test_snapshot' ) ) );

		[ $first, $second ] = $this->recorder->requests;
		$this->assertSame( $first['body'], $second['body'] );
		$this->assertSame( 'wc:site:commit:abc', $second['headers']['Idempotency-Key'] );
	}

	public function test_a_snapshot_the_plugin_kept_before_the_sdk_resends_the_same_request(): void {
		$this->recorder->json(
			201,
			array(
				'designId'      => '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
				'lowResolution' => array(),
				'printFloorDpi' => 150,
			)
		);
		$legacy = array(
			'operation'       => 'POST /v1/designs',
			'target'          => '/v1/designs',
			'body'            => '{"proofToken":"pt_1","externalRef":"cart:42"}',
			'idempotency_key' => 'wc:site:commit:abc',
		);

		$this->sdk()->designs()->sendCommit( StoredRequest::restore( $legacy ) );

		$sent = $this->recorder->requests[0];
		$this->assertSame( $legacy['body'], $sent['body'] );
		$this->assertSame( 'wc:site:commit:abc', $sent['headers']['Idempotency-Key'] );
	}

	/**
	 * PHP's own server on a free loopback port, answering `drawn` after
	 * `SLOW_ANSWER_SECONDS`, as Pixonaut answers once it has drawn a picture.
	 *
	 * @return array{url: string, process: resource}
	 */
	private function slow_server(): array {
		$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
		$this->assertIsResource( $probe );
		$address = (string) stream_socket_get_name( $probe, false );
		fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket, not a file.
		$router = get_temp_dir() . 'pixonaut-slow-answer.php';
		file_put_contents( $router, '<?php sleep( ' . self::SLOW_ANSWER_SECONDS . " ); echo 'drawn';" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test's own script.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- a test's own server.
		$process = proc_open( array( PHP_BINARY, '-S', $address, $router ), array( array( 'pipe', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		for ( $try = 0; $try < self::LISTEN_TRIES; ++$try ) {
			$socket = @stream_socket_client( 'tcp://' . $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refused until the server listens.
			if ( false !== $socket ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket, not a file.
				return array(
					'url'     => 'http://' . $address . '/',
					'process' => $process,
				);
			}
			usleep( self::LISTEN_WAIT_MICROSECONDS ); // real-timer: waits for a real server process to listen.
		}
		proc_terminate( $process );
		$this->fail( 'PHP\'s server did not start.' );
	}

	/**
	 * The CPU time this process has used, in seconds.
	 *
	 * @throws \RuntimeException When PHP cannot read it.
	 */
	private static function cpu_seconds(): float {
		$usage = getrusage();
		if ( false === $usage ) {
			throw new \RuntimeException( 'getrusage() failed.' );
		}
		return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ( $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'] ) / 1e6;
	}
}
