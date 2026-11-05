<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\Labels;
use WP_UnitTestCase;

final class LabelsTest extends WP_UnitTestCase {

	public function test_a_theme_filter_changes_one_label(): void {
		add_filter(
			'pixonaut_wc_storefront_label',
			static fn ( string $text, string $key ): string => 'line_state_shipped' === $key ? 'On its way' : $text,
			10,
			2
		);

		$labels = ( new Labels() )->all();

		$this->assertSame( 'On its way', $labels['line_state_shipped'] );
		$this->assertSame( 'Design saved', $labels['line_state_committed'] );
	}
}
