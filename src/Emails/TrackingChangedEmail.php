<?php
/**
 * The customer's email about tracking added or corrected after a personalised line shipped.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\ParcelLine;
use Pixonaut\WooCommerce\Labels;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent to the customer when the printshop gives tracking for a parcel that
 * shipped without any, or corrects it. One email per parcel, with its lines,
 * how many of each are in it, and its tracking once.
 */
class TrackingChangedEmail extends Email {

	public const ID = 'pixonaut_tracking_changed';

	/**
	 * The lines whose tracking changed.
	 *
	 * @var list<WC_Order_Item_Product>
	 */
	public array $items = array();

	/**
	 * The parcel whose tracking changed, while the email about it is sent.
	 *
	 * @var ParcelContent|null
	 */
	public ?ParcelContent $parcel = null;

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
	 * Sends the email about a parcel's new tracking.
	 *
	 * @param WC_Order                                                   $order    The order.
	 * @param ParcelLine[]                                               $lines    The parcel's lines.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The parcel's tracking as it now is.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	public function trigger( WC_Order $order, array $lines, ?array $tracking ): void {
		if ( array() === $lines ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->items                          = array_map( static fn ( ParcelLine $line ): WC_Order_Item_Product => $line->item, $lines );
		$this->parcel                         = new ParcelContent( $lines, $tracking );
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			ParcelContent::without_line_tracking(
				fn () => $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() )
			);
		}
		$this->restore_locale();
		$this->parcel = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function template_args(): array {
		$labels = new Labels();
		return array(
			'order'  => $this->object instanceof WC_Order ? $this->object : null,
			'items'  => $this->items,
			'parcel' => null === $this->parcel ? null : $this->parcel->template_args(),
			'intro'  => $labels->get( Labels::TRACKING_CHANGED_INTRO, array() === $this->items ? array() : array( 'item_id' => $this->items[0]->get_id() ) ),
		);
	}
}
