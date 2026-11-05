<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\IdempotencyBug;
use Pixonaut\WooCommerce\Api\Operation;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\PreparedRequest;
use Pixonaut\WooCommerce\Api\Response;
use Pixonaut\WooCommerce\Connection\Pkce;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedTransport;
use Pixonaut\WooCommerce\Tests\Support\StaticCredentials;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;

final class ClientTest extends TestCase {

	use FakeOptions;

	private const BASE_URL = 'http://pixonaut.test';

	private const NOW = 1_800_000_000;

	private const CREATE_DESIGN = 'POST /v1/designs';

	/**
	 * A key in a body that must not reach an error or the log.
	 */
	private const LEAKED_KEY = 'ik_live_SENTINEL0123456789abcdef';

	private const CONNECTION = array(
		'allowedOrigins'    => array( 'https://shop.acme.example' ),
		'connectionId'      => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'environment'       => 'live',
		'name'              => 'WooCommerce · Acme Shop (shop.acme.example)',
		'siteUrl'           => 'https://shop.acme.example',
		'tenantId'          => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'        => 'Acme',
		'tenantSlug'        => ConnectionAnswers::TENANT_SLUG,
		'webhookEndpointId' => 'wh_5f2a9c1d7e3b4a6f8c0d2e4a',
	) + ConnectionAnswers::WORKSPACE_SETTINGS;

	private const DESIGN_BODY = array(
		'proofToken'  => 'pt_1',
		'externalRef' => 'cart:42',
	);

	private const DESIGN_CREATED = array(
		'designId'      => '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
		'lowResolution' => array(),
		'printFloorDpi' => 150,
	);

	private ScriptedTransport $transport;

	private RecordingLogger $logger;

