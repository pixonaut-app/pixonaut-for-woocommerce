<?php
/**
 * The "proof ready" email.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent when a paid line whose approval mode is proof by email starts waiting
 * for the customer: a link to each of its designs' proof pages.
 */
final class ProofReadyEmail extends LineEmail {

	public const ID = 'pixonaut_proof_ready';


	/**
	 * @param Templates $templates Renders the email's templates.
	 * @param Labels    $labels    The customer-facing texts.
	 */
	public function __construct( Templates $templates, Labels $labels ) {
		$this->id = self::ID;
		/* translators: Name of an email to the customer, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Design proof ready', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer after payment when a product\'s approval mode is proof by email, with a link to approve each design.', 'pixonaut-for-woocommerce' );
		$this->template_html  = Emails::PROOF_READY_HTML;
		$this->template_plain = Emails::PROOF_READY_PLAIN;
		add_action( Emails::PROOF_READY_TRIGGER . '_notification', array( $this, 'trigger' ) );
		parent::__construct( $templates, $labels );
	}

	/**
	 * @param mixed $item The line now waiting for the customer.
	 */
	public function trigger( mixed $item ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) || $this->send_for( $item ) ) {
			return;
		}
		// Disabled, or not delivered: the merchant passes the links on instead.
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: product name of the order line, 2: the proof page addresses, separated by spaces. */
					__( 'Pixonaut: the proof email for "%1$s" was not sent. Send the customer the proof link(s): %2$s', 'pixonaut-for-woocommerce' ),
					$item->get_name(),
					implode( ' ', self::proof_urls( $item ) )
				)
			);
		}
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'Please approve your design for order {order_number}', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		/* translators: Default heading at the top of the email to the customer. Text in braces is filled in by WooCommerce; keep it as written. */
		return __( 'Your proof is ready', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return array{proof_urls: list<string>, removed: string, intro: string, link_text: string}
	 */
	protected function template_args(): array {
		$context = array( 'item_id' => null === $this->item ? 0 : $this->item->get_id() );
		return array(
			'proof_urls' => null === $this->item ? array() : self::proof_urls( $this->item ),
			'removed'    => $this->removed(),
			'intro'      => $this->labels->get( Labels::PROOF_READY_INTRO, $context ),
			'link_text'  => $this->labels->get( Labels::REVIEW_PROOF, $context ),
		);
	}
}
