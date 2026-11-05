<?php
/**
 * The store's email about a personalised line that needs attention.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent to the store when a Pixonaut line moves to `needs_attention`, once per
 * line each time it does, by AttentionAlerts.
 */
class NeedsAttentionEmail extends StoreEmail {

	public const ID = 'pixonaut_needs_attention';

	/**
	 * The line the email is about.
	 *
	 * @var WC_Order_Item_Product|null
	 */
	public ?WC_Order_Item_Product $item = null;

	/**
	 * Why the line needs attention: the code its state holds.
	 *
	 * @var string
	 */
	public string $reason = '';

	public function __construct() {
		$this->id = self::ID;
		/* translators: Name of an email to the merchant, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Pixonaut item needs attention', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the store when a personalised item cannot go on to the printshop without the store\'s help.', 'pixonaut-for-woocommerce' );
		$this->email_group    = 'orders';
		$this->template_html  = 'emails/needs-attention.php';
		$this->template_plain = 'emails/plain/needs-attention.php';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{item_name}'    => '',
		);

		parent::__construct();
	}

	public function get_default_subject(): string {
		/* translators: Default subject of the email to the merchant. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( '[{site_title}]: Order #{order_number} needs attention', 'pixonaut-for-woocommerce' );
	}

	public function get_default_heading(): string {
		/* translators: Default heading at the top of the email to the merchant. Text in braces is filled in by WooCommerce; keep it as written. */
		return __( 'Order #{order_number} needs attention', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Sends the email about a line.
	 *
	 * @param WC_Order_Item_Product $item   The line.
	 * @param string                $reason The code its state holds.
	 */
	public function trigger( WC_Order_Item_Product $item, string $reason ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->item                           = $item;
		$this->reason                         = $reason;
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
		$order = $this->object instanceof WC_Order ? $this->object : null;
		return array(
			'order'       => $order,
			'item_name'   => null === $this->item ? '' : $this->item->get_name(),
			'reason'      => $this->reason,
			'explanation' => NeedsAttention::explain( $this->reason ),
			'order_url'   => null === $order ? '' : $order->get_edit_order_url(),
		);
	}
}
