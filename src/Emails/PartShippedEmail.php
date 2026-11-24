<?php
/**
 * The customer's email about a parcel of personalised items that shipped before the rest of the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\ParcelLine;
use Pixonaut\WooCommerce\Labels;
use WC_Order;

/**
 * Sent to the customer by ParcelShipped, once per parcel, when a parcel of
 * personalised items ships and the order stays open. It lists each line in
 * the parcel with how many of its copies are in it, and the parcel's
 * tracking once. The parcel
 * that completes the order sends WooCommerce's completed email instead.
 */
class PartShippedEmail extends Email {

	public const ID = 'pixonaut_part_shipped';

	/**
	 * The parcel the email is about while it is sent.
	 *
	 * @var ParcelContent|null
	 */
	public ?ParcelContent $parcel = null;

	public function __construct() {
		$this->id             = self::ID;
		$this->customer_email = true;
		/* translators: Name of the email to the customer, shown in WooCommerce > Settings > Emails. */
		$this->title          = _x( 'Part of your order has shipped', 'name of the part shipped email in WooCommerce email settings', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer once per parcel of personalised items that ships before the rest of the order. Not sent for an order of a single item that ships whole, nor for the parcel that completes the order.', 'pixonaut-for-woocommerce' );
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
	 * Sends the email about a parcel.
	 *
	 * @param WC_Order                                                   $order    The order.
	 * @param ParcelLine[]                                               $lines    The parcel's lines.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The parcel's tracking; null when it shipped without any.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	public function trigger( WC_Order $order, array $lines, ?array $tracking ): void {
		if ( array() === $lines ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->parcel                         = new ParcelContent( $lines, $tracking );
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{item_name}']    = implode( ', ', array_map( static fn ( ParcelLine $line ): string => $line->item->get_name(), $lines ) );
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			ParcelContent::without_line_tracking(
				fn () => $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() )
			);
		}
		$this->restore_locale();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function template_args(): array {
		$labels = new Labels();
		$lines  = null === $this->parcel ? array() : $this->parcel->lines;
		return array(
			'order'  => $this->object instanceof WC_Order ? $this->object : null,
			'parcel' => null === $this->parcel ? null : $this->parcel->template_args(),
			'intro'  => $labels->get( Labels::PART_SHIPPED_INTRO, array() === $lines ? array() : array( 'item_id' => $lines[0]->item->get_id() ) ),
		);
	}
}
