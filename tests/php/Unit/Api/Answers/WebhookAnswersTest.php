<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\Answers\WebhookEndpoint;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use ReflectionClass;

final class WebhookAnswersTest extends TestCase {

	private const PINGED_AT = 1760000000;

	private ScriptedClient $transport;

	private Sdk $sdk;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		$this->transport = new ScriptedClient();
		$this->sdk       = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	public function test_reads_the_fields_of_a_listed_endpoint(): void {
		$this->transport->off_spec( self::answer( array( self::endpoint( array( 'failedCount' => 3 ) ) ) ) );

		$endpoints = WebhookEndpoint::all( $this->sdk );

		$this->assertCount( 1, $endpoints );
		$this->assertSame( 'wh_1', $endpoints[0]->id );
		$this->assertTrue( $endpoints[0]->active );
		$this->assertFalse( $endpoints[0]->failing );
		$this->assertSame( 3.0, $endpoints[0]->failed_count );
		$this->assertSame( 'https://shop.example/hook', $endpoints[0]->url );
		$this->assertTrue( $endpoints[0]->has_failed() );
	}

	public function test_an_inactive_endpoint_has_not_failed_whatever_it_counts(): void {
		$endpoint = self::endpoint(
			array(
				'active'      => false,
				'failedCount' => 3,
			)
		);
		$this->transport->off_spec( self::answer( array( $endpoint ) ) );

		$this->assertFalse( WebhookEndpoint::all( $this->sdk )[0]->has_failed() );
	}

	public function test_reads_a_listed_endpoint_without_fields_the_store_does_not_use(): void {
		$this->transport->off_spec( self::answer( array( array_diff_key( self::endpoint(), array_flip( array( 'reachable', 'eventTypes', 'tenantId', 'cursor' ) ) ) ) ) );

		$this->assertSame( 'wh_1', WebhookEndpoint::all( $this->sdk )[0]->id );
	}

	public function test_a_listed_endpoint_without_a_field_the_store_reads_is_unexpected(): void {
		$endpoint = self::endpoint();
		unset( $endpoint['failedCount'] );
		$this->transport->off_spec( self::answer( array( $endpoint ) ) );

		try {
			WebhookEndpoint::all( $this->sdk );
			$this->fail( 'An endpoint without its failed count was read.' );
		} catch ( ApiException $error ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $error->errorCode );
			$this->assertStringContainsString( '$[0]: missing required field "failedCount"', (string) $error->getPrevious()?->getMessage() );
		}
	}

	public function test_a_delivered_ping_is_stored_without_an_error(): void {
		$this->transport->off_spec( self::answer( array( 'delivered' => true ) ) );

		$this->assertSame(
			array(
				'delivered' => true,
				'error'     => '',
				'at'        => self::PINGED_AT,
			),
			$this->ping_and_stored()
		);
	}

	public function test_a_ping_that_was_not_delivered_is_stored_with_its_error(): void {
		$body = array(
			'delivered' => false,
			'error'     => 'HTTP 500',
		);
		$this->transport->off_spec( self::answer( $body ) );

		$this->assertSame(
			array(
				'delivered' => false,
				'error'     => 'HTTP 500',
				'at'        => self::PINGED_AT,
			),
			$this->ping_and_stored()
		);
	}

	public function test_a_refused_ping_is_stored_as_not_delivered(): void {
		$this->transport->off_spec( new Response( 429, array( 'content-type' => 'application/json' ), '{"error":"rate_limited"}' ) );

		$stored = $this->ping_and_stored();

		$this->assertFalse( $stored['delivered'] );
		$this->assertSame( 'Pixonaut did not send the test request (rate_limited).', $stored['error'] );
		$this->assertSame( self::PINGED_AT, $stored['at'] );
	}

	/**
	 * Sends the ping through an `Endpoint`, and returns what it stored.
	 *
	 * @return array{delivered: bool, error: string, at: int}
	 */
	private function ping_and_stored(): array {
		$stored = null;
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			static function ( string $option, mixed $value, mixed $autoload ) use ( &$stored ): bool {
				$stored = array( $option, $value, $autoload );
				return true;
			}
		);
		// Only the SDK and the clock take part in a ping.
		$class    = new ReflectionClass( Endpoint::class );
		$endpoint = $class->newInstanceWithoutConstructor();
		$class->getProperty( 'api' )->setValue( $endpoint, $this->sdk );
		$class->getProperty( 'clock' )->setValue( $endpoint, static fn (): int => self::PINGED_AT );

		$result = $endpoint->ping( self::connection() );

		$this->assertSame( array( Endpoint::PING_OPTION, $result, false ), $stored );
		return $result;
	}

	private static function connection(): StoredConnection {
		return new StoredConnection( 'site', 'https://shop.example', 0, 'ten_1', 'Acme', StoredConnection::LIVE, 'sc_1', ScriptedClient::INTEGRATION_KEY, 'ik_id', 'WooCommerce', 'pk_test', 'pk_id', array(), 'wh_1', 'https://shop.example/hook', 'whsec_test' );
	}

	/**
	 * @param array<string, mixed> $overrides Fields to set.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint( array $overrides = array() ): array {
		return $overrides + array(
			'active'       => true,
			'apiKeyId'     => null,
			'connectionId' => 'sc_1',
			'createdAt'    => 1760000000000,
			'cursor'       => null,
			'eventTypes'   => array( 'design.approved' ),
			'failedCount'  => 0,
			'failing'      => false,
			'id'           => 'wh_1',
			'reachable'    => true,
			'tenantId'     => 'ten_1',
			'url'          => 'https://shop.example/hook',
		);
	}

	/**
	 * @param array<mixed> $body The answer's body.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}
}
