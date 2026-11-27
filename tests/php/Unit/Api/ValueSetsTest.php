<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\PixonautValueSets;
use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ValueSetsTest extends TestCase {

	use FakeOptions;

	private const VALUES = array( 'title' => array( 'content' => 'farmor' ) );

	private const SET = 'vs_3f2e9a1b7c4d5e6f8a9b0c1d';

	private ScriptedClient $transport;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->transport = new ScriptedClient();
	}

	public function test_a_set_is_made_with_the_integration_key_for_the_template_version(): void {
		$this->transport->json(
			201,
			array(
				'valueSetId' => self::SET,
				'templateId' => 'mini-mug@2',
				'values'     => self::VALUES,
			)
		);

		$this->assertSame( self::SET, $this->sets()->make( 'mini-mug@2', self::VALUES ) );

		$sent = $this->transport->sent[0];
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertSame( ScriptedClient::BASE_URL . '/v1/templates/mini-mug%402/value-sets', $sent['url'] );
		$this->assertSame( 'Bearer ' . ScriptedClient::INTEGRATION_KEY, $sent['headers']['Authorization'] );
		$this->assertSame( array( 'values' => self::VALUES ), json_decode( (string) $sent['body'], true ) );
	}

	public function test_values_pixonaut_refuses_are_named_by_layer_and_prop(): void {
		$this->transport->json(
			422,
			array(
				'error'  => 'value_set_invalid',
				'issues' => array(
					array(
						'layerId' => 'title',
						'prop'    => 'colour',
						'rule'    => 'unknown_prop',
					),
				),
			)
		);

		try {
			$this->sets()->make( 'mini-mug@2', self::VALUES );
			$this->fail( 'The values were taken.' );
		} catch ( ValueSetRefused $refused ) {
			$this->assertSame(
				array(
					array(
						'layer_id' => 'title',
						'prop'     => 'colour',
						'rule'     => 'unknown_prop',
					),
				),
				$refused->issues
			);
		}
	}

	private function sets(): PixonautValueSets {
		return new PixonautValueSets( $this->transport->pixonaut( ScriptedClient::credentials(), new RecordingLogger() ) );
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
