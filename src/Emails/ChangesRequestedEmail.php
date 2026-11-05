<?php
/**
 * The store's email about a customer who asked for changes to a proof.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent to the store when a customer asked for changes on the proof page of a
 * line waiting for their approval, with what they asked. The customer makes
 * the changes on the store's product page; the store only needs to know.
 */
final class ChangesRequestedEmail extends StoreEmail {

	public const ID = 'pixonaut_changes_requested';

	/**
	 * The line the email is about.
	 *
	 * @var WC_Order_Item_Product|null
	 */
	public ?WC_Order_Item_Product $item = null;

	/**
	 * What the customer asked.
	 *
	 * @var string
	 */
	public string $message = '';

	public function __construct() {
		$this->id = self::ID;
		/* translators: Name of an email to the merchant, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Pixonaut design changes requested', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the store when a customer asked for changes to the proof of their design, with what they asked.', 'pixonaut-for-woocommerce' );
		$this->email_group    = 'orders';
		$this->template_html  = Emails::CHANGES_REQUESTED_HTML;
		$this->template_plain = Emails::CHANGES_REQUESTED_PLAIN;
		$this->placeholders   = array(
			'{order_number}' => '',
			'{item_name}'    => '',
		);

		parent::__construct();
	}

	public function get_default_subject(): string {
		/* translators: Default subject of the email to the merchant. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( '[{site_title}]: Changes requested for order #{order_number}', 'pixonaut-for-woocommerce' );
	}

	public function get_default_heading(): string {
		/* translators: Default heading at the top of the email to the merchant. Text in braces is filled in by WooCommerce; keep it as written. */
		return __( 'Changes requested for order #{order_number}', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Sends the email about a line.
	 *
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $message What the customer asked.
	 */
	public function trigger( WC_Order_Item_Product $item, string $message ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->item                           = $item;
		$this->message                        = $message;
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
			'order'     => $order,
			'item_name' => null === $this->item ? '' : $this->item->get_name(),
			'message'   => $this->message,
			'order_url' => null === $order ? '' : $order->get_edit_order_url(),
		);
	}
}
