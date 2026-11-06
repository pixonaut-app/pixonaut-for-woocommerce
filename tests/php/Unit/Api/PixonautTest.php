<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Brain\Monkey\Actions;
use InvalidArgumentException;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Config;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\ArrayCatalogue;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Operation;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use PHPUnit\Framework\AssertionFailedError;

final class PixonautTest extends TestCase {

	use FakeOptions;

	private const SHORT_TIMEOUT_SECONDS = 5;

	private const PNG = "\x89PNG\r\n\x1a\nbytes";

	private ScriptedClient $http;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		$this->http = new ScriptedClient();
	}

	public function test_every_operation_the_plugin_calls_is_consumed_with_its_key(): void {
		$consumed = OpenApiAssert::consumed();
		foreach ( Pixonaut::OPERATIONS as $id ) {
			$operation = Pixonaut::catalogue()->operation( $id );
			$name      = $operation->method . ' ' . $operation->path;
			$this->assertContains( $name, $consumed['operations'], "{$id} ({$name}) is missing from consumed.json." );
			$sent = $operation->anonymous ? null : ( $operation->accepts( Credential::IntegrationKey ) ? Credential::IntegrationKey->value : Credential::PublishableKey->value );
			$keys = (array) ( $consumed['keys'][ $name ] ?? array() );
			if ( null === $sent ) {
				$this->assertArrayNotHasKey( $name, $consumed['keys'], "{$name} is sent without a key." );
				continue;
			}
			$this->assertContains( $sent, $keys, "{$name} has the wrong keys entry in consumed.json." );
		}
	}

	public function test_the_sdk_sends_the_stores_key_and_names_the_plugin(): void {
		$this->http->json( 200, ConnectionAnswers::connection( true ) );

		$this->http->sdk()->connect()->get();

		$sent = $this->http->sent[0];
		$this->assertSame( ScriptedClient::BASE_URL . '/v1/connect', $sent['url'] );
		$this->assertSame( 'Bearer ' . ScriptedClient::INTEGRATION_KEY, $sent['headers']['Authorization'] );
		$this->assertStringStartsWith( 'pixonaut-for-woocommerce/' . Plugin::VERSION . ' pixonaut-php/', $sent['headers']['User-Agent'] );
		$this->assertSame( WpHttpTransport::TIMEOUT_SECONDS, $sent['timeout'] );
	}

	public function test_there_is_one_sdk_per_timeout(): void {
		$pixonaut = $this->http->pixonaut();
		$this->http->json( 200, ConnectionAnswers::connection( true ) );

		$this->assertSame( $pixonaut->sdk(), $pixonaut->sdk( WpHttpTransport::TIMEOUT_SECONDS ) );
		$this->assertNotSame( $pixonaut->sdk(), $pixonaut->sdk( self::SHORT_TIMEOUT_SECONDS ) );
		$pixonaut->sdk( Pixonaut::SHORT_TIMEOUT_SECONDS )->connect()->get();
		$this->assertSame( Pixonaut::SHORT_TIMEOUT_SECONDS, $this->http->sent[0]['timeout'] );
	}

	public function test_a_publishable_key_goes_out_from_the_sites_origin(): void {
		$this->http->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );

		$this->http->sdk()->templates()->getPreview( 'mini-tee@2', '600' );

		$this->assertSame( 'Bearer ' . ScriptedClient::PUBLISHABLE_KEY, $this->http->sent[0]['headers']['Authorization'] );
		$this->assertSame( ScriptedClient::ORIGIN, $this->http->sent[0]['headers']['Origin'] );
	}

	public function test_an_operation_the_plugin_does_not_call_is_never_sent(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->http->sdk()->apiKeys()->list();
	}

	public function test_all_sends_a_bounded_number_at_once_in_order(): void {
		$count = Pixonaut::MAX_CONCURRENT + 1;
		for ( $index = 0; $index < $count; ++$index ) {
			$this->http->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG . $index ) );
		}
		$pixonaut = $this->http->pixonaut();
		$requests = array_map( static fn ( int $index ) => $pixonaut->sdk()->prepare( 'templates.preview.get', array( 'id' => 'mini-tee@2' ), array( 'size' => 600 + $index ) ), range( 0, $count - 1 ) );

		$results = $pixonaut->all( $requests );

		$this->assertSame( array( Pixonaut::MAX_CONCURRENT, 1 ), array_map( 'count', $this->http->batches ) );
		foreach ( $results as $index => $result ) {
			$this->assertNotInstanceOf( ApiException::class, $result );
			$this->assertSame( self::PNG . $index, $result->body );
		}
	}

	public function test_all_answers_a_request_that_got_no_answer_as_a_retryable_failure_and_says_so(): void {
		$this->http->fail( 'cURL error 28: Operation timed out' );
		$this->http->respond( new Response( 404, array( 'content-type' => 'application/json' ), '{"error":"not_found"}' ) );
		$pixonaut = $this->http->pixonaut();
		$requests = array(
			$pixonaut->sdk()->prepare( 'templates.preview.get', array( 'id' => 'mini-tee@2' ) ),
			$pixonaut->sdk()->prepare( 'templates.preview.get', array( 'id' => 'gone@1' ) ),
		);
		Actions\expectDone( 'pixonaut_wc_api_after_request' )->twice();

		[ $lost, $refused ] = $pixonaut->all( $requests );

		$this->assertInstanceOf( ApiException::class, $lost );
		$this->assertSame( ApiException::TRANSPORT_FAILED, $lost->errorCode );
		$this->assertSame( 0, $lost->status );
		$this->assertTrue( $lost->retryable );
		$this->assertSame( array( 'message' => 'cURL error 28: Operation timed out' ), $lost->details );
		$this->assertInstanceOf( ApiException::class, $refused );
		$this->assertSame( 404, $refused->status );
	}

	public function test_all_refuses_a_request_that_takes_a_key(): void {
		$pixonaut = $this->http->pixonaut();
		$commit   = $pixonaut->sdk()->prepare( 'designs.commit', body: array( 'proofToken' => 'pt_1' ) );

		$this->expectException( InvalidArgumentException::class );

		$pixonaut->all( array( $commit ) );
	}

	public function test_all_refuses_a_write_that_takes_no_key(): void {
		$pixonaut = $this->http->pixonaut();
		$approve  = $pixonaut->sdk()->prepare( 'designs.approve', array( 'id' => 'des_1' ), body: array( 'expectedRevision' => 2 ) );

		try {
			$pixonaut->all( array( $approve ) );
			$this->fail( 'A write was sent in a batch, which sends no body.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( array(), $this->http->sent );
		}
	}

	public function test_an_operation_missing_from_consumed_json_fails_the_test(): void {
		$path    = OpenApiAssert::unconsumed_get_path();
		$factory = new Psr17Factory();
		$sdk     = new Sdk(
			new Config( ScriptedClient::BASE_URL ),
			$this->http,
			$factory,
			$factory,
			ScriptedClient::credentials(),
			new ArrayCatalogue( array( new Operation( 'unconsumed', 'GET', $path, array( Credential::IntegrationKey ) ) ) ),
			new RecordingLogger()
		);

		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( "GET {$path} is not in contracts/pixonaut/consumed.json" );
		$sdk->call( 'unconsumed' );
	}
}
