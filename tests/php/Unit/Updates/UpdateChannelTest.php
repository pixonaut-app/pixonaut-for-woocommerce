<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Updates;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Updates\UpdateChannel;

final class UpdateChannelTest extends TestCase {

	public function test_stable_accepts_releases_only(): void {
		$this->assertTrue( UpdateChannel::accepts( UpdateChannel::STABLE, '1.2.3' ) );
		$this->assertFalse( UpdateChannel::accepts( UpdateChannel::STABLE, '1.3.0-beta.1' ) );
		$this->assertFalse( UpdateChannel::accepts( UpdateChannel::STABLE, '1.3.0-rc.1' ) );
	}

	public function test_beta_accepts_releases_and_betas(): void {
		$this->assertTrue( UpdateChannel::accepts( UpdateChannel::BETA, '1.2.3' ) );
		$this->assertTrue( UpdateChannel::accepts( UpdateChannel::BETA, '1.3.0-beta.12' ) );
		$this->assertFalse( UpdateChannel::accepts( UpdateChannel::BETA, '1.3.0-alpha.1' ) );
		$this->assertFalse( UpdateChannel::accepts( UpdateChannel::BETA, '1.3.0-beta' ) );
	}

	public function test_an_unknown_channel_is_stable(): void {
		$this->assertFalse( UpdateChannel::accepts( 'nightly', '1.3.0-beta.1' ) );
		$this->assertTrue( UpdateChannel::accepts( 'nightly', '1.2.3' ) );
	}

	public function test_each_channel_keeps_its_own_update_state(): void {
		$this->assertSame( UpdateChannel::all(), array_keys( Schema::UPDATE_STATE_OPTIONS ) );
		$this->assertNotSame( UpdateChannel::state_option( UpdateChannel::STABLE ), UpdateChannel::state_option( UpdateChannel::BETA ) );
		$this->assertSame( UpdateChannel::state_option( UpdateChannel::STABLE ), UpdateChannel::state_option( 'nightly' ) );
	}
}
