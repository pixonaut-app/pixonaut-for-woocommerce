<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Response;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedTransport;
use Pixonaut\WooCommerce\Tests\Support\StaticCredentials;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class DesignsTest extends TestCase {

	use FakeOptions;

	private const BASE_URL = 'http://pixonaut.test';

	private const TOKEN = 'eyJhdWQiOiJwcm9vZiJ9.c2lnbmF0dXJl';

	private const CART_REF = 'wc:site-1:cart:abc';

	private const COMMIT_KEY = 'wc:site-1:commit:0123';

	private ScriptedTransport $transport;

	private Designs $designs;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->transport = new ScriptedTransport();
		$this->designs   = new Designs(
			new Client( self::BASE_URL, Operations::plugin(), new StaticCredentials(), $this->transport, new RecordingLogger(), new Deprecations(), static function (): void {} )
		);
	}

	public function test_commit_sends_the_token_and_ref_under_the_given_key(): void {
		$this->transport->json(
			201,
			array(
				'designId'      => DesignFixture::ID,
				'printFloorDpi' => 150,
				'lowResolution' => array(),
			)
		);

		$this->assertSame( DesignFixture::ID, $this->designs->commit( self::TOKEN, self::CART_REF, self::COMMIT_KEY ) );

		$sent = $this->transport->sent[0];
		$this->assertSame( self::COMMIT_KEY, $sent['headers']['Idempotency-Key'] );
		$this->assertSame(
			array(
				'proofToken'  => self::TOKEN,
				'externalRef' => self::CART_REF,
			),
			json_decode( (string) $sent['body'], true )
		);
	}

	public function test_commit_sends_the_fulfilment_keys_it_is_given(): void {
		$this->transport->json(
			201,
			array(
				'designId'      => DesignFixture::ID,
				'printFloorDpi' => 150,
				'lowResolution' => array(),
			)
		);

		$this->designs->commit( self::TOKEN, self::CART_REF, self::COMMIT_KEY, array( 'placement' => DesignFixture::PLACEMENT_ID ) );

		$this->assertSame(
			array( 'placement' => DesignFixture::PLACEMENT_ID ),
			json_decode( (string) $this->transport->sent[0]['body'], true )['fulfilmentPayload'] ?? null
		);
	}

	public function test_commit_adopts_a_proof_committed_before(): void {
		$this->transport->json(
			409,
			array(
				'error'    => ErrorCodes::PROOF_ALREADY_COMMITTED,
				'designId' => DesignFixture::ID,
			)
		);

		$this->assertSame( DesignFixture::ID, $this->designs->commit( self::TOKEN, self::CART_REF, self::COMMIT_KEY ) );
	}

	public function test_commit_passes_on_other_refusals(): void {
		$this->transport->json( 410, array( 'error' => ErrorCodes::PROOF_EXPIRED ) );

		try {
			$this->designs->commit( self::TOKEN, self::CART_REF, self::COMMIT_KEY );
			$this->fail( 'An expired proof must not commit.' );
		} catch ( ApiError $error ) {
			$this->assertSame( ErrorCodes::PROOF_EXPIRED, $error->error_code );
		}
	}

	public function test_standing_finds_or_creates_the_approved_design_for_the_side(): void {
		$this->transport->json( 201, self::standing_answer( true ) );

		$this->assertSame( 'des_standing_1', $this->designs->standing( 'tpl_mugs@4', 'blk_123', 'front', new PrintSize( 200.0, 90.0, '200×90' ) ) );

		$sent = $this->transport->sent[0];
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertSame( self::BASE_URL . '/v1/designs/standing', $sent['url'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $sent['headers'] );
		$this->assertSame(
			array(
				'templateId'  => 'tpl_mugs@4',
				'approve'     => true,
				'blankId'     => 'blk_123',
				'placementId' => 'front',
				'widthMm'     => 200,
				'heightMm'    => 90,
			),
			json_decode( (string) $sent['body'], true )
		);
	}

	public function test_standing_without_a_blank_names_no_placement_or_size(): void {
		$this->transport->json(
			200,
			array(
				'blankId'     => null,
				'placementId' => null,
			) + self::standing_answer( false )
		);

		$this->assertSame( 'des_standing_1', $this->designs->standing( 'tpl_mugs@4', null, null, null ) );

		$this->assertSame(
			array(
				'templateId' => 'tpl_mugs@4',
				'approve'    => true,
			),
			json_decode( (string) $this->transport->sent[0]['body'], true )
		);
	}

	public function test_standing_without_a_blank_names_its_size(): void {
		$this->transport->json(
			200,
			array(
				'blankId'     => null,
				'placementId' => null,
			) + self::standing_answer( false )
		);

		$this->designs->standing( 'tpl_signs@1', null, null, new PrintSize( 500.0, 700.0, 'Sign' ) );

		$this->assertSame(
			array(
				'templateId' => 'tpl_signs@1',
				'approve'    => true,
				'widthMm'    => 500,
				'heightMm'   => 700,
			),
			json_decode( (string) $this->transport->sent[0]['body'], true )
		);
	}

	public function test_standing_passes_on_a_refusal(): void {
		$this->transport->json( 403, array( 'error' => ErrorCodes::FORBIDDEN ) );

		$this->expectException( ApiError::class );

		$this->designs->standing( 'tpl_mugs@4', 'blk_123', 'front', new PrintSize( 200.0, 90.0, '200×90' ) );
	}

	public function test_standing_refuses_an_answer_without_a_design(): void {
		$this->transport->json( 201, array( 'designId' => '' ) + self::standing_answer( true ) );

		$this->expectException( ApiError::class );

		$this->designs->standing( 'tpl_mugs@4', 'blk_123', 'front', new PrintSize( 200.0, 90.0, '200×90' ) );
	}

	/**
	 * A `POST /v1/designs/standing` answer.
	 *
	 * @param bool $created Whether the call made the design.
	 * @return array<string, mixed>
	 */
	private static function standing_answer( bool $created ): array {
		return array(
			'designId'    => 'des_standing_1',
			'created'     => $created,
			'standing'    => true,
			'status'      => 'approved',
			'templateId'  => 'tpl_mugs@4',
			'blankId'     => 'blk_123',
			'placementId' => 'front',
			'widthMm'     => 200,
			'heightMm'    => 90,
			'key'         => null,
			'adjusted'    => false,
			'revision'    => 1,
		);
	}

	public function test_standing_with_a_layout_proof_names_it_and_its_row(): void {
		$this->transport->json( 201, array( 'adjusted' => true ) + self::standing_answer( true ) );

		$this->designs->standing( 'tpl_mugs@4', 'blk_123', 'front', null, array( 'Size' => 'S' ), 'prf_layout' );

		$this->assertSame(
			array(
				'templateId'  => 'tpl_mugs@4',
				'approve'     => true,
				'blankId'     => 'blk_123',
				'placementId' => 'front',
				'variantRow'  => array( 'Size' => 'S' ),
				'proofToken'  => 'prf_layout',
			),
			json_decode( (string) $this->transport->sent[0]['body'], true )
		);
	}

	public function test_a_correction_link_adjusts_a_layout_only_when_asked_to(): void {
		$link = array(
			'id'        => 'shl_1',
			'token'     => 'tok_1',
			'url'       => 'https://app.pixonaut.example/h/correct/tok_1',
			'expiresAt' => 1760007200000,
			'name'      => null,
			'brandId'   => null,
		);
		$this->transport->json( 201, $link );
		$this->transport->json( 201, $link );

		$this->designs->correction_link( 'des_1', 'https://shop.example/back', 'trip', true );
		$this->designs->correction_link( 'des_1', 'https://shop.example/back', 'trip' );

		$this->assertSame(
			array(
				'adjustLayout' => true,
				'returnUrl'    => 'https://shop.example/back',
				'state'        => 'trip',
			),
			json_decode( (string) $this->transport->sent[0]['body'], true )
		);
		$this->assertArrayNotHasKey( 'adjustLayout', json_decode( (string) $this->transport->sent[1]['body'], true ) );
	}

	public function test_get_reads_the_design(): void {
		$this->transport->json( 200, DesignFixture::design() );

		$this->assertEquals( DesignFixture::design(), $this->designs->get( DesignFixture::ID ) );
		$this->assertSame( self::BASE_URL . '/v1/designs/' . DesignFixture::ID, $this->transport->sent[0]['url'] );
	}

	public function test_delete_counts_a_missing_design_as_deleted(): void {
		$this->transport->respond( new Response( 204, array(), '' ) );
		$this->transport->json( 404, array( 'error' => ErrorCodes::NOT_FOUND ) );

		$this->designs->delete( DesignFixture::ID );
		$this->designs->delete( DesignFixture::ID );

		$this->assertSame( 'DELETE', $this->transport->sent[1]['method'] );
		$this->assertSame( 0, $this->transport->remaining() );
	}

	public function test_delete_passes_on_a_design_that_went_on(): void {
		$this->transport->json( 409, array( 'error' => ErrorCodes::DESIGN_APPROVED ) );

		$this->expectException( ApiError::class );

		$this->designs->delete( DesignFixture::ID );
	}

	public function test_set_external_ref_puts_the_new_ref(): void {
		$order_ref = 'wc:site-1:order:7:item:9';
		$this->transport->json(
			200,
			array(
				'designId'    => DesignFixture::ID,
				'externalRef' => $order_ref,
			)
		);

		$this->designs->set_external_ref( DesignFixture::ID, $order_ref );

		$sent = $this->transport->sent[0];
		$this->assertSame( 'PUT', $sent['method'] );
		$this->assertSame( array( 'externalRef' => $order_ref ), json_decode( (string) $sent['body'], true ) );
	}

	public function test_reject_posts_to_the_design_without_a_key(): void {
		$this->transport->json( 200, array( 'ok' => true ) );

		$this->designs->reject( DesignFixture::ID );

		$sent = $this->transport->sent[0];
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertSame( self::BASE_URL . '/v1/designs/' . DesignFixture::ID . '/reject', $sent['url'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $sent['headers'] );
	}

	public function test_reject_passes_on_a_design_no_longer_waiting(): void {
		$this->transport->json( 409, array( 'error' => ErrorCodes::NOT_COMMITTED ) );

		$this->expectException( ApiError::class );

		$this->designs->reject( DesignFixture::ID );
	}

	public function test_proof_asks_for_the_named_proof_at_a_width_and_answers_its_bytes(): void {
		$jpeg = "\xFF\xD8\xFF\xE0jpeg";
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/jpeg' ), $jpeg ) );

		$this->assertSame( $jpeg, $this->designs->proof( DesignFixture::ID, DesignFixture::PROOF_ID, 320 ) );

		$sent = $this->transport->sent[0];
		$this->assertSame( 'GET', $sent['method'] );
		$this->assertSame( self::BASE_URL . '/v1/designs/' . DesignFixture::ID . '/proof?v=' . DesignFixture::PROOF_ID . '&width=320', $sent['url'] );
		$this->assertSame( 'image/jpeg', $sent['headers']['Accept'] );
	}

	public function test_proof_passes_on_a_changed_proof(): void {
		$this->transport->json( 404, array( 'error' => ErrorCodes::PROOF_CHANGED ) );

		try {
			$this->designs->proof( DesignFixture::ID, 'prf_old', 320 );
			$this->fail( 'A proof the design no longer shows must not be served.' );
		} catch ( ApiError $error ) {
			$this->assertSame( ErrorCodes::PROOF_CHANGED, $error->error_code );
		}
	}

	/**
	 * @return array<string, array{0: Response}>
	 */
	public static function not_a_jpeg(): array {
		return array(
			'an HTML page'     => array( new Response( 200, array( 'content-type' => 'text/html' ), '<html>maintenance</html>' ) ),
			'bytes of no JPEG' => array( new Response( 200, array( 'content-type' => 'image/jpeg' ), 'not an image' ) ),
			'an empty body'    => array( new Response( 200, array( 'content-type' => 'image/jpeg' ), '' ) ),
		);
	}

	/**
	 * @dataProvider not_a_jpeg
	 *
	 * @param Response $response A 200 that is not the image.
	 */
	public function test_proof_refuses_an_answer_that_is_not_a_jpeg( Response $response ): void {
		$this->transport->off_spec( $response );

		try {
			$this->designs->proof( DesignFixture::ID, DesignFixture::PROOF_ID, 320 );
			$this->fail( 'Only a JPEG is a proof.' );
		} catch ( ApiError $error ) {
			$this->assertSame( ErrorCodes::UNEXPECTED_RESPONSE, $error->error_code );
		}
	}
}
