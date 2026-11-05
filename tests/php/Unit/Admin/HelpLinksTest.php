<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Admin;

use Pixonaut\WooCommerce\Admin\HelpLinks;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class HelpLinksTest extends TestCase {

	public function test_every_page_linked_from_wp_admin_is_in_the_merchant_guide(): void {
		foreach ( HelpLinks::PAGES as $page ) {
			$this->assertFileExists( dirname( __DIR__, 4 ) . '/docs/merchant/' . $page . '.md', $page );
		}
	}

	public function test_a_page_is_its_file_in_the_repository(): void {
		$this->assertSame( HelpLinks::DOCS_BASE . 'connect.md', HelpLinks::docs( HelpLinks::CONNECT ) );
		$this->assertSame( HelpLinks::DOCS_BASE . 'index.md', HelpLinks::docs() );
	}
}
