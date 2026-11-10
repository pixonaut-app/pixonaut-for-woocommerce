<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\TemplateRenames;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * Reading the history. Storing it is covered by the integration tests,
 * against the options table.
 */
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
	}

	public function test_a_chain_of_changes_is_followed_both_ways(): void {
		$this->history( array( 'tee-copy-2', 'tee-pandas', 1 ), array( 'tee-pandas', 'tee-safari', 2 ) );

		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee-copy-2' ) );
		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee-pandas' ) );
		$this->assertSame( 'tee-copy-2', TemplateRenames::first( 'tee-safari' ) );
		$this->assertSame( 'tee-copy-2', TemplateRenames::first( 'tee-pandas' ) );
		$this->assertTrue( TemplateRenames::same( 'tee-copy-2', 'tee-safari' ) );
	}

	public function test_a_change_noted_late_does_not_undo_a_later_one(): void {
		// The change to tee-pandas was handled again after tee-pandas became tee-safari.
		$this->history( array( 'tee-pandas', 'tee-safari', 2 ), array( 'tee', 'tee-pandas', 1 ), array( 'tee', 'tee-pandas', 1 ) );

		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee' ) );
		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee-pandas' ) );
		$this->assertSame( 'tee', TemplateRenames::first( 'tee-safari' ) );
		$this->assertSame( 'tee', TemplateRenames::first( 'tee-pandas' ) );
	}

	public function test_an_id_taken_back_is_a_template_s_id_again(): void {
		$this->history( array( 'tee', 'tee-pandas', 1 ), array( 'tee-pandas', 'tee', 2 ) );

		$this->assertSame( 'tee', TemplateRenames::current( 'tee' ) );
		$this->assertSame( 'tee', TemplateRenames::current( 'tee-pandas' ) );
		$this->assertSame( 'tee', TemplateRenames::first( 'tee' ) );
		$this->assertSame( 'tee', TemplateRenames::first( 'tee-pandas' ) );
	}

	public function test_an_id_taken_back_and_changed_again_ends_on_the_last_id(): void {
		$this->history( array( 'tee', 'tee-pandas', 1 ), array( 'tee-pandas', 'tee', 2 ), array( 'tee', 'tee-safari', 3 ) );

		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee' ) );
		$this->assertSame( 'tee-safari', TemplateRenames::current( 'tee-pandas' ) );
		$this->assertSame( 'tee', TemplateRenames::first( 'tee-safari' ) );
	}

	public function test_an_id_another_template_took_names_that_template(): void {
		$this->history( array( 'tee', 'tee-pandas', 1 ), array( 'hoodie', 'tee', 2 ) );

		$this->assertSame( 'tee', TemplateRenames::current( 'tee' ) );
		$this->assertSame( 'tee-pandas', TemplateRenames::current( 'tee-pandas' ) );
		$this->assertSame( 'hoodie', TemplateRenames::first( 'tee' ) );
		$this->assertSame( 'tee', TemplateRenames::first( 'tee-pandas' ) );
		$this->assertFalse( TemplateRenames::same( 'tee', 'tee-pandas' ) );
	}

	public function test_an_id_of_digits_alone_is_kept_as_text(): void {
		$this->history( array( '2024', 'tee-2024', 1 ) );

		$this->assertSame( 'tee-2024', TemplateRenames::current( '2024' ) );
		$this->assertSame( '2024', TemplateRenames::first( 'tee-2024' ) );
	}

	public function test_a_malformed_change_is_ignored(): void {
		$this->options[ TemplateRenames::OPTION ] = array(
			'tee' => 'tee-pandas',
			array(
				'from' => 'tee',
				'to'   => 'tee-safari',
			),
		);

		$this->assertSame( 'tee', TemplateRenames::current( 'tee' ) );
	}

	/**
	 * Stores changes as `record()` does.
	 *
	 * @param array{string, string, int} ...$changes Each change's old id, new id and time.
	 */
	private function history( array ...$changes ): void {
		$this->options[ TemplateRenames::OPTION ] = array_map(
			static fn ( array $change ): array => array(
				'from' => $change[0],
				'to'   => $change[1],
				'at'   => $change[2],
			),
			$changes
		);
	}
}
