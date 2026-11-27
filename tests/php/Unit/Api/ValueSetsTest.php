<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\PixonautValueSets;
use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Api\ValueSets;
use Pixonaut\WooCommerce\Api\ValueSetsUnavailable;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Operations;

final class ValueSetsTest extends TestCase {

	use FakeOptions;

	private const VALUES = array( 'title' => array( 'content' => 'farmor' ) );

	private ScriptedClient $transport;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->transport = new ScriptedClient();
	}

	public function test_the_plugin_offers_what_it_calls_and_an_awaited_operation_only_once_the_client_knows_it(): void {
		$known = array_map( static fn ( $operation ): string => $operation->id, Operations::all() );

		$this->assertTrue( Pixonaut::offers( 'templates.get' ) );
		$this->assertFalse( Pixonaut::offers( 'templates.no-such-operation' ) );
		$this->assertContains( ValueSets::CREATE, Pixonaut::AWAITED_OPERATIONS );
		$this->assertSame( in_array( ValueSets::CREATE, $known, true ), Pixonaut::offers( ValueSets::CREATE ) );
	}

	public function test_without_the_operation_in_the_client_no_set_is_asked_for(): void {
		if ( Pixonaut::offers( ValueSets::CREATE ) ) {
			$this->markTestSkipped( 'The client knows templates.valueSets.create: move it to Pixonaut::OPERATIONS.' );
		}
		$sets = new PixonautValueSets( $this->transport->pixonaut( ScriptedClient::credentials(), new RecordingLogger() ) );

		try {
			$sets->make( 'mini-mug@2', self::VALUES );
			$this->fail( 'A set was made.' );
		} catch ( ValueSetsUnavailable ) {
			$this->assertSame( array(), $this->transport->sent );
		}
	}

	public function test_a_refusal_names_each_value_refused_and_leaves_out_what_is_no_issue(): void {
		$refused = ValueSetRefused::from_answer(
			array(
				array(
					'layerId' => 'title',
					'prop'    => 'content',
					'rule'    => 'max_length',
				),
				array(
					'layerId' => 'ghost',
					'prop'    => '',
					'rule'    => 'unknown_layer',
				),
				array( 'layerId' => 'motif' ),
				'not an issue',
			)
		);

		$this->assertSame(
			array(
				array(
					'layer_id' => 'title',
					'prop'     => 'content',
					'rule'     => 'max_length',
				),
				array(
					'layer_id' => 'ghost',
					'prop'     => '',
					'rule'     => 'unknown_layer',
				),
			),
			$refused->issues
		);
		$this->assertSame( array(), ValueSetRefused::from_answer( null )->issues );
	}
}
