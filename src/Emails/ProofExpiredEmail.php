<?php
/**
 * The "cancelled after deadline" email.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Order_Item_Product;

/**
 * Sent to the customer when a proof-by-email line they did not approve by
 * the deadline was cancelled, refunded too when "On deny" refunds.
 */
final class ProofExpiredEmail extends LineEmail {

	public const ID = 'pixonaut_proof_expired';

	/**
	 * Whether the line being told about was refunded.
	 *
	 * @var bool
	 */
	private bool $refunded = false;

	/**
	 * @param Templates $templates Renders the email's templates.
	 * @param Labels    $labels    The customer-facing texts.
	 */
	public function __construct( Templates $templates, Labels $labels ) {
		$this->id = self::ID;
		/* translators: Name of an email to the customer, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Design cancelled after deadline', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when an item whose proof they did not approve by the deadline was cancelled.', 'pixonaut-for-woocommerce' );
		$this->template_html  = Emails::PROOF_EXPIRED_HTML;
		$this->template_plain = Emails::PROOF_EXPIRED_PLAIN;
		parent::__construct( $templates, $labels );
	}

	/**
	 * @param WC_Order_Item_Product $item     The line cancelled.
	 * @param bool                  $refunded Whether it was refunded too.
	 */
	public function trigger( WC_Order_Item_Product $item, bool $refunded ): void {
		$this->refunded = $refunded;
		$this->send_for( $item );
		$this->refunded = false;
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'An item of your order {order_number} was cancelled', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		/* translators: Default heading at the top of the email to the customer. Text in braces is filled in by WooCommerce; keep it as written. */
		return __( 'Your design was not approved in time', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return array{intro: string, refunded: string}
	 */
	protected function template_args(): array {
		$context = array( 'item_id' => null === $this->item ? 0 : $this->item->get_id() );
		return array(
			'intro'    => $this->labels->get( Labels::PROOF_EXPIRED_INTRO, $context ),
			'refunded' => $this->refunded ? $this->labels->get( Labels::PROOF_EXPIRED_REFUNDED, $context ) : '',
		);
	}
}
