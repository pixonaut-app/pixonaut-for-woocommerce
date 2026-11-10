<?php
/**
 * Links into the Pixonaut studio.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;

/**
 * A page of the studio for one design, order or blank. Every such page sits
 * under its workspace (`/w/<slug>/…`), so a merchant who belongs to several
 * workspaces lands in the store's. The studio keeps no page without the
 * workspace, so while the store knows no slug there is no link.
 *
 * Only for the merchant's screens: nothing a shopper sees links the studio.
 */
final class StudioPage {

	public const DESIGNS = 'designs';

	public const ORDERS = 'orders';

	public const BLANKS = 'blanks';

	/**
	 * The workspace's printshops, under its settings.
	 */
	public const PRINTSHOPS = 'settings/printshop';

	/**
	 * The page, or empty when the workspace's slug is not known yet.
	 *
	 * @param string $section One of `DESIGNS`, `ORDERS` or `BLANKS`.
	 * @param string $id      The design, order or blank.
	 */
	public static function url( string $section, string $id ): string {
		$slug = WorkspaceSettings::tenant_slug();
		if ( null === $slug ) {
			return '';
		}
		return untrailingslashit( Hosts::studio() ) . '/w/' . rawurlencode( $slug ) . '/' . $section . '/' . rawurlencode( $id );
	}

	/**
	 * A section's own page, such as `PRINTSHOPS`; empty when the workspace's
	 * slug is not known yet.
	 *
	 * @param string $section The section.
	 */
	public static function section( string $section ): string {
		$slug = WorkspaceSettings::tenant_slug();
		if ( null === $slug ) {
			return '';
		}
		return untrailingslashit( Hosts::studio() ) . '/w/' . rawurlencode( $slug ) . '/' . $section;
	}
}
