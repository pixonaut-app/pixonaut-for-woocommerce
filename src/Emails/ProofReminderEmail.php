<?php
/**
 * The proof reminder email.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Approval\ProofOutcome;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Order_Item_Product;

/**
 * Sent while a proof-by-email line still waits for the customer, as the
 * reminder settings schedule it: the links to its proof pages again. The
 * last reminder also says when the deadline is and what happens then.
 */
final class ProofReminderEmail extends LineEmail {

	public const ID = 'pixonaut_proof_reminder';

	/**
	 * What the last reminder says, by what happens at the deadline.
	 */
	private const DEADLINE_LABELS = array(
		ProofOutcome::APPROVE           => Labels::PROOF_DEADLINE_APPROVE,
		ProofOutcome::CANCEL            => Labels::PROOF_DEADLINE_CANCEL,
		ProofOutcome::REFUND_AND_CANCEL => Labels::PROOF_DEADLINE_REFUND,
		ProofOutcome::NEEDS_ATTENTION   => Labels::PROOF_DEADLINE_REVIEW,
	);

	/**
	 * The deadline sentence of the reminder being sent; empty for one that is not the last.
	 *
	 * @var string
	 */
	private string $deadline = '';

	/**
	 * @param Templates $templates Renders the email's templates.
	 * @param Labels    $labels    The customer-facing texts.
	 */
	public function __construct( Templates $templates, Labels $labels ) {
		$this->id = self::ID;
		/* translators: Name of an email to the customer, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Design proof reminder', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to a customer who has not answered their proof yet, as the proof reminder settings say. The last one says when the deadline is and what happens then.', 'pixonaut-for-woocommerce' );
		$this->template_html  = Emails::PROOF_REMINDER_HTML;
		$this->template_plain = Emails::PROOF_REMINDER_PLAIN;
		parent::__construct( $templates, $labels );
	}

	/**
	 * @param WC_Order_Item_Product $item     The line still waiting for the customer.
	 * @param bool                  $last     Whether it is the last reminder.
	 * @param int                   $deadline Unix time of the deadline.
	 * @param string                $outcome  What happens at the deadline: `approve`, or the "On deny" action.
	 */
	public function trigger( WC_Order_Item_Product $item, bool $last, int $deadline, string $outcome ): void {
		if ( ! LineState::is_tracked( $item ) ) {
			return;
		}
		$label          = self::DEADLINE_LABELS[ $outcome ] ?? Labels::PROOF_DEADLINE_REVIEW;
		$this->deadline = $last ? sprintf( $this->labels->get( $label, array( 'item_id' => $item->get_id() ) ), wc_format_datetime( wc_string_to_datetime( '@' . $deadline ) ) ) : '';
		$this->send_for( $item );
		$this->deadline = '';
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'Reminder: please approve your design for order {order_number}', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		/* translators: Default heading at the top of the email to the customer. Text in braces is filled in by WooCommerce; keep it as written. */
		return __( 'Your proof is waiting for you', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return array{proof_urls: list<string>, removed: string, intro: string, link_text: string, deadline: string}
	 */
	protected function template_args(): array {
		$context = array( 'item_id' => null === $this->item ? 0 : $this->item->get_id() );
		return array(
			'proof_urls' => null === $this->item ? array() : self::proof_urls( $this->item ),
			'removed'    => $this->removed(),
			'intro'      => $this->labels->get( Labels::PROOF_REMINDER_INTRO, $context ),
			'link_text'  => $this->labels->get( Labels::REVIEW_PROOF, $context ),
			'deadline'   => $this->deadline,
		);
	}
}
