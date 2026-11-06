<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;

final class HookListenerTest extends TestCase {

	use FakeOptions;

	private const NOW = 1_800_000_000;

	private const DEPRECATED_AT = '@1798761600';

	private const SUNSET = 'Wed, 01 Jan 2027 00:00:00 GMT';

	private ScriptedClient $http;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		$this->http = new ScriptedClient();
	}

	public function test_a_request_body_passes_the_filter_once_with_the_operation_id(): void {
		$this->http->json(
			201,
			array(
				'designId'      => 'des_1',
				'lowResolution' => array(),
				'printFloorDpi' => 150,
			)
		);
		Filters\expectApplied( 'pixonaut_wc_api_request_body' )
			->once()
			->with( array( 'proofToken' => 'pt_1' ), 'designs.commit' )
			->andReturn(
				array(
					'proofToken'  => 'pt_1',
					'externalRef' => 'shop-a:cart:1',
				)
			);

		$this->http->sdk()->designs()->commit( new DesignsCommitRequestOption1( proofToken: 'pt_1' ) );

		$this->assertSame( '{"proofToken":"pt_1","externalRef":"shop-a:cart:1"}', $this->http->sent[0]['body'] );
	}

	public function test_every_send_fires_before_and_after_with_the_sdks_objects(): void {
		$this->http->json( 200, ConnectionAnswers::connection( true ) );
		Actions\expectDone( 'pixonaut_wc_api_before_request' )->once()->with( \Mockery::type( PreparedRequest::class ) );
		Actions\expectDone( 'pixonaut_wc_api_after_request' )->once()->with( \Mockery::type( PreparedRequest::class ), \Mockery::type( Result::class ), null );

		$this->http->sdk()->connect()->get();
	}

	public function test_a_refusal_comes_to_after_as_the_error(): void {
		$this->http->json( 404, array( 'error' => 'not_found' ) );
		Actions\expectDone( 'pixonaut_wc_api_after_request' )->once()->with( \Mockery::type( PreparedRequest::class ), null, \Mockery::type( ApiException::class ) );

		$this->expectException( ApiException::class );

		$this->http->sdk()->designs()->get( 'des_gone' );
	}

	public function test_a_refused_key_fires_the_unauthorized_action(): void {
		$this->http->json( 401, array( 'error' => 'unauthorized' ) );
		Actions\expectDone( 'pixonaut_wc_api_unauthorized' )->once()->with( 'connectedStore.get', \Mockery::type( ApiException::class ) );

		try {
			$this->http->sdk()->connect()->get();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 401, $e->status );
		}
	}

	public function test_a_deprecation_is_kept_for_the_admin_notice(): void {
		$this->http->json(
			200,
			ConnectionAnswers::connection( true ),
			array(
				'Deprecation' => self::DEPRECATED_AT,
				'Sunset'      => self::SUNSET,
			)
		);
		$listener = new HookListener( new Deprecations(), static fn (): int => self::NOW );

		$this->http->pixonaut( null, null, $listener )->sdk()->connect()->get();

		$this->assertSame(
			array(
				'connectedStore.get' => array(
					'deprecation' => self::DEPRECATED_AT,
					'sunset'      => self::SUNSET,
					'seen_at'     => self::NOW,
				),
			),
			( new Deprecations() )->all()
		);
	}
}
