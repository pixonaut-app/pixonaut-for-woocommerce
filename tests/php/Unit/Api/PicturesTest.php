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
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Api\Response;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedTransport;
use Pixonaut\WooCommerce\Tests\Support\StaticCredentials;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class PicturesTest extends TestCase {

	use FakeOptions;

	private const BASE_URL = 'http://pixonaut.test';

	private const SITE = 'https://Shop.example:8443/';

	private const PNG = "\x89PNG\r\n\x1a\nbytes";

	private ScriptedTransport $transport;

	private Pictures $pictures;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\when( 'home_url' )->justReturn( self::SITE );
		Functions\when( 'untrailingslashit' )->alias( static fn ( string $value ): string => rtrim( $value, '/\\' ) );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$this->transport = new ScriptedTransport();
		$this->pictures  = new Pictures(
			new Client( self::BASE_URL, Operations::plugin(), new StaticCredentials(), $this->transport, new RecordingLogger(), new Deprecations(), static function (): void {} )
		);
	}

	public function test_mockup_draws_the_template_on_the_variants_photo_with_the_publishable_key_from_the_sites_origin(): void {
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );

		$this->assertSame( self::PNG, $this->pictures->mockup( 'mini-tee@2', 'blk_1', 'front', 'flat', 'tee-red-m', 1200 ) );

		$sent = $this->transport->sent[0];
		$this->assertSame( 'GET', $sent['method'] );
		$this->assertSame( self::BASE_URL . '/t/mini-tee%402/mockup.png?blankId=blk_1&placementId=front&viewId=flat&variant=tee-red-m&size=1200', $sent['url'] );
		$this->assertSame( 'Bearer ' . StaticCredentials::PUBLISHABLE_KEY, $sent['headers']['Authorization'] );
		$this->assertSame( 'https://shop.example:8443', $sent['headers']['Origin'] );
		$this->assertSame( 'image/png', $sent['headers']['Accept'] );
	}

	public function test_all_draws_the_pictures_together_a_bounded_number_at_once_in_order(): void {
		$count = Client::MAX_CONCURRENT + 1;
		for ( $index = 0; $index < $count; ++$index ) {
			$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG . $index ) );
		}
		$calls = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$calls[] = Pictures::preview_of( 'mini-tee@2', 1200, 'showcase-' . $index );
		}

		$pngs = $this->pictures->all( $calls );

		$this->assertSame( array_map( static fn ( int $index ): string => self::PNG . $index, range( 0, $count - 1 ) ), $pngs );
		$this->assertSame( array( Client::MAX_CONCURRENT, 1 ), array_map( 'count', $this->transport->batches ) );
		$this->assertSame( self::BASE_URL . '/t/mini-tee%402/preview.png?size=1200&showcase=showcase-0', $this->transport->batches[0][0] );
		$this->assertSame( 'Bearer ' . StaticCredentials::PUBLISHABLE_KEY, $this->transport->sent[0]['headers']['Authorization'] );
	}

	public function test_all_says_why_each_picture_it_could_not_draw_was_not_drawn(): void {
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );
		$this->transport->respond( new Response( 404, array( 'content-type' => 'application/json' ), '{"error":"showcase_not_found"}' ) );
		$this->transport->fail( 'Connection reset.' );
		$this->transport->off_spec( new Response( 200, array( 'content-type' => 'text/html' ), '<html>' ) );

		$pngs = $this->pictures->all(
			array(
				Pictures::mockup_of( 'mini-tee@2', 'blk_1', 'front', 'flat', null, 1200 ),
				Pictures::preview_of( 'mini-tee@2', 1200, 'showcase-gone' ),
				Pictures::preview_of( 'mini-tee@2', 1200 ),
				Pictures::preview_of( 'mini-tee@2', 1200, 'showcase-anna' ),
			)
		);

		$this->assertSame( self::PNG, $pngs[0] );
		$this->assertInstanceOf( ApiError::class, $pngs[1] );
		$this->assertSame( ErrorCodes::SHOWCASE_NOT_FOUND, $pngs[1]->error_code );
		$this->assertInstanceOf( ApiError::class, $pngs[2] );
		$this->assertSame( ErrorCodes::TRANSPORT_FAILED, $pngs[2]->error_code );
		$this->assertInstanceOf( ApiError::class, $pngs[3] );
		$this->assertSame( ErrorCodes::UNEXPECTED_RESPONSE, $pngs[3]->error_code );
	}

	public function test_mockup_without_a_variant_leaves_it_out(): void {
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );

		$this->pictures->mockup( 'mini-tee@2', 'blk_1', 'front', 'flat', null, 1200 );

		$this->assertStringNotContainsString( 'variant', $this->transport->sent[0]['url'] );
	}

	public function test_a_showcase_set_is_drawn_on_the_photo_and_alone(): void {
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );

		$this->pictures->mockup( 'mini-tee@2', 'blk_1', 'front', 'flat', null, 1200, 'showcase-anna' );
		$this->pictures->preview( 'mini-tee@2', 1200, 'showcase-anna' );

		$this->assertSame( self::BASE_URL . '/t/mini-tee%402/mockup.png?blankId=blk_1&placementId=front&viewId=flat&size=1200&showcase=showcase-anna', $this->transport->sent[0]['url'] );
		$this->assertSame( self::BASE_URL . '/t/mini-tee%402/preview.png?size=1200&showcase=showcase-anna', $this->transport->sent[1]['url'] );
	}

	public function test_a_showcase_set_the_template_does_not_have_is_refused_by_its_code(): void {
		$this->transport->respond( new Response( 404, array( 'content-type' => 'application/json' ), '{"error":"showcase_not_found"}' ) );

		try {
			$this->pictures->preview( 'mini-tee@2', 1200, 'gone' );
			$this->fail( 'Expected an ApiError.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 404, $e->status );
			$this->assertSame( ErrorCodes::SHOWCASE_NOT_FOUND, $e->error_code );
		}
	}

	public function test_preview_draws_the_template_alone(): void {
		$this->transport->respond( new Response( 200, array( 'content-type' => 'image/png' ), self::PNG ) );

		$this->assertSame( self::PNG, $this->pictures->preview( 'mini-tee@2', 1200 ) );

		$this->assertSame( self::BASE_URL . '/t/mini-tee%402/preview.png?size=1200', $this->transport->sent[0]['url'] );
	}

	/**
	 * @return array<string, array{0: Response}>
	 */
	public static function not_a_png(): array {
		return array(
			'an HTML page'    => array( new Response( 200, array( 'content-type' => 'text/html' ), '<html>maintenance</html>' ) ),
			'bytes of no PNG' => array( new Response( 200, array( 'content-type' => 'image/png' ), 'not an image' ) ),
			'an empty body'   => array( new Response( 200, array( 'content-type' => 'image/png' ), '' ) ),
		);
	}

	/**
	 * @dataProvider not_a_png
	 *
	 * @param Response $response A 200 that is not the picture.
	 */
	public function test_refuses_an_answer_that_is_not_a_png( Response $response ): void {
		$this->transport->off_spec( $response );

		try {
			$this->pictures->preview( 'mini-tee@2', 1200 );
			$this->fail( 'Only a PNG is a picture.' );
		} catch ( ApiError $error ) {
			$this->assertSame( ErrorCodes::UNEXPECTED_RESPONSE, $error->error_code );
		}
	}
}