	/**
	 * @var list<int>
	 */
	private array $slept = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		$this->transport = new ScriptedTransport();
		$this->logger    = new RecordingLogger();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\when( 'wp_generate_uuid4' )->justReturn( '5b0d7c1e-8f2a-4c3b-9d4e-1a2b3c4d5e6f' );
	}

	private function client( ?Operations $operations = null, ?StaticCredentials $credentials = null ): Client {
		return new Client(
			self::BASE_URL,
			$operations ?? new Operations(
				array(
					new Operation( 'GET', '/v1/connect', Credential::IntegrationKey ),
					new Operation( 'POST', '/v1/designs', Credential::IntegrationKey, true ),
				)
			),
			$credentials ?? new StaticCredentials(),
			$this->transport,
			$this->logger,
			new Deprecations(),
			function ( int $seconds ): void {
				$this->slept[] = $seconds;
			},
			static fn (): int => self::NOW
		);
	}

	public function test_get_connect_sends_the_integration_key_and_decodes_the_answer(): void {
		$this->transport->json( 200, self::CONNECTION );

		$connection = ( new Connect( $this->client() ) )->get();

		$this->assertSame( self::CONNECTION, $connection );
		$sent = $this->transport->sent[0];
		$this->assertSame( 'GET', $sent['method'] );
		$this->assertSame( self::BASE_URL . '/v1/connect', $sent['url'] );
		$this->assertSame( 'Bearer ' . StaticCredentials::INTEGRATION_KEY, $sent['headers']['Authorization'] );
		$this->assertSame( 'pixonaut-for-woocommerce/' . Plugin::VERSION, $sent['headers']['User-Agent'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $sent['headers'] );
		$this->assertNull( $sent['body'] );
	}

	public function test_fires_actions_around_every_call(): void {
		Actions\expectDone( 'pixonaut_wc_api_before_request' )->once()->with( \Mockery::type( PreparedRequest::class ) );
		Actions\expectDone( 'pixonaut_wc_api_after_request' )->once()->with( \Mockery::type( PreparedRequest::class ), \Mockery::type( Response::class ), null );
		$this->transport->json( 200, self::CONNECTION );

		$this->client()->call( Operations::GET_CONNECT );
	}

	public function test_call_all_fires_actions_around_every_call(): void {
		Actions\expectDone( 'pixonaut_wc_api_before_request' )->twice()->with( \Mockery::type( PreparedRequest::class ) );
		Actions\expectDone( 'pixonaut_wc_api_after_request' )->twice()->with( \Mockery::type( PreparedRequest::class ), \Mockery::type( Response::class ), null );
		$this->transport->json( 200, self::CONNECTION );
		$this->transport->json( 200, self::CONNECTION );

		$answers = $this->client()->call_all( array( array( Operations::GET_CONNECT, array(), array() ), array( Operations::GET_CONNECT, array(), array() ) ) );

		$this->assertCount( 2, $answers );
		$this->assertSame( array( array( self::BASE_URL . '/v1/connect', self::BASE_URL . '/v1/connect' ) ), $this->transport->batches );
	}

	public function test_call_all_refuses_an_operation_that_takes_a_key(): void {
		$this->expectException( \InvalidArgumentException::class );

		try {
			$this->client()->call_all( array( array( self::CREATE_DESIGN, array(), array() ) ) );
		} finally {
			$this->assertSame( array(), $this->transport->sent );
		}
	}

	public function test_known_error_code_maps_to_a_typed_error_without_logging(): void {
		$this->transport->json(
			403,
			array(
				'error'   => 'feature_disabled',
				'feature' => 'api',
			)
		);

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertSame( 403, $error->status );
		$this->assertSame( ErrorCodes::FEATURE_DISABLED, $error->error_code );
		$this->assertSame( array( 'feature' => 'api' ), $error->details );
		$this->assertFalse( $error->retryable );
		$this->assertSame( array(), $this->logger->entries );
	}

	public function test_unknown_code_and_status_are_kept_and_logged(): void {
		$this->transport->off_spec( new Response( 418, array(), '{"error":"teapot_mode","hint":"brew"}' ) );

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertSame( 418, $error->status );
		$this->assertSame( 'teapot_mode', $error->error_code );
		$this->assertSame( array( 'hint' => 'brew' ), $error->details );
		$this->assertSame( 'error', $this->logger->entries[0]['level'] );
		$this->assertSame( 'teapot_mode', $this->logger->entries[0]['context']['code'] );
	}

	public function test_non_json_500_is_a_retryable_logged_unexpected_response(): void {
		$this->transport->respond( new Response( 500, array( 'content-type' => 'text/plain' ), 'Internal Server Error' ) );

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertSame( 500, $error->status );
		$this->assertSame( ErrorCodes::UNEXPECTED_RESPONSE, $error->error_code );
		$this->assertTrue( $error->retryable );
		$this->assertSame( 'Internal Server Error', $error->details['body'] );
		$this->assertCount( 1, $this->logger->entries );
	}

	public function test_unknown_status_is_logged_even_with_a_known_code(): void {
		$this->transport->off_spec( new Response( 418, array(), '{"error":"bad_request"}' ) );

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertSame( 418, $error->status );
		$this->assertSame( ErrorCodes::BAD_REQUEST, $error->error_code );
		$this->assertSame( 418, $this->logger->entries[0]['context']['status'] );
	}

	public function test_a_json_list_is_not_a_successful_object_answer(): void {
		$this->transport->off_spec( new Response( 200, array( 'content-type' => 'application/json' ), '[]' ) );

		try {
			( new Connect( $this->client() ) )->get();
			$this->fail( 'Expected an ApiError.' );
		} catch ( ApiError $e ) {
			$this->assertSame( ErrorCodes::UNEXPECTED_RESPONSE, $e->error_code );
		}
		$this->assertCount( 1, $this->logger->entries );
	}

	public function test_rate_limit_carries_retry_after(): void {
		$this->transport->off_spec( new Response( 429, array( 'Retry-After' => '7' ), '{"error":"rate_limited"}' ) );

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertTrue( $error->retryable );
		$this->assertSame( 7, $error->retry_after );
	}

	public function test_transport_failure_is_a_retryable_error(): void {
		Actions\expectDone( 'pixonaut_wc_api_after_request' )->once()->with( \Mockery::type( PreparedRequest::class ), null, \Mockery::type( ApiError::class ) );
		$this->transport->fail( 'cURL error 28: Operation timed out' );

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertSame( 0, $error->status );
		$this->assertSame( ErrorCodes::TRANSPORT_FAILED, $error->error_code );
		$this->assertTrue( $error->retryable );
	}

	public function test_401_fires_the_unauthorized_action(): void {
		Actions\expectDone( 'pixonaut_wc_api_unauthorized' )->once()->with( Operations::GET_CONNECT, \Mockery::type( ApiError::class ) );
		$this->transport->json( 401, array( 'error' => 'unauthorized' ) );

		$error = $this->call_and_catch( Operations::GET_CONNECT );

		$this->assertSame( ErrorCodes::UNAUTHORIZED, $error->error_code );
	}

	public function test_refuses_to_send_without_a_credential(): void {
		$error = null;
		try {
			$this->client( null, new StaticCredentials( null ) )->call( Operations::GET_CONNECT );
		} catch ( ApiError $e ) {
			$error = $e;
		}

		$this->assertNotNull( $error );
		$this->assertSame( ErrorCodes::NOT_CONNECTED, $error->error_code );
		$this->assertSame( array(), $this->transport->sent );
	}

	public function test_writes_send_an_idempotency_key(): void {
		$this->transport->json( 201, self::DESIGN_CREATED );

		$this->client()->call( self::CREATE_DESIGN, array(), array(), self::DESIGN_BODY, 'wc:site:commit:abc' );

		$this->assertSame( 'wc:site:commit:abc', $this->transport->sent[0]['headers']['Idempotency-Key'] );
		$this->assertSame( 'application/json', $this->transport->sent[0]['headers']['Content-Type'] );
	}

	public function test_a_write_without_a_given_key_gets_a_uuid(): void {
		$request = $this->client()->prepare( self::CREATE_DESIGN, array(), array(), self::DESIGN_BODY );

		$this->assertSame( '5b0d7c1e-8f2a-4c3b-9d4e-1a2b3c4d5e6f', $request->idempotency_key );
	}

	public function test_key_in_use_waits_and_resends_the_same_request(): void {
		$this->transport
			->json( 409, array( 'error' => 'idempotency_key_in_use' ), array( 'Retry-After' => '2' ) )
			->json( 201, self::DESIGN_CREATED );

		$response = $this->client()->call( self::CREATE_DESIGN, array(), array(), self::DESIGN_BODY, 'k1' );

		$this->assertSame( 201, $response->status );
		$this->assertSame( array( 2 ), $this->slept );
		$this->assertCount( 2, $this->transport->sent );
		$this->assertSame( $this->transport->sent[0]['body'], $this->transport->sent[1]['body'] );
		$this->assertSame( $this->transport->sent[0]['headers'], $this->transport->sent[1]['headers'] );
	}

	public function test_key_in_use_gives_up_after_the_bound(): void {
		for ( $i = 0; $i <= Client::MAX_IN_USE_RESENDS; ++$i ) {
			$this->transport->json( 409, array( 'error' => 'idempotency_key_in_use' ) );
		}

		$error = $this->call_and_catch( self::CREATE_DESIGN, self::DESIGN_BODY );

		$this->assertSame( ErrorCodes::IDEMPOTENCY_KEY_IN_USE, $error->error_code );
		$this->assertTrue( $error->retryable );
		$this->assertCount( Client::MAX_IN_USE_RESENDS + 1, $this->transport->sent );
		$this->assertSame( 0, $this->transport->remaining() );
	}

	/**
	 * @dataProvider idempotency_bug_codes
	 *
	 * @param string $code Error code.
	 */
	public function test_idempotency_refusals_are_bugs( string $code ): void {
		$this->transport->json(
			422,
			array(
				'error'     => $code,
				'retryable' => true,
			)
		);

		$error = $this->call_and_catch( self::CREATE_DESIGN, self::DESIGN_BODY );

		$this->assertInstanceOf( IdempotencyBug::class, $error );
		$this->assertSame( $code, $error->error_code );
		$this->assertFalse( $error->retryable );
		$this->assertCount( 1, $this->transport->sent );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function idempotency_bug_codes(): array {
		return array(
			'reused'  => array( ErrorCodes::IDEMPOTENCY_KEY_REUSED ),
			'invalid' => array( ErrorCodes::INVALID_IDEMPOTENCY_KEY ),
		);
	}

	public function test_snapshot_resend_sends_the_same_body_and_key(): void {
		Filters\expectApplied( 'pixonaut_wc_api_request_body' )->once()->andReturnUsing(
			static fn ( array $body ): array => $body + array( 'printshopId' => 'ps_1' )
		);
		$this->transport->json( 500, array( 'error' => 'db_error' ) )->json( 201, self::DESIGN_CREATED );
		$client  = $this->client();
		$request = $client->prepare( self::CREATE_DESIGN, array(), array(), self::DESIGN_BODY );
		try {
			$client->send( $request );
			$this->fail( 'Expected the first attempt to fail.' );
		} catch ( ApiError $e ) {
			$this->assertTrue( $e->retryable );
		}

		$stored = json_decode( (string) json_encode( $request->to_array() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$client->send( PreparedRequest::from_array( $stored ) );

		[ $first, $second ] = $this->transport->sent;
		$this->assertSame( $first['body'], $second['body'] );
		$this->assertSame( $first['headers']['Idempotency-Key'], $second['headers']['Idempotency-Key'] );
		$this->assertSame( 'ps_1', json_decode( (string) $second['body'], true )['printshopId'] );
	}

	public function test_a_snapshot_that_lost_its_key_is_refused(): void {
		$stored = $this->client()->prepare( self::CREATE_DESIGN, array(), array(), self::DESIGN_BODY )->to_array();
		unset( $stored['idempotency_key'] );

		$this->expectException( \InvalidArgumentException::class );
		PreparedRequest::from_array( $stored );
	}

	public function test_a_write_without_a_key_is_not_sent(): void {
		$request = new PreparedRequest( self::CREATE_DESIGN, '/v1/designs', '{"proofToken":"pt_1"}', null );

		try {
			$this->client()->send( $request );
			$this->fail( 'Expected the request to be refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( array(), $this->transport->sent );
		}
	}

	public function test_a_request_for_another_path_is_not_sent(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->client()->send( new PreparedRequest( Operations::GET_CONNECT, '/v1/designs' ) );
	}

	public function test_an_invalid_key_is_refused_when_preparing(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->client()->prepare( self::CREATE_DESIGN, array(), array(), self::DESIGN_BODY, "bad key\n" );
	}

	public function test_known_statuses_are_the_error_statuses_the_spec_declares(): void {
		$spec     = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/contracts/pixonaut/openapi.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$statuses = array();
		foreach ( $spec['paths'] as $operations ) {
			foreach ( $operations as $operation ) {
				foreach ( array_keys( $operation['responses'] ?? array() ) as $status ) {
					if ( (int) $status >= 400 ) {
						$statuses[ (int) $status ] = true;
					}
				}
			}
		}
		$statuses = array_keys( $statuses );
		sort( $statuses );

		$this->assertSame( $statuses, ErrorCodes::KNOWN_STATUSES );
	}

	public function test_deprecation_is_recorded_and_logged_once(): void {
		$headers = array(
			'Deprecation' => '@1798761600',
			'Sunset'      => 'Fri, 01 Jun 2027 00:00:00 GMT',
		);
		$this->transport->json( 200, self::CONNECTION, $headers )->json( 200, self::CONNECTION, $headers );
		$client = $this->client();

		$client->call( Operations::GET_CONNECT );
		$client->call( Operations::GET_CONNECT );

		$this->assertSame(
			array(
				Operations::GET_CONNECT => array(
					'deprecation' => '@1798761600',
					'sunset'      => 'Fri, 01 Jun 2027 00:00:00 GMT',
					'seen_at'     => self::NOW,
				),
			),
			( new Deprecations() )->all()
		);
		$this->assertCount( 1, $this->logger->entries );
		$this->assertSame( 'warning', $this->logger->entries[0]['level'] );
	}

	public function test_an_operation_missing_from_consumed_json_fails_the_test(): void {
		$path   = OpenApiAssert::unconsumed_get_path();
		$client = $this->client( new Operations( array( new Operation( 'GET', $path, Credential::IntegrationKey ) ) ) );

		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( "GET {$path} is not in contracts/pixonaut/consumed.json" );
		$client->call( 'GET ' . $path );
	}

	public function test_a_truncated_token_answer_keeps_no_secret_in_the_error_or_the_log(): void {
		$this->transport->off_spec( new Response( 201, array( 'content-type' => 'application/json' ), '{"integrationKey":{"key":"' . self::LEAKED_KEY ) );

		$error = $this->exchange_and_catch();

		$this->assertSame( ErrorCodes::UNEXPECTED_RESPONSE, $error->error_code );
		$this->assertSame( array( 'redacted' => true ), $error->details );
		$this->assertNotEmpty( $this->logger->entries );
		$this->assertStringNotContainsString( 'SENTINEL', (string) json_encode( $this->logger->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_an_unexpected_token_refusal_keeps_no_secret_in_the_error_or_the_log(): void {
		$this->transport->off_spec( new Response( 500, array( 'content-type' => 'text/plain' ), 'debug: ' . self::LEAKED_KEY ) );

		$error = $this->exchange_and_catch();

		$this->assertSame( 500, $error->status );
		$this->assertStringNotContainsString( 'SENTINEL', (string) json_encode( $error->details ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertNotEmpty( $this->logger->entries );
		$this->assertStringNotContainsString( 'SENTINEL', (string) json_encode( $this->logger->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private function exchange_and_catch(): ApiError {
		try {
			( new Connect( $this->client( Operations::plugin() ) ) )->exchange( 'c_1', Pkce::verifier(), 'https://shop.acme.example/wp-admin/admin-post.php' );
		} catch ( ApiError $e ) {
			return $e;
		}
		$this->fail( 'Expected an ApiError.' );
	}

	public function test_refuses_an_unregistered_operation(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->client()->call( 'GET /v1/templates' );
	}

	/**
	 * @param string                    $operation Operation id.
	 * @param array<string, mixed>|null $body      Body.
	 */
	private function call_and_catch( string $operation, ?array $body = null ): ApiError {
		try {
			$this->client()->call( $operation, array(), array(), $body );
		} catch ( ApiError $e ) {
			return $e;
		}
		$this->fail( 'Expected an ApiError.' );
	}
}
