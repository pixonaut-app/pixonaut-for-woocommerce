<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Pixonaut\WooCommerce\Api\Answers\ConnectedStore;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

final class ConnectedStoreTest extends TestCase {

	private const BRAND = '0b1c2d3e-4f50-4a6b-8c7d-0000000000b1';

	private const ACCENT = '#ff6600';

	private ScriptedClient $transport;

	private Sdk $sdk;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new ScriptedClient();
		$this->sdk       = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	public function test_reads_the_fields_the_store_uses(): void {
		$this->transport->json( 200, self::branded() );

		$connected = ConnectedStore::get( $this->sdk );

		$this->assertSame( array( 'http://example.org' ), $connected->allowed_origins );
		$this->assertSame( self::BRAND, $connected->brand_id );
		$this->assertSame( self::ACCENT, $connected->branding->accent_color );
		$this->assertSame( self::BRAND, $connected->branding->brand_id );
		$this->assertSame( 'http://127.0.0.1:9/l/logo', $connected->branding->logo_url );
		$this->assertCount( 1, $connected->brands );
		$this->assertSame( self::BRAND, $connected->brands[0]->id );
		$this->assertSame( 'Acme', $connected->brands[0]->name );
		$this->assertTrue( $connected->brands[0]->is_default );
		$this->assertSame( 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004', $connected->connection_id );
		$this->assertSame( 'en', $connected->default_language );
		$this->assertSame( 'Warehouse', $connected->deliver_orders_to?->name );
		$this->assertSame( 'sandbox', $connected->environment );
		$this->assertSame( 'WooCommerce · Acme Shop', $connected->name );
		$this->assertSame( 150.0, $connected->printing->min_print_dpi );
		$this->assertSame( 'flat', $connected->printing->print_file_form );
		$this->assertSame( 'px', $connected->printing->print_file_text_tolerance->unit );
		$this->assertSame( 1.0, $connected->printing->print_file_text_tolerance->value );
		$this->assertNull( $connected->print_file_form );
		$this->assertNull( $connected->print_file_text_tolerance );
		$this->assertTrue( $connected->proof_watermark );
		$this->assertFalse( $connected->reachable );
		$this->assertSame( 'http://example.org', $connected->site_url );
		$this->assertSame( 'Acme', $connected->tenant_name );
		$this->assertSame( ConnectionAnswers::TENANT_SLUG, $connected->tenant_slug );
		$this->assertStringEndsWith( '/v1/connect', $this->transport->sent[0]['url'] );
		$this->assertSame( 'GET', $this->transport->sent[0]['method'] );
	}

	public function test_update_sends_the_change_and_reads_the_answer(): void {
		$this->transport->json( 200, self::branded() );

		$connected = ConnectedStore::update( $this->sdk, new ConnectedStoreUpdateRequest( brandId: self::BRAND ) );

		$this->assertSame( self::BRAND, $connected->brand_id );
		$this->assertSame( 'PATCH', $this->transport->sent[0]['method'] );
		$this->assertSame( array( 'brandId' => self::BRAND ), json_decode( (string) $this->transport->sent[0]['body'], true ) );
	}

	public function test_reads_an_answer_without_a_field_the_store_does_not_use(): void {
		$answer = self::branded();
		unset( $answer['assetTtlHours'], $answer['webhook'], $answer['printing']['defaultBleedMm'], $answer['brands'][0]['logoUrl'] );
		$this->transport->off_spec( self::answer( $answer ) );

		$this->assertSame( 150.0, ConnectedStore::get( $this->sdk )->printing->min_print_dpi );
	}

	public function test_an_answer_without_a_field_the_store_reads_is_unexpected(): void {
		$answer = self::branded();
		unset( $answer['brands'][0]['isDefault'] );
		$this->transport->off_spec( self::answer( $answer ) );

		try {
			ConnectedStore::get( $this->sdk );
			$this->fail( 'A brand without whether it is the default was read.' );
		} catch ( ApiException $error ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $error->errorCode );
			$this->assertStringContainsString( '$.brands[0]: missing required field "isDefault"', (string) $error->getPrevious()?->getMessage() );
		}
	}

	/**
	 * A connection drawn in its own brand, with a delivery address and a watermark, whose site did not answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function branded(): array {
		return array(
			'branding'        => array(
				'logoUrl'     => 'http://127.0.0.1:9/l/logo',
				'accentColor' => self::ACCENT,
				'brandId'     => self::BRAND,
			),
			'brandId'         => self::BRAND,
			'brands'          => array(
				array(
					'id'          => self::BRAND,
					'name'        => 'Acme',
					'isDefault'   => true,
					'logoUrl'     => 'http://127.0.0.1:9/l/logo',
					'accentColor' => self::ACCENT,
				),
			),
			'deliverOrdersTo' => array(
				'id'   => '0b1c2d3e-4f50-4a6b-8c7d-0000000000d1',
				'name' => 'Warehouse',
			),
			'proofWatermark'  => true,
		) + ConnectionAnswers::connection( false );
	}

	/**
	 * @param array<string, mixed> $body The answer's body.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}
}
