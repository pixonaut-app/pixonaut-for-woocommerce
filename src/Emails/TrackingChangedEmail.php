<?php
/**
 * The customer's email about tracking added or corrected after a personalised line shipped.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Labels;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent to the customer by OrderTrackingChanged when the printshop gives
 * tracking for personalised lines that shipped without any, or corrects
 * their tracking. One email per order, with each line whose tracking changed
 * and its tracking as it now is.
 */
class TrackingChangedEmail extends Email {

	public const ID = 'pixonaut_tracking_changed';

	/**
	 * The lines whose tracking changed.
	 *
	 * @var list<WC_Order_Item_Product>
	 */
	public array $items = array();

	public function __construct() {
		$this->id             = self::ID;
		$this->customer_email = true;
		/* translators: Name of the email to the customer, shown in WooCommerce > Settings > Emails. */
		$this->title          = _x( 'Tracking updated', 'name of the tracking updated email in WooCommerce email settings', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when the printshop adds tracking to a personalised item that shipped without any, or corrects its tracking.', 'pixonaut-for-woocommerce' );
		$this->email_group    = 'order-updates';
		$this->template_html  = 'emails/tracking-changed.php';
		$this->template_plain = 'emails/plain/tracking-changed.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		parent::__construct();
	}

	public function get_default_subject(): string {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'New tracking for your {site_title} order #{order_number}', 'pixonaut-for-woocommerce' );
	}

	public function get_default_heading(): string {
		/* translators: Heading at the top of the email to the customer. */
		return _x( 'New tracking for your order', 'heading of the tracking updated email', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Sends the email about lines of one order.
	 *
	 * @param WC_Order_Item_Product[] $items The shipped lines whose tracking changed.
	 */
	public function trigger( array $items ): void {
		$order = array() === $items ? null : wc_get_order( $items[0]->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->items                          = array_values( $items );
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function template_args(): array {
		$labels = new Labels();
		return array(
			'order' => $this->object instanceof WC_Order ? $this->object : null,
			'items' => $this->items,
			'intro' => $labels->get( Labels::TRACKING_CHANGED_INTRO, array() === $this->items ? array() : array( 'item_id' => $this->items[0]->get_id() ) ),
		);
	}
}
