<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

final class DesignTest extends TestCase {

	private ScriptedClient $transport;

	private Sdk $sdk;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new ScriptedClient();
		$this->sdk       = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	public function test_reads_the_fields_the_store_uses(): void {
		$this->transport->json( 200, DesignFixture::design( array( 'externalRef' => 'wc:site-1:cart:abc' ) ) );

		$design = Design::get( $this->sdk, DesignFixture::ID );

		$this->assertSame( DesignFixture::ID, $design->id );
		$this->assertSame( 'committed', $design->status );
		$this->assertSame( 1, $design->revision );
		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID, $design->template_base_id );
		$this->assertSame( DesignFixture::LIVE_VERSION, $design->template_version );
		$this->assertSame( DesignFixture::BLANK_ID, $design->blank_id );
		$this->assertSame( DesignFixture::PLACEMENT_ID, $design->placement_id );
		$this->assertNotNull( $design->print_size );
		$this->assertSame( DesignFixture::WIDTH_MM, $design->print_size->width_mm );
		$this->assertSame( DesignFixture::HEIGHT_MM, $design->print_size->height_mm );
		$this->assertSame( DesignFixture::PROOF_ID, $design->proof_id );
		$this->assertSame( 'wc:site-1:cart:abc', $design->external_ref );
		$this->assertNull( $design->personal_data_erased_at );
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID, $this->transport->sent[0]['url'] );
	}

	public function test_reads_an_answer_without_a_field_the_store_does_not_use(): void {
		$answer = DesignFixture::design();
		unset( $answer['garmentTone'], $answer['canDecide'] );
		$this->transport->off_spec( self::answer( $answer ) );

		$this->assertSame( 'committed', Design::get( $this->sdk, DesignFixture::ID )->status );
	}

	public function test_an_answer_without_a_field_the_store_reads_is_unexpected(): void {
		$answer = DesignFixture::design();
		unset( $answer['printSize']['widthMm'] );
		$this->transport->off_spec( self::answer( $answer ) );

		try {
			Design::get( $this->sdk, DesignFixture::ID );
			$this->fail( 'A design without its print width was read.' );
		} catch ( ApiException $error ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $error->errorCode );
			$this->assertStringContainsString( '$.printSize: missing required field "widthMm"', (string) $error->getPrevious()?->getMessage() );
		}
	}

	public function test_an_answer_that_is_not_an_object_is_unexpected(): void {
		$this->transport->off_spec( new Response( 200, array( 'content-type' => 'application/json' ), 'null' ) );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessageMatches( '/unexpected_response|designs\.get/' );
		Design::get( $this->sdk, DesignFixture::ID );
	}

	/**
	 * @param array<string, mixed> $body The answer's body.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}
}
