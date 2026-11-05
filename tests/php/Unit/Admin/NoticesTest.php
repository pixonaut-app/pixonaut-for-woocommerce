<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Admin;

use InvalidArgumentException;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class NoticesTest extends TestCase {

	use FakeOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
	}

	public function test_a_key_holds_one_notice(): void {
		$notices = $this->notices();

		$notices->add( 'connection_paused', 'First' );
		$notices->add( 'connection_paused', 'Second', 'error', false );

		$this->assertSame(
			array(
				'connection_paused' => array(
					'message'     => 'Second',
					'type'        => 'error',
					'dismissible' => false,
				),
			),
			$notices->all()
		);
	}

	public function test_notices_persist_until_removed(): void {
		$this->notices()->add( 'template_invalid', 'Fix it' );

		$this->assertTrue( $this->notices()->has( 'template_invalid' ) );

		$this->notices()->remove( 'template_invalid' );

		$this->assertFalse( $this->notices()->has( 'template_invalid' ) );
	}

	public function test_a_key_sanitize_key_would_change_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->notices()->add( 'product:123', 'Message' );
	}

	public function test_unknown_type_is_shown_as_a_warning(): void {
		$this->notices()->add( 'k', 'Message', 'shouting' );

		$this->assertSame( 'warning', $this->notices()->all()['k']['type'] );
	}

	public function test_malformed_stored_entries_are_skipped(): void {
		$this->options[ Notices::OPTION ] = array(
			'bad'  => 'string',
			'good' => array(
				'message' => 'M',
				'type'    => 'info',
			),
		);

		$this->assertSame( array( 'good' ), array_keys( $this->notices()->all() ) );
	}

	private function notices(): Notices {
		return new Notices( new Templates( '/nowhere/' ) );
	}
}
