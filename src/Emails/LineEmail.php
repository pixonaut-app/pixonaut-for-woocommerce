<?php
/**
 * A customer email about one Pixonaut order line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A WooCommerce email to the customer about one order line, rendered from
 * the plugin's templates, which a theme overrides under
 * `woocommerce/pixonaut/`. Construct only once WooCommerce's emails are
 * loaded (the `woocommerce_email_classes` filter).
 */
abstract class LineEmail extends WC_Email {

	/**
	 * The line the email is about while it is sent.
	 *
	 * @var WC_Order_Item_Product|null
	 */
	protected ?WC_Order_Item_Product $item = null;

	/**
	 * @param Templates $templates Renders the email's templates.
	 * @param Labels    $labels    The customer-facing texts.
	 */
	public function __construct(
		protected readonly Templates $templates,
		protected readonly Labels $labels,
	) {
		$this->customer_email = true;
		$this->template_base  = $templates->default_path();
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		parent::__construct();
	}

	/**
	 * Sends the email about a line to the order's customer, when the email
	 * is enabled.
	 *
	 * @param WC_Order_Item_Product $item The order line.
	 * @return bool Whether it was sent: false when it is disabled, the order has no customer email, or sending failed.
	 */
	protected function send_for( WC_Order_Item_Product $item ): bool {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$sent = false;
		$this->setup_locale();
		$this->item                           = $item;
		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$created                              = $order->get_date_created();
		$this->placeholders['{order_date}']   = null === $created ? '' : wc_format_datetime( $created );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		$this->item = null;
		return $sent;
	}

	/**
	 * The line's proof pages still waiting for the customer.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 * @return list<string>
	 */
	protected static function proof_urls( WC_Order_Item_Product $item ): array {
		return LineState::of( $item )->waiting_proof_pages();
	}

	/**
	 * The line saying the line's personalisation was removed, as
	 * "Personalisation: Removed after the shop's retention period"; empty
	 * while no side's design was erased.
	 */
	protected function removed(): string {
		if ( null === $this->item ) {
			return '';
		}
		$line = LineState::of( $this->item );
		foreach ( $line->sides() as $side ) {
			if ( $line->is_side_erased( $side ) ) {
				$context = array( 'item_id' => $this->item->get_id() );
				return $this->labels->get( Labels::PERSONALISATION, $context ) . ': ' . $this->labels->get( Labels::PERSONALISATION_REMOVED, $context );
			}
		}
		return '';
	}

	/**
	 * What the email's template receives besides WooCommerce's usual variables.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function template_args(): array;

	/**
	 * Where a theme overrides the template: next to the plugin's other overrides.
	 *
	 * @param string $template Template path below `templates/`.
	 */
	public function get_theme_template_file( $template ): string {
		return get_stylesheet_directory() . '/' . Templates::THEME_PATH . $template;
	}

	/**
	 * @return string
	 */
	public function get_content_html() {
		return $this->render( $this->template_html, false );
	}

	/**
	 * @return string
	 */
	public function get_content_plain() {
		return $this->render( $this->template_plain, true );
	}

	private function render( string $template, bool $plain_text ): string {
		return $this->templates->html(
			$template,
			Emails::template_args(
				array(
					'order'              => $this->object,
					'item'               => $this->item,
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => $plain_text,
					'email'              => $this,
				) + $this->template_args(),
				$this,
				$plain_text
			)
		);
	}
}
