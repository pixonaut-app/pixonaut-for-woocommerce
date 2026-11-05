<?php
/**
 * Adds the plugin's emails to WooCommerce's.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Approval\ProofOutcome;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Email;
use WC_Order_Item_Product;

/**
 * Lists the plugin's WooCommerce emails, relays the line transitions that
 * send the customer's emails through WooCommerce's transactional emails, and
 * sends the emails about a customer's request for changes. The
 * emails extend `WC_Email`, which exists only once WooCommerce loads its
 * mailer, so nothing here loads them before WooCommerce asks for them: the
 * constants they share live here.
 */
final class Emails {

	/**
	 * The actions, after WooCommerce's transactional email relay, that send
	 * the customer's emails: a line waiting for the customer, and a line
	 * approved.
	 */
	public const PROOF_READY_TRIGGER = 'pixonaut_wc_fulfilment_awaiting_customer';

	public const DESIGN_APPROVED_TRIGGER = 'pixonaut_wc_fulfilment_approved';

	public const PROOF_READY_HTML = 'emails/proof-ready.php';

	public const PROOF_READY_PLAIN = 'emails/plain/proof-ready.php';

	public const DESIGN_APPROVED_HTML = 'emails/design-approved.php';

	public const DESIGN_APPROVED_PLAIN = 'emails/plain/design-approved.php';

	public const CHANGES_LINK_HTML = 'emails/changes-link.php';

	public const CHANGES_LINK_PLAIN = 'emails/plain/changes-link.php';

	public const CHANGES_REQUESTED_HTML = 'emails/changes-requested.php';

	public const CHANGES_REQUESTED_PLAIN = 'emails/plain/changes-requested.php';

	public const PROOF_REMINDER_HTML = 'emails/proof-reminder.php';

	public const PROOF_REMINDER_PLAIN = 'emails/plain/proof-reminder.php';

	public const PROOF_EXPIRED_HTML = 'emails/proof-expired.php';

	public const PROOF_EXPIRED_PLAIN = 'emails/plain/proof-expired.php';

	public const DEADLINE_PASSED_HTML = 'emails/deadline-passed.php';

	public const DEADLINE_PASSED_PLAIN = 'emails/plain/deadline-passed.php';

