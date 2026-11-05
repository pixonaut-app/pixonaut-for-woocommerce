<?php
/**
 * Drops the notices of products in the trash.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\TrashedProducts;
use Pixonaut\WooCommerce\Templates;

/**
 * A product put in the trash kept its notice that customers cannot buy it,
 * and a re-check raised it again. Trashing one now drops its notices; this
 * drops those raised before, and those of products deleted since.
 */
final class ForgetTrashedProductNotices implements Migration {

	public function version(): int {
		return 7;
	}

	public function up(): void {
		// The notices are only removed here, never rendered.
		TrashedProducts::forget_notices( new Notices( Templates::for_plugin( dirname( __DIR__, 2 ) . '/pixonaut-for-woocommerce.php' ) ) );
	}
}
