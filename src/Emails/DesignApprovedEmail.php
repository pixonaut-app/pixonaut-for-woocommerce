<?php
/**
 * The "design approved" email.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Order_Item_Product;

/**
 * Sent when the customer approved the proof of a line whose approval mode is
 * proof by email. Off until the store turns it on. Not sent for a line
 * the store approved because its deadline passed: the customer approved
 * nothing.
 */
final class DesignApprovedEmail extends LineEmail {

	public const ID = 'pixonaut_design_approved';

	/**
	 * The consent kind of a line the store approved at its proof deadline.
	 */
	private const DEADLINE_CONSENT = 'deadline';


	/**
	 * @param Templates $templates Renders the email's templates.
	 * @param Labels    $labels    The customer-facing texts.
	 */
	public function __construct( Templates $templates, Labels $labels ) {
		$this->id = self::ID;
		/* translators: Name of the email to the customer that confirms their approval, shown in WooCommerce > Settings > Emails. */
		$this->title          = _x( 'Design approved', 'name of the design approved email in WooCommerce email settings', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when they approved the proof of their design, with proof by email. Off by default.', 'pixonaut-for-woocommerce' );
		$this->template_html  = Emails::DESIGN_APPROVED_HTML;
		$this->template_plain = Emails::DESIGN_APPROVED_PLAIN;
		add_action( Emails::DESIGN_APPROVED_TRIGGER . '_notification', array( $this, 'trigger' ), 10, 2 );
		parent::__construct( $templates, $labels );
	}

	/**
	 * Off by default.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['enabled']['default'] = 'no';
	}

	/**
	 * @param mixed $item The line just approved.
	 * @param mixed $from The state it left; only an approval by the customer, from `awaiting_customer`, sends the email.
	 */
	public function trigger( mixed $item, mixed $from = null ): void {
		if ( $item instanceof WC_Order_Item_Product && LineState::AWAITING_CUSTOMER === $from && self::DEADLINE_CONSENT !== ( LineState::of( $item )->ref( LineState::REF_CONSENT )['kind'] ?? null ) ) {
			$this->send_for( $item );
		}
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'Your design for order {order_number} is approved', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		/* translators: Heading at the top of the email to the customer that confirms their approval. */
		return _x( 'Design approved', 'heading of the email confirming the customer approved their design', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return array{intro: string}
	 */
	protected function template_args(): array {
		return array(
			'intro' => $this->labels->get( Labels::DESIGN_APPROVED_INTRO, array( 'item_id' => null === $this->item ? 0 : $this->item->get_id() ) ),
		);
	}
}
