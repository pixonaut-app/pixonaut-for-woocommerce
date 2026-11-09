<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreExchange;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\MemberKeys;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

final class ConnectedStoreExchangeTest extends TestCase {

	private const EXCHANGED = array(
		'tenantId'       => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'     => 'Acme',
		'tenantSlug'     => ConnectionAnswers::TENANT_SLUG,
		'environment'    => 'sandbox',
		'connectionId'   => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'integrationKey' => array(
			'id'    => '0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'key'   => 'ik_test_0123456789abcdef01234567',
			'label' => 'WooCommerce · Acme Shop (shop.acme.example)',
		),
		'publishableKey' => array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			'key'            => 'pk_test_89abcdef0123456789abcdef',
			'allowedOrigins' => array( 'https://shop.acme.example' ),
		),
		'webhook'        => array(
			'id'     => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'url'    => 'https://shop.acme.example/wp-json/pixonaut/v1/webhook',
			'secret' => 'whsec_0123456789abcdef0123456789abcdef',
		),
		'memberKey'      => MemberKeys::ANSWER,
		'reachable'      => true,
	);

	private ScriptedClient $transport;

	private Sdk $sdk;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new ScriptedClient();
		$this->sdk       = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	public function test_reads_the_fields_the_store_uses(): void {
		$this->transport->json( 201, self::EXCHANGED );

		$exchanged = $this->exchange();

		$this->assertSame( self::EXCHANGED['connectionId'], $exchanged->connection_id );
		$this->assertSame( 'sandbox', $exchanged->environment );
		$this->assertSame( self::EXCHANGED['integrationKey']['id'], $exchanged->integration_key->id );
		$this->assertSame( self::EXCHANGED['integrationKey']['key'], $exchanged->integration_key->key );
		$this->assertSame( self::EXCHANGED['integrationKey']['label'], $exchanged->integration_key->label );
		$this->assertSame( array( 'https://shop.acme.example' ), $exchanged->publishable_key->allowed_origins );
		$this->assertSame( self::EXCHANGED['publishableKey']['id'], $exchanged->publishable_key->id );
		$this->assertSame( self::EXCHANGED['publishableKey']['key'], $exchanged->publishable_key->key );
		$this->assertSame( self::EXCHANGED['tenantId'], $exchanged->tenant_id );
		$this->assertSame( 'Acme', $exchanged->tenant_name );
		$this->assertSame( ConnectionAnswers::TENANT_SLUG, $exchanged->tenant_slug );
		$this->assertSame( self::EXCHANGED['webhook']['id'], $exchanged->webhook->id );
		$this->assertSame( self::EXCHANGED['webhook']['secret'], $exchanged->webhook->secret );
		$this->assertSame( self::EXCHANGED['webhook']['url'], $exchanged->webhook->url );
		$this->assertStringEndsWith( '/v1/connect/token', $this->transport->sent[0]['url'] );
		$this->assertSame( 'c_code', json_decode( (string) $this->transport->sent[0]['body'], true )['code'] );
	}

	public function test_reads_an_answer_without_a_field_the_store_does_not_use(): void {
		$answer = self::EXCHANGED;
		unset( $answer['memberKey'], $answer['reachable'] );
		$this->transport->off_spec( self::answer( $answer ) );

		$this->assertSame( self::EXCHANGED['connectionId'], $this->exchange()->connection_id );
	}

	public function test_an_answer_without_a_field_the_store_reads_is_unexpected(): void {
		$answer = self::EXCHANGED;
		unset( $answer['webhook']['secret'] );
		$this->transport->off_spec( self::answer( $answer ) );

		try {
			$this->exchange();
			$this->fail( 'A webhook endpoint without its secret was read.' );
		} catch ( ApiException $error ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $error->errorCode );
			$this->assertStringContainsString( '$.webhook: missing required field "secret"', (string) $error->getPrevious()?->getMessage() );
		}
	}

	public function test_a_member_key_alone_is_unexpected(): void {
		$this->transport->off_spec( self::answer( MemberKeys::ANSWER ) );

		$this->expectException( ApiException::class );
		$this->exchange();
	}

	private function exchange(): ConnectedStoreExchange {
		return ConnectedStoreExchange::exchange( $this->sdk, new ConnectedStoreExchangeCodeRequest( code: 'c_code', code_verifier: 'verifier', redirect_uri: 'https://shop.acme.example/wp-admin/admin-post.php' ) );
	}

	/**
	 * @param array<string, mixed> $body The answer's body.
	 */
	private static function answer( array $body ): Response {
		return new Response( 201, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}
}
