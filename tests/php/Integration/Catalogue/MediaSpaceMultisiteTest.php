<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\MediaSpace;
use WP_UnitTestCase;

/**
 * On a WordPress network enforcing upload quotas, the shop's free media
 * space is what is left of its site's quota, not the disk's.
 *
 * Runs only on a multisite install: `pnpm test:integration` runs this group
 * with `WP_MULTISITE=1`.
 *
 * @group multisite
 */
final class MediaSpaceMultisiteTest extends WP_UnitTestCase {

	/**
	 * The site's upload quota, in megabytes.
	 */
	private const QUOTA_MB = 50;

	/**
	 * Free space the disk would say it has.
	 */
	private const DISK_FREE = 999 * GB_IN_BYTES;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite install (WP_MULTISITE=1).' );
		}
	}

	public function test_it_is_what_is_left_of_the_sites_upload_quota(): void {
		update_site_option( 'upload_space_check_disabled', 0 );
		update_option( 'blog_upload_space', self::QUOTA_MB );

		$free = ( new MediaSpace( static fn (): int => self::DISK_FREE ) )->free();

		$this->assertSame( (int) round( ( self::QUOTA_MB - get_space_used() ) * MB_IN_BYTES ), $free );
	}

	public function test_a_network_without_quotas_reads_the_disk(): void {
		update_site_option( 'upload_space_check_disabled', 1 );

		$this->assertSame( self::DISK_FREE, ( new MediaSpace( static fn (): int => self::DISK_FREE ) )->free() );
	}
}