	public function __construct(
		private readonly Templates $templates,
		private readonly Labels $labels,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'add_classes' ) );
		add_filter( 'woocommerce_email_actions', array( $this, 'add_actions' ) );
		add_action( 'pixonaut_wc_approval_changes_requested', array( $this, 'changes_requested' ), 10, 4 );
		add_action( 'pixonaut_wc_approval_proof_reminder', array( $this, 'proof_reminder' ), 10, 5 );
		add_action( 'pixonaut_wc_approval_deadline_passed', array( $this, 'deadline_passed' ), 10, 2 );
	}

	/**
	 * @param array<string, WC_Email> $emails WooCommerce's emails, by class.
	 *
	 * @return array<string, WC_Email>
	 */
	public function add_classes( array $emails ): array {
		$emails[ NeedsAttentionEmail::class ]   = new NeedsAttentionEmail();
		$emails[ PartShippedEmail::class ]      = new PartShippedEmail();
		$emails[ TrackingChangedEmail::class ]  = new TrackingChangedEmail();
		$emails[ ProofReadyEmail::class ]       = new ProofReadyEmail( $this->templates, $this->labels );
		$emails[ DesignApprovedEmail::class ]   = new DesignApprovedEmail( $this->templates, $this->labels );
		$emails[ ChangesLinkEmail::class ]      = new ChangesLinkEmail( $this->templates, $this->labels );
		$emails[ ChangesRequestedEmail::class ] = new ChangesRequestedEmail();
		$emails[ ProofReminderEmail::class ]    = new ProofReminderEmail( $this->templates, $this->labels );
		$emails[ ProofExpiredEmail::class ]     = new ProofExpiredEmail( $this->templates, $this->labels );
		$emails[ DeadlinePassedEmail::class ]   = new DeadlinePassedEmail();
		return $emails;
	}

	/**
	 * Tells the store what the customer asked and, while the design can
	 * still be changed, sends the customer the link that opens it again. Sent at once, not through WooCommerce's
	 * deferred relay, whose queue keeps no objects.
	 *
	 * @param mixed $item     The order line.
	 * @param mixed $side     The side whose proof the customer asked to change.
	 * @param mixed $message  What the customer asked.
	 * @param mixed $edit_url The address that opens the design again; null when it can no longer be changed.
	 */
	public function changes_requested( mixed $item, mixed $side, mixed $message, mixed $edit_url ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$emails   = WC()->mailer()->get_emails();
		$customer = $emails[ ChangesLinkEmail::class ] ?? null;
		$store    = $emails[ ChangesRequestedEmail::class ] ?? null;
		if ( $customer instanceof ChangesLinkEmail && is_string( $edit_url ) ) {
			$customer->trigger( $item, $edit_url );
		}
		if ( $store instanceof ChangesRequestedEmail ) {
			$store->trigger( $item, (string) $message );
		}
	}

	/**
	 * Reminds the customer of a proof still waiting for them. Sent at once,
	 * like the emails about a request for changes.
	 *
	 * @param mixed $item     The order line.
	 * @param mixed $number   The reminder's number, from 1.
	 * @param mixed $last     Whether it is the last reminder.
	 * @param mixed $deadline Unix time of the deadline.
	 * @param mixed $outcome  What happens at the deadline.
	 */
	public function proof_reminder( mixed $item, mixed $number, mixed $last, mixed $deadline, mixed $outcome ): void {
		$email = WC()->mailer()->get_emails()[ ProofReminderEmail::class ] ?? null;
		if ( $item instanceof WC_Order_Item_Product && $email instanceof ProofReminderEmail ) {
			$email->trigger( $item, true === $last, (int) $deadline, (string) $outcome );
		}
	}

	/**
	 * Tells the store what became of a line whose proof deadline passed and,
	 * for a line cancelled, the customer too.
	 *
	 * @param mixed $item    The order line.
	 * @param mixed $outcome What became of it: `approve`, or an "On deny" action.
	 */
	public function deadline_passed( mixed $item, mixed $outcome ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$emails   = WC()->mailer()->get_emails();
		$store    = $emails[ DeadlinePassedEmail::class ] ?? null;
		$customer = $emails[ ProofExpiredEmail::class ] ?? null;
		if ( $store instanceof DeadlinePassedEmail ) {
			$store->trigger( $item, (string) $outcome );
		}
		if ( $customer instanceof ProofExpiredEmail && in_array( $outcome, array( ProofOutcome::CANCEL, ProofOutcome::REFUND_AND_CANCEL ), true ) ) {
			$customer->trigger( $item, ProofOutcome::REFUND_AND_CANCEL === $outcome );
		}
	}

	/**
	 * @param mixed $actions The actions WooCommerce relays to `<action>_notification`.
	 * @return mixed
	 */
	public function add_actions( mixed $actions ): mixed {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		return array_merge( $actions, array( self::PROOF_READY_TRIGGER, self::DESIGN_APPROVED_TRIGGER ) );
	}

	/**
	 * The variables an email's template receives, as
	 * `pixonaut_wc_emails_template_args` filters them.
	 *
	 * @param array<string, mixed> $args       The variables.
	 * @param WC_Email             $email      The email.
	 * @param bool                 $plain_text Whether it is the plain text template.
	 * @return array<string, mixed>
	 */
	public static function template_args( array $args, WC_Email $email, bool $plain_text ): array {
		/**
		 * Filters the variables one of the plugin's emails passes to its
		 * template: `email_heading`, `additional_content`, `email`,
		 * `plain_text`, the order and line where the email is about one, and
		 * the email's own, such as its proof URLs. Settings under
		 * WooCommerce > Settings > Emails change the heading, subject and
		 * additional content; template overrides change the markup.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $args       The template's variables.
		 * @param WC_Email             $email      The email; its `id` tells which, e.g. `pixonaut_proof_ready`.
		 * @param bool                 $plain_text Whether it is the plain text template.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_emails_template_args', function ( $args, $email ) {
		 *     if ( 'pixonaut_proof_ready' === $email->id ) {
		 *         $args['additional_content'] .= "\n" . __( 'Questions? Reply to this email.', 'my-theme' );
		 *     }
		 *     return $args;
		 * }, 10, 2 );
		 */
		return (array) apply_filters( 'pixonaut_wc_emails_template_args', $args, $email, $plain_text );
	}
}
