<?php
/**
 * The customer's email about a personalised line that shipped before the rest of the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Labels;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent to the customer when a Pixonaut line of an order with more than one
 * line ships and the order stays open, by Shipments. The shipment that
 * completes the order sends WooCommerce's completed email instead.
 */
class PartShippedEmail extends Email {

	public const ID = 'pixonaut_part_shipped';

	/**
	 * The line that shipped.
	 *
	 * @var WC_Order_Item_Product|null
	 */
	public ?WC_Order_Item_Product $item = null;

	public function __construct() {
		$this->id             = self::ID;
		$this->customer_email = true;
		/* translators: Name of the email to the customer, shown in WooCommerce > Settings > Emails. */
		$this->title          = _x( 'Part of your order has shipped', 'name of the part shipped email in WooCommerce email settings', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when a personalised item ships before the rest of the order. Not sent for an order with a single item, nor when the shipment completes the order.', 'pixonaut-for-woocommerce' );
		$this->email_group    = 'order-updates';
		$this->template_html  = 'emails/part-shipped.php';
		$this->template_plain = 'emails/plain/part-shipped.php';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{item_name}'    => '',
		);

		parent::__construct();
	}

	public function get_default_subject(): string {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'Part of your {site_title} order #{order_number} has shipped', 'pixonaut-for-woocommerce' );
	}

	public function get_default_heading(): string {
		/* translators: Heading at the top of the email to the customer. */
		return _x( 'Part of your order has shipped', 'heading of the part shipped email', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Sends the email about a line.
	 *
	 * @param WC_Order_Item_Product $item The line that shipped.
	 */
	public function trigger( WC_Order_Item_Product $item ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->item                           = $item;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{item_name}']    = $item->get_name();
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
			'item'  => $this->item,
			'intro' => $labels->get( Labels::PART_SHIPPED_INTRO, null === $this->item ? array() : array( 'item_id' => $this->item->get_id() ) ),
		);
	}
}
