<?php
/**
 * Where the merchant docs and support are.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

/**
 * The merchant guide is `docs/merchant/` in the plugin's repository, read
 * on GitHub; each page constant names one of its files.
 */
final class HelpLinks {

	/**
	 * The merchant guide's folder, `<page>.md` under it.
	 */
	public const DOCS_BASE = 'https://github.com/pixonaut-app/pixonaut-for-woocommerce/blob/main/docs/merchant/';

	/**
	 * Where a merchant asks for help or reports a problem.
	 */
	public const SUPPORT_URL = 'https://github.com/pixonaut-app/pixonaut-for-woocommerce/issues';

	public const INDEX = 'index';

	public const CONNECT = 'connect';

	public const LINKING_PRODUCTS = 'linking-products';

	public const TROUBLESHOOTING = 'troubleshooting';

	/**
	 * Every page linked from wp-admin.
	 */
	public const PAGES = array( self::INDEX, self::CONNECT, self::LINKING_PRODUCTS, self::TROUBLESHOOTING );

	/**
	 * A page of the merchant guide.
	 *
	 * @param string $page One of PAGES.
	 */
	public static function docs( string $page = self::INDEX ): string {
		return self::DOCS_BASE . $page . '.md';
	}
}
