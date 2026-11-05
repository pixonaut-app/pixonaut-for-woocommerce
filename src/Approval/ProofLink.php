<?php
/**
 * What a proof page link is minted with.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Plugin;
use WC_Order_Item_Product;

/**
 * The lifetime, language and return address of the proof page a customer
 * approves their design on. The link outlives the approval deadline by a
 * margin, so a customer who opens it on the last day still finds it working,
 * and never lives longer than Pixonaut keeps a proof page link.
 */
final class ProofLink {

	/**
	 * Longest a proof page link may live, as Pixonaut caps it.
	 */
	public const MAX_LINK_DAYS = 90;

	/**
	 * Days the link outlives the approval deadline.
	 */
	public const MARGIN_DAYS = 7;

	/**
	 * Longest approval deadline: one whose link, with the margin, still fits.
	 */
	public const MAX_DEADLINE_DAYS = self::MAX_LINK_DAYS - self::MARGIN_DAYS;

	public const DEFAULT_DEADLINE_DAYS = 14;

	/**
	 * Path, under the plugin's REST namespace, of the route a proof page
	 * sends a customer back to, with the order item as `item`.
	 */
	public const RETURN_ROUTE = '/order-items/(?P<item>\d+)/edit';

	private const ITEM_PATTERN = '(?P<item>\d+)';

	/**
	 * How long the link works: the deadline plus the margin, at most
	 * MAX_LINK_DAYS.
	 *
	 * @param int                   $deadline_days The approval deadline setting.
	 * @param WC_Order_Item_Product $item          The order line the link is for.
	 */
	public static function days( int $deadline_days, WC_Order_Item_Product $item ): int {
		/**
		 * Filters how many days a proof page link works. Pixonaut takes at
		 * most 90; a longer value is cut to that.
		 *
		 * @since 0.1.0
		 *
		 * @param int                   $days          The approval deadline plus a week.
		 * @param int                   $deadline_days The approval deadline setting.
		 * @param WC_Order_Item_Product $item          The order line the link is for.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_approval_proof_link_days', function ( $days, $deadline_days ) {
		 *     return $deadline_days + 30;
		 * }, 10, 2 );
		 */
		$days = (int) apply_filters( 'pixonaut_wc_approval_proof_link_days', $deadline_days + self::MARGIN_DAYS, $deadline_days, $item );
		return max( 1, min( self::MAX_LINK_DAYS, $days ) );
	}

	/**
	 * The WordPress locale a line's proof page follows: the one the customer
	 * bought in, else the site's, for a line bought before it was kept. A
	 * proof sent later, from a job or the store's screens, is then still in
	 * the customer's language rather than the store manager's.
	 *
	 * @param LineState $line The line.
	 */
	public static function locale( LineState $line ): string {
		$locale = $line->ref( LineState::REF_LOCALE );
		return is_string( $locale ) && '' !== $locale ? $locale : get_locale();
	}

	/**
	 * The address a proof page sends a customer back to, to edit the design
	 * of an order line.
	 *
	 * @param int $item_id The order line.
	 */
	public static function return_url( int $item_id ): string {
		return rest_url( Plugin::REST_NAMESPACE . str_replace( self::ITEM_PATTERN, (string) $item_id, self::RETURN_ROUTE ) );
	}
}
