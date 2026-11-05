<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;

final class LabelsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_returns_the_default_text(): void {
		$this->assertSame( 'Awaiting your approval', ( new Labels() )->get( Labels::line_state_key( LineState::AWAITING_CUSTOMER ) ) );
	}

	public function test_filter_changes_a_label(): void {
		Filters\expectApplied( 'pixonaut_wc_storefront_label' )
			->once()
			->with( 'Shipped', 'line_state_shipped', array( 'order_id' => 7 ) )
			->andReturn( 'On its way' );

		$this->assertSame( 'On its way', ( new Labels() )->get( 'line_state_shipped', array( 'order_id' => 7 ) ) );
	}

	public function test_a_label_splits_around_the_words_it_links(): void {
		$this->assertSame( array( 'I approve the ', 'preview', '' ), Labels::link_parts( 'I approve the %1$spreview%2$s' ) );
		$this->assertSame( array( 'Ich gebe die ', 'Vorschau', ' frei' ), Labels::link_parts( 'Ich gebe die %1$sVorschau%2$s frei' ) );
		$this->assertSame( 'Ich gebe die Vorschau frei', Labels::without_link( 'Ich gebe die %1$sVorschau%2$s frei' ) );
	}

	public function test_a_label_without_both_marks_links_nothing(): void {
		$this->assertNull( Labels::link_parts( 'I approve the preview' ) );
		$this->assertNull( Labels::link_parts( 'I approve the %1$spreview' ) );
		$this->assertNull( Labels::link_parts( 'I approve the %2$spreview%1$s' ) );
	}

	public function test_unknown_key_reads_as_itself(): void {
		$this->assertSame( 'no_such_label', ( new Labels() )->get( 'no_such_label' ) );
	}

	public function test_every_line_state_has_a_label(): void {
		$labels = ( new Labels() )->all();

		foreach ( LineState::STATES as $state ) {
			$this->assertArrayHasKey( Labels::line_state_key( $state ), $labels, $state );
		}
		$this->assertArrayHasKey( Labels::PERSONALISATION_UNAVAILABLE, $labels );
	}

	public function test_every_label_says_where_it_is_shown(): void {
		$labels = new Labels();

		foreach ( $labels->definitions() as $key => $label ) {
			$this->assertNotSame( '', $label['shown'], $key );
		}
		$this->assertSame( array_keys( $labels->definitions() ), array_keys( $labels->all() ) );
	}
}
