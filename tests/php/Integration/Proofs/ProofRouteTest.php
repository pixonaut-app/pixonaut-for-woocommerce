<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Proofs;

use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofPicture;
use Pixonaut\WooCommerce\Proofs\ProofRoute;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WP_REST_Request;
use WP_REST_Response;

final class ProofRouteTest extends PersonalisedCartTestCase {

	private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg";

	private const WIDTH = 320;

	private const PNG = "\x89PNG\r\n\x1a\nfake-png";

	public function tear_down(): void {
		$this->plugin->proof_cache()->purge( DesignFixture::ID );
		parent::tear_down();
	}

	/**
	 * The route's request for a URL the plugin minted.
	 *
	 * @param array<string, string> $overrides Query values to replace.
	 * @param ProofPicture|null     $picture   The picture; null for the flat proof.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function minted( array $overrides = array(), ?ProofPicture $picture = null ): WP_REST_Request {
		$url = $this->plugin->proof_urls()->url( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, $picture );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . ProofUrls::ROUTE . '/' . DesignFixture::ID );
		$request->set_query_params( $overrides + $query );
		// As the route's match sets it, for a test that calls the handler itself.
		$request->set_url_params( array( 'design' => DesignFixture::ID ) );
		return $request;
	}

	private function script_image(): void {
		$this->recorder->raw( 200, self::JPEG, ProofRoute::CONTENT_TYPE );
	}

	public function test_serves_a_minted_url_from_pixonaut_with_the_stores_key(): void {
		$this->script_image();

		$response = rest_do_request( $this->minted() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::JPEG, $response->get_data() );
		$this->assertSame( ProofRoute::CONTENT_TYPE, $response->get_headers()['Content-Type'] );
		$this->assertSame( ProofRoute::CACHE_CONTROL, $response->get_headers()['Cache-Control'] );
		[ $fetch ] = $this->recorder->requests;
		$this->assertSame( 'GET', $fetch['method'] );
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID . '/proof?v=' . DesignFixture::PROOF_ID . '&width=' . self::WIDTH, $fetch['url'] );
		$this->assertSame( 'Bearer ' . self::connection()->integration_key, $fetch['headers']['Authorization'] );
	}

	private function script_picture(): void {
		$this->recorder->raw( 200, self::PNG, ProofRoute::ON_BLANK_CONTENT_TYPE );
	}

	public function test_serves_the_design_on_its_blank_in_the_view_it_was_previewed_in(): void {
		$this->script_picture();

		$response = rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::PNG, $response->get_data() );
		$this->assertSame( ProofRoute::ON_BLANK_CONTENT_TYPE, $response->get_headers()['Content-Type'] );
		$this->assertSame( ProofRoute::CACHE_CONTROL, $response->get_headers()['Cache-Control'] );
		[ $fetch ] = $this->recorder->requests;
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID . '/mockup.png?size=' . self::WIDTH . '&v=' . DesignFixture::PROOF_ID, $fetch['url'] );
		$this->assertSame( 'Bearer ' . self::connection()->integration_key, $fetch['headers']['Authorization'] );
	}

	public function test_serves_the_design_on_its_blank_in_the_view_the_url_names(): void {
		$this->script_picture();

		$this->assertSame( 200, rest_do_request( $this->minted( array(), ProofPicture::on_blank( BlankFixture::VIEW_ID ) ) )->get_status() );

		[ $fetch ] = $this->recorder->requests;
		$this->assertStringContainsString( 'viewId=' . BlankFixture::VIEW_ID, $fetch['url'] );
	}

	public function test_the_picture_on_the_blank_and_the_flat_proof_are_cached_apart(): void {
		$this->script_picture();
		$this->script_image();
		rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );
		rest_do_request( $this->minted() );

		$this->assertSame( self::PNG, rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) )->get_data() );
		$this->assertSame( self::JPEG, rest_do_request( $this->minted() )->get_data() );
		$this->assertCount( 2, $this->recorder->requests );
		$this->assertNull( $this->plugin->proof_cache()->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, ProofPicture::on_blank( 'view_other' ) ), 'Another view is not the one kept.' );
	}

	public function test_a_picture_pixonaut_cannot_draw_is_answered_with_the_flat_proof_for_a_while(): void {
		$this->recorder->json( 422, array( 'error' => 'mockup_rect_unusable' ) );
		$this->script_image();

		$response = rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::JPEG, $response->get_data() );
		$this->assertSame( ProofRoute::CONTENT_TYPE, $response->get_headers()['Content-Type'] );
		$this->assertSame( ProofRoute::STAND_IN_CACHE_CONTROL, $response->get_headers()['Cache-Control'] );
		$this->assertNull( $this->plugin->proof_cache()->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, ProofPicture::on_blank() ) );
	}

	public function test_a_refused_picture_is_answered_with_the_flat_proof_without_asking_again(): void {
		$this->recorder->json( 422, array( 'error' => 'mockup_rect_unusable' ) );
		$this->script_image();
		rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );

		$again = rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );

		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( self::JPEG, $again->get_data() );
		$this->assertSame( ProofRoute::STAND_IN_CACHE_CONTROL, $again->get_headers()['Cache-Control'] );
		$this->assertCount( 2, $this->recorder->requests, 'The mockup once, then the flat proof once.' );
	}

	public function test_a_refused_picture_is_asked_for_again_once_the_refusal_is_old(): void {
		$this->recorder->json( 422, array( 'error' => 'mockup_rect_unusable' ) );
		$this->script_image();
		rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );
		$this->script_picture();
		$later = $this->route_with( new ProofCache( null, static fn (): int => time() + ProofCache::REFUSAL_SECONDS ) );

		$response = $later->handle( $this->minted( array(), ProofPicture::on_blank() ) );

		$this->assertSame( self::PNG, $response->get_data() );
		$this->assertStringContainsString( '/mockup.png?', $this->recorder->requests[2]['url'] );
	}

	public function test_deleting_the_design_drops_a_refused_picture(): void {
		$this->recorder->json( 422, array( 'error' => 'mockup_rect_unusable' ) );
		$this->script_image();
		rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );
		$cache = $this->plugin->proof_cache();
		$this->assertSame( ProofCache::STAND_IN, $cache->refusal( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, ProofPicture::on_blank() ) );
		$this->recorder->raw( 204, '', '' );

		( new Designs( $this->plugin->sdk() ) )->delete( DesignFixture::ID );

		$this->assertNull( $cache->refusal( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, ProofPicture::on_blank() ) );
	}

	public function test_pictures_in_different_views_are_cached_apart(): void {
		$this->script_picture();
		$this->recorder->raw( 200, self::PNG . '-view', ProofRoute::ON_BLANK_CONTENT_TYPE );
		$previewed = ProofPicture::on_blank();
		$named     = ProofPicture::on_blank( BlankFixture::VIEW_ID );
		rest_do_request( $this->minted( array(), $previewed ) );
		rest_do_request( $this->minted( array(), $named ) );

		$this->assertSame( self::PNG, rest_do_request( $this->minted( array(), $previewed ) )->get_data() );
		$this->assertSame( self::PNG . '-view', rest_do_request( $this->minted( array(), $named ) )->get_data() );
		$this->assertCount( 2, $this->recorder->requests );

		$this->recorder->raw( 204, '', '' );
		( new Designs( $this->plugin->sdk() ) )->delete( DesignFixture::ID );

		$cache = $this->plugin->proof_cache();
		$this->assertNull( $cache->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, $previewed ) );
		$this->assertNull( $cache->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, $named ) );
	}

	public function test_a_picture_whose_proof_is_gone_is_not_found_without_the_flat_proof(): void {
		$this->recorder->json( 404, array( 'error' => ErrorCode::PROOF_NOT_FOUND ) );

		$this->assertSame( 404, rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) )->get_status() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_deleting_the_design_drops_its_pictures_on_the_blank_too(): void {
		$this->script_picture();
		rest_do_request( $this->minted( array(), ProofPicture::on_blank() ) );
		$this->recorder->raw( 204, '', '' );

		( new Designs( $this->plugin->sdk() ) )->delete( DesignFixture::ID );

		$this->assertNull( $this->plugin->proof_cache()->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, ProofPicture::on_blank() ) );
	}

	public function test_a_cached_image_makes_no_api_call(): void {
		$this->script_image();
		rest_do_request( $this->minted() );

		$again = rest_do_request( $this->minted() );

		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( self::JPEG, $again->get_data() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function tampered(): array {
		return array(
			'signature' => array( array( 'sig' => str_repeat( 'a', 64 ) ) ),
			'proof'     => array( array( 'v' => 'prf_other' ) ),
			'width'     => array( array( 'w' => '640' ) ),
			'picture'   => array( array( 'p' => 'blank' ) ),
		);
	}

	/**
	 * @dataProvider tampered
	 *
	 * @param array<string, string> $change What the URL's holder changed.
	 */
	public function test_a_url_the_plugin_did_not_mint_is_forbidden( array $change ): void {
		$response = rest_do_request( $this->minted( $change ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_proof_the_design_no_longer_shows_is_not_found_and_not_cached(): void {
		$this->recorder->json( 404, array( 'error' => ErrorCode::PROOF_CHANGED ) );
		$this->script_image();

		$this->assertSame( 404, rest_do_request( $this->minted() )->get_status() );
		$this->assertSame( 200, rest_do_request( $this->minted() )->get_status() );
		$this->assertCount( 2, $this->recorder->requests );
	}

	public function test_an_erased_designs_proof_is_not_found_without_asking_pixonaut(): void {
		do_action( 'pixonaut_wc_webhook_design_personal_data_erased', array( 'designId' => DesignFixture::ID ) );

		$response = rest_do_request( $this->minted() );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pixonaut_proof_not_found', $response->get_data()['code'] ?? null );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_pixonaut_out_of_reach_is_a_bad_gateway(): void {
		$this->recorder->json( 429, array( 'error' => ErrorCode::RATE_LIMITED ), array( 'retry-after' => '30' ) );

		$response = rest_do_request( $this->minted() );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_deleting_the_design_drops_its_cached_images(): void {
		$this->script_image();
		rest_do_request( $this->minted() );
		$this->assertNotNull( $this->plugin->proof_cache()->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
		$this->recorder->raw( 204, '', '' );

		( new Designs( $this->plugin->sdk() ) )->delete( DesignFixture::ID );

		$this->assertNull( $this->plugin->proof_cache()->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
	}

	public function test_another_designs_delete_keeps_the_cache(): void {
		$this->script_image();
		rest_do_request( $this->minted() );
		$this->recorder->raw( 204, '', '' );

		( new Designs( $this->plugin->sdk() ) )->delete( 'another-design' );

		$this->assertNotNull( $this->plugin->proof_cache()->get( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
	}

	public function test_the_image_is_sent_as_bytes_rather_than_json(): void {
		$request  = $this->minted();
		$response = new WP_REST_Response( self::JPEG, 200 );

		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() );
		$output = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( self::JPEG, $output );
	}

	public function test_a_refusal_is_left_to_the_rest_server(): void {
		$response = new WP_REST_Response( array( 'code' => 'pixonaut_proof_forbidden' ), 403 );

		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $response, $this->minted(), rest_get_server() );
		ob_end_clean();

		$this->assertFalse( $served );
	}

	/**
	 * A route whose cache waits through $sleep, as a request does while another fetches.
	 *
	 * @param \Closure $sleep `fn( int $microseconds ): void`.
	 */
	private function route_waiting( \Closure $sleep ): ProofRoute {
		return $this->route_with( new ProofCache( $sleep ) );
	}

	private function route_with( ProofCache $cache ): ProofRoute {
		return new ProofRoute( $this->plugin->proof_urls(), $cache, new Designs( $this->plugin->sdk() ), new WcLogger() );
	}

	/**
	 * A route waiting on another request's fetch of the picture on the blank,
	 * which Pixonaut refuses meanwhile as $refusal says.
	 *
	 * @param string $refusal `ProofCache::STAND_IN` or `ProofCache::GONE`.
	 */
	private function route_waiting_on_a_refusal( string $refusal ): ProofRoute {
		$cache   = $this->plugin->proof_cache();
		$picture = ProofPicture::on_blank();
		$this->assertTrue( $cache->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, $picture ) );
		return $this->route_waiting(
			static function () use ( $cache, $picture, $refusal ): void {
				$cache->refuse( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, $picture, $refusal );
				$cache->unlock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, $picture );
			}
		);
	}

	public function test_a_request_waiting_on_a_refused_picture_gets_the_flat_proof(): void {
		$this->script_image();
		$route = $this->route_waiting_on_a_refusal( ProofCache::STAND_IN );

		$response = $route->handle( $this->minted( array(), ProofPicture::on_blank() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::JPEG, $response->get_data() );
		$this->assertSame( ProofRoute::STAND_IN_CACHE_CONTROL, $response->get_headers()['Cache-Control'] );
		[ $fetch ] = $this->recorder->requests;
		$this->assertStringContainsString( '/proof?', $fetch['url'], 'Only the flat proof is fetched.' );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_request_waiting_on_a_picture_whose_proof_is_gone_is_not_found(): void {
		$route = $this->route_waiting_on_a_refusal( ProofCache::GONE );

		$this->assertSame( 404, $route->handle( $this->minted( array(), ProofPicture::on_blank() ) )->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_request_while_another_fetches_waits_for_its_image(): void {
		$cache = $this->plugin->proof_cache();
		$this->assertTrue( $cache->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
		// The other request's fetch lands while this one waits.
		$route = $this->route_waiting(
			static function () use ( $cache ): void {
				$cache->put( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH, self::JPEG );
				$cache->unlock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH );
			}
		);

		$response = $route->handle( $this->minted() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::JPEG, $response->get_data() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_fetch_that_never_lands_is_not_repeated(): void {
		$cache = $this->plugin->proof_cache();
		$this->assertTrue( $cache->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
		$sleeps = 0;
		$route  = $this->route_waiting(
			static function () use ( &$sleeps ): void {
				++$sleeps;
			}
		);

		$response = $route->handle( $this->minted() );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertGreaterThan( 1, $sleeps );
		$cache->unlock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH );
	}

	public function test_the_lock_is_released_after_a_failed_fetch(): void {
		$this->recorder->json( 429, array( 'error' => ErrorCode::RATE_LIMITED ), array( 'retry-after' => '30' ) );

		rest_do_request( $this->minted() );

		$this->assertTrue( $this->plugin->proof_cache()->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
		$this->plugin->proof_cache()->unlock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH );
	}

	public function test_a_lock_left_by_a_request_that_died_is_taken_over(): void {
		$stale = new ProofCache( null, static fn (): int => time() - ProofCache::LOCK_SECONDS );
		$this->assertTrue( $stale->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );

		$this->assertTrue( $this->plugin->proof_cache()->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
		$this->assertFalse( $this->plugin->proof_cache()->lock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH ) );
		$this->plugin->proof_cache()->unlock( DesignFixture::ID, DesignFixture::PROOF_ID, self::WIDTH );
	}
}
