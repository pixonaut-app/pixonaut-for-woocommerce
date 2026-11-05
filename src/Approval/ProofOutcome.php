<?php
/**
 * What happens to a proof the customer did not approve.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

/**
 * The choices of the proof deadline settings: what the store does when the
 * customer has not answered by the deadline, and what it does with a line
 * whose proof was denied, by the deadline or by the customer rejecting it.
 */
final class ProofOutcome {

	// When no answer came by the deadline.
	public const APPROVE = 'approve';
	public const DENY    = 'deny';

	// On deny.
	public const REFUND_AND_CANCEL = 'refund_cancel';
	public const CANCEL            = 'cancel';
	public const NEEDS_ATTENTION   = 'needs_attention';

	/**
	 * The deadline passed but the store could not decide the proof: the line
	 * needs the merchant's attention for the reason it records.
	 */
	public const UNRESOLVED = 'unresolved';

	public const DEFAULT_NO_ANSWER = self::DENY;

	public const DEFAULT_ON_DENY = self::NEEDS_ATTENTION;

	/**
	 * Default days from the proof email to the first reminder, between
	 * reminders, and the most reminders sent.
	 */
	public const DEFAULT_REMINDER_AFTER_DAYS = 2;

	public const DEFAULT_REMINDER_EVERY_DAYS = 2;

	public const DEFAULT_REMINDERS = 2;

	/**
	 * Most reminders a line gets.
	 */
	public const MAX_REMINDERS = 10;

	/**
	 * @return list<string>
	 */
	public static function no_answer_choices(): array {
		return array_keys( self::no_answer_names() );
	}

	/**
	 * @return array<string, string> Choice => translated name.
	 */
	public static function no_answer_names(): array {
		return array(
			/* translators: Option in the proof settings: what happens to a design the customer never answers. The merchant reads it. */
			self::APPROVE => __( 'Approve the design as shown', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the proof settings: what happens to a design the customer never answers. The merchant reads it. */
			self::DENY    => __( 'Deny the design', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function on_deny_choices(): array {
		return array_keys( self::on_deny_names() );
	}

	/**
	 * @return array<string, string> Choice => translated name.
	 */
	public static function on_deny_names(): array {
		return array(
			/* translators: Option in the proof settings: what happens to an order item whose design is denied. The merchant reads it. */
			self::REFUND_AND_CANCEL => __( 'Refund and cancel the item', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the proof settings: what happens to an order item whose design is denied. The merchant reads it. */
			self::CANCEL            => __( 'Cancel the item', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the proof settings: what happens to an order item whose design is denied. The merchant reads it. */
			self::NEEDS_ATTENTION   => __( 'Mark the item as needing attention', 'pixonaut-for-woocommerce' ),
		);
	}
}
