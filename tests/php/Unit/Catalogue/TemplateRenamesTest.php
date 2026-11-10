<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\TemplateRenames;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class TemplateRenamesTest extends TestCase {

	use FakeOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
	}

	public function test_an_id_never_changed_is_its_own(): void {
		$this->assertSame( 'mini-tee', TemplateRenames::current( 'mini-tee' ) );
		$this->assertSame( 'mini-tee', TemplateRenames::first( 'mini-tee' ) );
		$this->assertFalse( TemplateRenames::same( 'mini-tee', 'mug' ) );
		$this->assertArrayNotHasKey( TemplateRenames::OPTION, $this->options );
	}

	public function test_a_chain_of_changes_is_followed_both_ways(): void {
		TemplateRenames::record( 'tee-copy-2', 'tee-pandas' );
		TemplateRenames::record( 'tee-pandas', 'tee-safari' );

		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee-copy-2' ) );
		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee-pandas' ) );
		$this->assertSame( 'tee-copy-2', TemplateRenames::first( 'tee-safari' ) );
		$this->assertSame( 'tee-copy-2', TemplateRenames::first( 'tee-pandas' ) );
		$this->assertTrue( TemplateRenames::same( 'tee-copy-2', 'tee-safari' ) );
	}

	public function test_recording_a_change_twice_writes_nothing_more(): void {
		TemplateRenames::record( 'tee-copy-2', 'tee-pandas' );
		$this->failing_options = array( TemplateRenames::OPTION );

		TemplateRenames::record( 'tee-copy-2', 'tee-pandas' );

		$this->assertSame( array( 'tee-copy-2' => 'tee-pandas' ), $this->options[ TemplateRenames::OPTION ] );
	}

	public function test_an_id_taken_back_is_a_template_s_id_again(): void {
		TemplateRenames::record( 'tee', 'tee-pandas' );
		TemplateRenames::record( 'tee-pandas', 'tee' );

		$this->assertSame( 'tee', TemplateRenames::current( 'tee' ) );
		$this->assertSame( 'tee', TemplateRenames::current( 'tee-pandas' ) );
	}

	public function test_an_id_of_digits_alone_is_kept_as_text(): void {
		TemplateRenames::record( '2024', 'tee-2024' );

		$this->assertSame( 'tee-2024', TemplateRenames::current( '2024' ) );
		$this->assertSame( '2024', TemplateRenames::first( 'tee-2024' ) );
	}
}
