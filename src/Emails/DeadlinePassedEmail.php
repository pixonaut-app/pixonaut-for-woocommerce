<?php
/**
 * The store's email about a proof whose deadline passed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Approval\ProofOutcome;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Sent to the store when a customer did not answer the proof of an item by
 * its deadline, with what the store did about it.
 */
final class DeadlinePassedEmail extends StoreEmail {

	public const ID = 'pixonaut_deadline_passed';

	/**
	 * The line the email is about.
	 *
	 * @var WC_Order_Item_Product|null
	 */
	public ?WC_Order_Item_Product $item = null;

	/**
	 * What became of it: `approve`, or an "On deny" action.
	 *
	 * @var string
	 */
	public string $outcome = '';

	public function __construct() {
		$this->id = self::ID;
		/* translators: Name of an email to the merchant, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Pixonaut proof deadline passed', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the store when a customer did not answer the proof of their design by the deadline, with what happened to the item.', 'pixonaut-for-woocommerce' );
		$this->email_group    = 'orders';
		$this->template_html  = Emails::DEADLINE_PASSED_HTML;
		$this->template_plain = Emails::DEADLINE_PASSED_PLAIN;
		$this->placeholders   = array(
			'{order_number}' => '',
			'{item_name}'    => '',
		);

		parent::__construct();
	}

	public function get_default_subject(): string {
		/* translators: Default subject of the email to the merchant. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( '[{site_title}]: Proof deadline passed for order #{order_number}', 'pixonaut-for-woocommerce' );
	}

	public function get_default_heading(): string {
		/* translators: Default heading at the top of the email to the merchant. Text in braces is filled in by WooCommerce; keep it as written. */
		return __( 'Proof deadline passed for order #{order_number}', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Sends the email about a line.
	 *
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $outcome What became of it.
	 */
	public function trigger( WC_Order_Item_Product $item, string $outcome ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->item                           = $item;
		$this->outcome                        = $outcome;
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{item_name}']    = $item->get_name();
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/**
	 * What became of the item, in words.
	 *
	 * @param string      $outcome `approve`, `unresolved`, or an "On deny" action.
	 * @param string|null $reason  The line's attention reason, for `unresolved`.
	 */
	public static function describe( string $outcome, ?string $reason = null ): string {
		return match ( $outcome ) {
			/* translators: %s: why, as the store explains the item's attention reason. */
			ProofOutcome::UNRESOLVED        => sprintf( __( 'The store could not decide its proof, so the item needs your attention. %s', 'pixonaut-for-woocommerce' ), NeedsAttention::explain( $reason ?? '' ) ),
			ProofOutcome::APPROVE           => __( 'Its design was approved as the proof shows it and is on its way to print.', 'pixonaut-for-woocommerce' ),
			ProofOutcome::REFUND_AND_CANCEL => __( 'Its design was rejected, and the item cancelled and refunded. The customer was told.', 'pixonaut-for-woocommerce' ),
			ProofOutcome::CANCEL            => __( 'Its design was rejected and the item cancelled. The customer was told. Refund it if you need to.', 'pixonaut-for-woocommerce' ),
			default                         => __( 'Its design was rejected and will not be printed. The item needs your attention: refund or cancel it.', 'pixonaut-for-woocommerce' ),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function template_args(): array {
		$order = $this->object instanceof WC_Order ? $this->object : null;
		return array(
			'order'     => $order,
			'item_name' => null === $this->item ? '' : $this->item->get_name(),
			'outcome'   => self::describe( $this->outcome, null === $this->item || ! LineState::is_tracked( $this->item ) ? null : LineState::of( $this->item )->reason() ),
			'order_url' => null === $order ? '' : $order->get_edit_order_url(),
		);
	}
}
