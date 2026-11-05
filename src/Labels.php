<?php
/**
 * Customer-facing text.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Pixonaut\WooCommerce\Fulfilment\LineState;

/**
 * Every string a customer sees comes from here, by key, so a store can change
 * any of them with one filter. The same strings reach the storefront script,
 * where `pixonaut.storefront.label` filters them again.
 */
final class Labels {

	public const PERSONALISATION_UNAVAILABLE = 'personalisation_unavailable';

	/**
	 * The Personalise button pressed before a variation was chosen, with `%s`
	 * for the options still to choose.
	 */
	public const CHOOSE_OPTIONS = 'choose_options';

	public const DESIGN_REQUIRED = 'design_required';

	public const DESIGN_NOT_SAVED = 'design_not_saved';

	public const DESIGN_MISMATCH = 'design_mismatch';

	public const DESIGN_OUTDATED = 'design_outdated';

	public const DESIGN_EXPIRED = 'design_expired';

	public const DESIGN_ALREADY_USED = 'design_already_used';

	public const DESIGN_FAILED = 'design_failed';

	/**
	 * A cart line whose design is gone at checkout, with `%s` for the
	 * product's name.
	 */
	public const DESIGN_UNAVAILABLE = 'design_unavailable';

	/**
	 * A design refused for fields the customer must change, with `%s` for
	 * their labels.
	 */
	public const DESIGN_FIELDS_TO_CHANGE = 'design_fields_to_change';

	/**
	 * The approval box's label, with `%1$s` and `%2$s` around the word that
	 * opens the proof (`link_parts()`).
	 */
	public const APPROVE_PREVIEW = 'approve_preview';

	public const OPEN_PROOF = 'open_proof';

	public const APPROVAL_REQUIRED = 'approval_required';

	/**
	 * Under the live preview.
	 */
	public const PREVIEW_NOTE = 'preview_note';

	/**
	 * Read out by the spinner over the preview while the designer loads.
	 */
	public const DESIGNER_LOADING = 'designer_loading';

	public const PROOF_ALT = 'proof_alt';

	/**
	 * In the proof's dialog on the product page while the proof is made.
	 */
	public const PROOF_LOADING = 'proof_loading';

	/**
	 * The proof dialog's close button.
	 */
	public const PROOF_CLOSE = 'proof_close';

	/**
	 * Names a line's personalisation where it was removed.
	 */
	public const PERSONALISATION = 'personalisation';

	/**
	 * A side whose design's personal data was erased in Pixonaut.
	 */
	public const PERSONALISATION_REMOVED = 'personalisation_removed';

	/**
	 * Name a design's field in a line's details when its author gave it no
	 * label; `field_image` also stands for a picture's value.
	 */
	public const FIELD_TEXT = 'field_text';

	public const FIELD_CHOICE = 'field_choice';

	public const FIELD_NUMBER = 'field_number';

	public const FIELD_COLOUR = 'field_colour';

	public const FIELD_IMAGE = 'field_image';

	public const LINE_STATE = 'line_state';

	public const TRACKING = 'tracking';

	public const PART_SHIPPED_INTRO = 'part_shipped_intro';

	public const TRACKING_CHANGED_INTRO = 'tracking_changed_intro';

	public const UPDATE_DESIGN = 'update_design';

	/**
	 * The design was corrected elsewhere (another tab) since the page loaded.
	 */
	public const DESIGN_CHANGED_ELSEWHERE = 'design_changed_elsewhere';

	/**
	 * The design takes no more changes.
	 */
	public const DESIGN_NOT_EDITABLE = 'design_not_editable';

	/**
	 * The cart line the edit link named is gone.
	 */
	public const EDIT_LINE_GONE = 'edit_line_gone';

	/**
	 * A link to change a proof of an older revision than the order line holds.
	 */
	public const PROOF_CHANGED = 'proof_changed';

	/**
	 * A link to change a design the customer approved since.
	 */
	public const EDIT_CLOSED = 'edit_closed';

	/**
	 * The button that saves and approves the changes a customer asked for.
	 */
	public const APPROVE_CHANGES = 'approve_changes';

	public const CHANGES_LINK_INTRO = 'changes_link_intro';

	public const EDIT_YOUR_DESIGN = 'edit_your_design';

	public const CORRECTION_VISIT_UNKNOWN = 'correction_visit_unknown';

	public const DESIGN_UPDATED = 'design_updated';

	/**
	 * The accessible name of a product's side tabs.
	 */
	public const SIDES             = 'sides';
	public const PROOF_READY_INTRO = 'proof_ready_intro';

	public const REVIEW_PROOF = 'review_proof';

	public const DESIGN_APPROVED_INTRO = 'design_approved_intro';

	public const PROOF_REMINDER_INTRO = 'proof_reminder_intro';

	// What the last reminder says happens at the deadline, with `%s` for its date.
	public const PROOF_DEADLINE_APPROVE = 'proof_deadline_approve';
	public const PROOF_DEADLINE_CANCEL  = 'proof_deadline_cancel';
	public const PROOF_DEADLINE_REFUND  = 'proof_deadline_refund';
	public const PROOF_DEADLINE_REVIEW  = 'proof_deadline_review';

	public const PROOF_EXPIRED_INTRO = 'proof_expired_intro';

	public const PROOF_EXPIRED_REFUNDED = 'proof_expired_refunded';

	public const PERSONALISE = 'personalise';

	public const DESIGNER_VISIT_UNKNOWN = 'designer_visit_unknown';

	public const DESIGN_READY = 'design_ready';

	public const ADD_DESIGN_AGAIN = 'add_design_again';

	public const PERSONALISED = 'personalised';

	public const CHANGE_DESIGN = 'change_design';

	public const CHANGE_DESIGN_SIDE = 'change_design_side';

	public const IN_CART = 'in_cart';

	public const CHANGE_LINE_DESIGN = 'change_line_design';

	public const VIEW_CART = 'view_cart';

	public const MAKE_ANOTHER = 'make_another';

	/**
	 * Prefix of the keys naming a line's state, followed by the state.
	 */
	public const LINE_STATE_PREFIX = 'line_state_';

	/**
	 * Marks the start of the words a label links.
	 */
	private const LINK_OPEN = '%1$s';

	/**
	 * Marks their end.
	 */
	private const LINK_CLOSE = '%2$s';

	/**
	 * The text for a key, after the filter.
	 *
	 * @param string               $key     A label key; an unknown key reads as itself.
	 * @param array<string, mixed> $context What the text is shown for, e.g. `product_id`.
	 */
	public function get( string $key, array $context = array() ): string {
		$defaults = $this->defaults();
		$text     = $defaults[ $key ] ?? $key;

		/**
		 * Filters a customer-facing text.
		 *
		 * The storefront script runs the same text through the JavaScript
		 * filter `pixonaut.storefront.label`.
		 *
		 * @since 0.1.0
		 *
		 * @param string               $text    The text.
		 * @param string               $key     The label key, see docs/developer/label-keys.md.
		 * @param array<string, mixed> $context What the text is shown for; may be empty.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_storefront_label', function ( $text, $key ) {
		 *     return 'line_state_shipped' === $key ? 'On its way' : $text;
		 * }, 10, 2 );
		 */
		return (string) apply_filters( 'pixonaut_wc_storefront_label', $text, $key, $context );
	}

	/**
	 * A label's text split around the words it links, which it marks with
	 * `%1$s` before and `%2$s` after; null when it marks none, as a store's
	 * own text may not.
	 *
	 * @param string $text A label's text.
	 * @return array{0: string, 1: string, 2: string}|null The text before the link, the link's, and the text after it.
	 */
	public static function link_parts( string $text ): ?array {
		$open  = strpos( $text, self::LINK_OPEN );
		$close = false === $open ? false : strpos( $text, self::LINK_CLOSE, $open );
		if ( false === $open || false === $close ) {
			return null;
		}
		$start = $open + strlen( self::LINK_OPEN );
		return array(
			substr( $text, 0, $open ),
			substr( $text, $start, $close - $start ),
			substr( $text, $close + strlen( self::LINK_CLOSE ) ),
		);
	}

	/**
	 * A label's text without the marks around the words it links.
	 *
	 * @param string $text A label's text.
	 */
	public static function without_link( string $text ): string {
		return str_replace( array( self::LINK_OPEN, self::LINK_CLOSE ), '', $text );
	}

	/**
	 * Every label, filtered, for the storefront script.
	 *
	 * @param array<string, mixed> $context Passed to the filter for each label.
	 * @return array<string, string>
	 */
	public function all( array $context = array() ): array {
		$labels = array();
		foreach ( array_keys( $this->defaults() ) as $key ) {
			$labels[ $key ] = $this->get( $key, $context );
		}
		return $labels;
	}

	/**
	 * The key naming a line state.
	 *
	 * @param string $state One of LineState::STATES.
	 */
	public static function line_state_key( string $state ): string {
		return self::LINE_STATE_PREFIX . $state;
	}

	/**
	 * Every label's default text and where a customer sees it, which the label
	 * keys reference lists. Built on each call: translations load at `init`,
	 * after the plugin boots.
	 *
	 * @return array<string, array{text: string, shown: string}>
	 */
	public function definitions(): array {
		$line_state    = 'Under a personalised item on the admin order screen: its state';
		$add_refused   = 'Notice when a personalised product is not added to the cart';
		$edit_refused  = 'On a product page opened to edit a cart line\'s design, or in the cart after editing it in Pixonaut, when the update is refused';
		$last_reminder = 'In the last proof reminder email: what happens at the deadline. Keep %s, the date';
		$changes_page  = 'On a product page opened from a proof the customer asked to change, instead of the designer, and when saving the change is refused; on the order received page for a product on the Personalise button layout';
		$labels        = array(
			self::PERSONALISATION_UNAVAILABLE => array(
				'text'  => __( 'Personalisation is unavailable right now. Please try again later.', 'pixonaut-for-woocommerce' ),
				'shown' => 'On a personalised product when the designer cannot load, or when it cannot be added to the cart for a reason the customer cannot fix',
			),
			self::DESIGN_REQUIRED             => array(
				'text'  => __( 'Please personalise this product before adding it to your cart.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::DESIGN_NOT_SAVED            => array(
				'text'  => __( 'We could not save your design. Please try again.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::DESIGN_MISMATCH             => array(
				'text'  => __( 'Your design does not match this product. Please personalise it again.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::DESIGN_OUTDATED             => array(
				'text'  => __( 'This product was just updated, please reload.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::DESIGN_EXPIRED              => array(
				'text'  => __( 'Your design preview expired. Please approve it again.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::DESIGN_ALREADY_USED         => array(
				'text'  => __( 'This design is already in a cart or an order. Please personalise the product again.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::DESIGN_FAILED               => array(
				'text'  => __( 'Your design could not be prepared. Please check it and try again.', 'pixonaut-for-woocommerce' ),
				'shown' => 'On a personalised product when adding it to the cart could not prepare the design',
			),
			self::DESIGN_UNAVAILABLE          => array(
				/* translators: %s: the name of the personalised product in the shopper's cart. */
				'text'  => __( 'The design for "%s" is no longer available. Please remove it from your cart, or personalise it again.', 'pixonaut-for-woocommerce' ),
				'shown' => 'At checkout, when a personalised item\'s design was deleted or can no longer be ordered; checkout stops until the item is removed. Keep %s, the product\'s name',
			),
			self::CHOOSE_OPTIONS              => array(
				/* translators: %s: the names of the product options the shopper has not chosen yet, as a list such as "Colour and Size". Notice on the product page after pressing the Personalise button. */
				'text'  => __( 'Please choose a value for %s before personalising this product.', 'pixonaut-for-woocommerce' ),
				'shown' => 'On a variable product on the Personalise button layout, when the button is pressed before a variation is chosen. Keep %s, the options to choose',
			),
			self::DESIGN_FIELDS_TO_CHANGE     => array(
				/* translators: %s: the labels of the design's fields the shopper must change, as a list such as "Photo, Name and Class". */
				'text'  => __( 'Please change these fields: %s', 'pixonaut-for-woocommerce' ),
				'shown' => 'On a personalised product when adding it to the cart refused the design for fields the customer must change, such as a default left as it was. Keep %s, the fields\' labels',
			),
			self::APPROVE_PREVIEW             => array(
				/* translators: Label of the checkbox next to the preview on the product page, shown to the shopper; ticking it approves the design. %1$s and %2$s go around the word for the preview, which becomes a link that opens the design's proof. */
				'text'  => __( 'I approve the %1$spreview%2$s', 'pixonaut-for-woocommerce' ),
				'shown' => 'The box a customer ticks to approve their design, on a product whose approval mode is the checkbox. Keep %1$s and %2$s around the words that open the proof; without them an Open proof button sits beside the box',
			),
			self::OPEN_PROOF                  => array(
				/* translators: Button in the cart and on the order received page, shown to the shopper; opens the proof of the design. */
				'text'  => __( 'Open proof', 'pixonaut-for-woocommerce' ),
				'shown' => 'Beside the approval box: opens the design\'s proof in a window over the page',
			),
			self::APPROVAL_REQUIRED           => array(
				'text'  => __( 'Please tick "I approve the preview" before adding this product to your cart.', 'pixonaut-for-woocommerce' ),
				'shown' => $add_refused,
			),
			self::PREVIEW_NOTE                => array(
				/* translators: Short note under the live preview on the product page, shown to the shopper. */
				'text'  => __( 'The preview updates as you type', 'pixonaut-for-woocommerce' ),
				'shown' => 'Under the live preview of a personalised product',
			),
			self::DESIGNER_LOADING            => array(
				'text'  => __( 'Loading the designer', 'pixonaut-for-woocommerce' ),
				'shown' => 'Read out by the spinner over the preview while the designer loads',
			),
			self::PROOF_ALT                   => array(
				/* translators: Alternative text of the design preview image in the cart and order, shown to the shopper. */
				'text'  => __( 'Your design', 'pixonaut-for-woocommerce' ),
				'shown' => 'Alternative text of the proof shown as a cart line\'s image, and the name of the window the proof opens in on the product page',
			),
			self::PROOF_LOADING               => array(
				/* translators: Shown to the shopper in the window over the product page while their design's proof is made. */
				'text'  => __( 'Preparing your proof', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the window the proof opens in on the product page, while the proof is made',
			),
			self::PROOF_CLOSE                 => array(
				/* translators: Button that closes the window showing the design's proof on the product page; here Close is a verb. */
				'text'  => _x( 'Close', 'proof window', 'pixonaut-for-woocommerce' ),
				'shown' => 'The button that closes the window the proof opens in on the product page',
			),
			self::PERSONALISATION             => array(
				/* translators: Heading above a cart or order item's design details, shown to the shopper. */
				'text'  => __( 'Personalisation', 'pixonaut-for-woocommerce' ),
				'shown' => 'Under a personalised item in My Account, on the order received page, in order emails and in proof emails: names the item\'s personalisation once it was removed',
			),
			self::PERSONALISATION_REMOVED     => array(
				/* translators: Shown to the shopper in place of a design's field values once the shop has removed them after its retention period. */
				'text'  => __( 'Removed after the shop\'s retention period', 'pixonaut-for-woocommerce' ),
				'shown' => 'In place of a side\'s proof once its design\'s personal data was erased: next to the side\'s name in the cart, and under the item in My Account, on the order received page and in order and proof emails',
			),
			self::FIELD_TEXT                  => array(
				/* translators: Name of a design field type, a free-text field, shown to the shopper in the cart line details. */
				'text'  => __( 'Text', 'pixonaut-for-woocommerce' ),
				'shown' => 'In a personalised item\'s details in the cart, the checkout, My Account, on the order received page and in order emails: names a text field of the design whose template gives it no label',
			),
			self::FIELD_CHOICE                => array(
				/* translators: Name of a design field type, a pick from a list of options, shown to the shopper in the cart line details. */
				'text'  => __( 'Choice', 'pixonaut-for-woocommerce' ),
				'shown' => 'In a personalised item\'s details, as `field_text`: names a field the customer chose from a list',
			),
			self::FIELD_NUMBER                => array(
				/* translators: Name of a design field type, a numeric value, shown to the shopper in the cart line details. */
				'text'  => __( 'Number', 'pixonaut-for-woocommerce' ),
				'shown' => 'In a personalised item\'s details, as `field_text`: names a number field',
			),
			self::FIELD_COLOUR                => array(
				/* translators: Name of a design field type, a picked colour, shown to the shopper in the cart line details. */
				'text'  => __( 'Colour', 'pixonaut-for-woocommerce' ),
				'shown' => 'In a personalised item\'s details, as `field_text`: names a colour field',
			),
			self::FIELD_IMAGE                 => array(
				/* translators: Name of a design field type, an uploaded image, shown to the shopper in the cart line details. */
				'text'  => __( 'Image', 'pixonaut-for-woocommerce' ),
				'shown' => 'In a personalised item\'s details, as `field_text`: names a picture field, and stands for the picture the customer chose',
			),
			self::LINE_STATE                  => array(
				/* translators: Heading of the status of an order line on the admin order screen. */
				'text'  => _x( 'Status', 'heading: status of an order line', 'pixonaut-for-woocommerce' ),
				'shown' => 'Under a personalised item on the admin order screen: names the item\'s state',
			),
			self::TRACKING                    => array(
				/* translators: Heading of the shipment tracking links in the order details and shipping emails, shown to the shopper. */
				'text'  => __( 'Tracking', 'pixonaut-for-woocommerce' ),
				'shown' => 'Under a shipped personalised item in My Account, on the order received page and in order emails: names its tracking',
			),
			self::PART_SHIPPED_INTRO          => array(
				'text'  => __( 'Part of your order is on its way. The rest follows as soon as it ships.', 'pixonaut-for-woocommerce' ),
				'shown' => 'The email a customer gets when a personalised item of an order with other items ships',
			),
			self::TRACKING_CHANGED_INTRO      => array(
				'text'  => __( 'There is new tracking for your order. Follow your parcel with the details below.', 'pixonaut-for-woocommerce' ),
				'shown' => 'The email a customer gets when tracking is added to, or corrected for, a personalised item that has shipped',
			),
			self::SIDES                       => array(
				/* translators: Accessible name of the tabs that switch between a product's sides, such as front and back, on the product page, read by screen readers. */
				'text'  => __( 'Sides', 'pixonaut-for-woocommerce' ),
				'shown' => 'Read by screen readers as the name of the side tabs, on a product printed on more than one side',
			),
			self::PROOF_READY_INTRO           => array(
				'text'  => __( 'Your order is paid. Before we print it, please check the proof of your design and approve it. Nothing is printed until you do.', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "proof ready" email, above the links to the proofs',
			),
			self::REVIEW_PROOF                => array(
				/* translators: Link in the proof email to the shopper, and under the order item on the order received page and in My Account; opens the proof page where the design is approved. */
				'text'  => __( 'Review and approve your design', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "proof ready" email, and under an item waiting for the customer\'s approval on the order received page and in My Account: the link to a proof page',
			),
			self::DESIGN_APPROVED_INTRO       => array(
				'text'  => __( 'Thank you for approving your design. It is now on its way to print.', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "design approved" email',
			),
			self::PROOF_REMINDER_INTRO        => array(
				'text'  => __( 'We are still waiting for your approval. Please check the proof of your design and approve it, or tell us what to change. Nothing is printed until you do.', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the proof reminder email, above the links to the proofs',
			),
			self::PROOF_DEADLINE_APPROVE      => array(
				/* translators: %s: date of the approval deadline. */
				'text'  => __( 'If we have not heard from you by %s, we will print your design as the proof shows it.', 'pixonaut-for-woocommerce' ),
				'shown' => $last_reminder,
			),
			self::PROOF_DEADLINE_CANCEL       => array(
				/* translators: %s: date of the approval deadline. */
				'text'  => __( 'If we have not heard from you by %s, we will cancel this item.', 'pixonaut-for-woocommerce' ),
				'shown' => $last_reminder,
			),
			self::PROOF_DEADLINE_REFUND       => array(
				/* translators: %s: date of the approval deadline. */
				'text'  => __( 'If we have not heard from you by %s, we will cancel this item and refund it.', 'pixonaut-for-woocommerce' ),
				'shown' => $last_reminder,
			),
			self::PROOF_DEADLINE_REVIEW       => array(
				/* translators: %s: date of the approval deadline. */
				'text'  => __( 'If we have not heard from you by %s, we will not print your design and will be in touch about this item.', 'pixonaut-for-woocommerce' ),
				'shown' => $last_reminder,
			),
			self::PROOF_EXPIRED_INTRO         => array(
				'text'  => __( 'You did not approve your design by the deadline, so we have cancelled this item. Nothing was printed.', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "cancelled after deadline" email',
			),
			self::PROOF_EXPIRED_REFUNDED      => array(
				'text'  => __( 'Its price is being refunded to you.', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "cancelled after deadline" email, when the item was refunded',
			),
			self::UPDATE_DESIGN               => array(
				/* translators: Button on the product page opened to edit a cart item, shown to the shopper; saves the changed design. */
				'text'  => __( 'Update design', 'pixonaut-for-woocommerce' ),
				'shown' => 'The add-to-cart button\'s text on a product page opened to edit a cart line\'s design',
			),
			self::DESIGN_CHANGED_ELSEWHERE    => array(
				'text'  => __( 'This design was changed elsewhere. Please reload the page.', 'pixonaut-for-woocommerce' ),
				'shown' => $edit_refused,
			),
			self::DESIGN_NOT_EDITABLE         => array(
				'text'  => __( 'This design can no longer be changed.', 'pixonaut-for-woocommerce' ),
				'shown' => $edit_refused,
			),
			self::EDIT_LINE_GONE              => array(
				'text'  => __( 'This item is no longer in your cart.', 'pixonaut-for-woocommerce' ),
				'shown' => $edit_refused,
			),
			self::PERSONALISE                 => array(
				/* translators: Button on the product page, shown to the shopper; opens the Pixonaut designer to personalise the product. */
				'text'  => _x( 'Personalise', 'verb: button that opens the designer', 'pixonaut-for-woocommerce' ),
				'shown' => 'The button that opens the Pixonaut designer, on a product whose layout is the personalise button',
			),
			self::DESIGNER_VISIT_UNKNOWN      => array(
				'text'  => __( 'We could not match your design to this visit. Please personalise the product again.', 'pixonaut-for-woocommerce' ),
				'shown' => 'Notice when a customer comes back from the Pixonaut designer with a link that expired, was used already, or was opened in another browser',
			),
			self::DESIGN_READY                => array(
				'text'  => __( 'Your design is ready. Use it with the button below.', 'pixonaut-for-woocommerce' ),
				'shown' => 'Notice when a customer comes back from the Pixonaut designer in a browser or session that did not open it, so the design waits for them to use it',
			),
			self::ADD_DESIGN_AGAIN            => array(
				/* translators: Button on the product page, shown to the shopper after returning from the Pixonaut designer when their design could not be kept yet, or when they came back in a browser that did not open the designer; keeps the design. */
				'text'  => __( 'Use my design', 'pixonaut-for-woocommerce' ),
				'shown' => 'On the product page when the design a customer approved in the Pixonaut designer could not be kept yet, or came back in a browser or session that did not open the designer',
			),
			self::PERSONALISED                => array(
				/* translators: Soft note above the add-to-cart row on a product page showing the design the shopper made in the Pixonaut designer. */
				'text'  => __( 'This is your design.', 'pixonaut-for-woocommerce' ),
				'shown' => 'Note above the add-to-cart row when the product page shows a customer\'s design from the Pixonaut designer',
			),
			self::CHANGE_DESIGN               => array(
				/* translators: Button beside the note that this is the shopper's design; reopens it in the Pixonaut designer. */
				'text'  => _x( 'Change', 'personalised product button', 'pixonaut-for-woocommerce' ),
				'shown' => 'Button beside that note, on a product printed on one side',
			),
			self::CHANGE_DESIGN_SIDE          => array(
				/* translators: %s: the name of a printed side, such as Front. Button beside the note that this is the shopper's design; reopens that side's design in the Pixonaut designer. */
				'text'  => __( 'Change %s', 'pixonaut-for-woocommerce' ),
				'shown' => 'Button per side beside that note, on a product printed on more than one side',
			),
			self::IN_CART                     => array(
				/* translators: Start of the line on a product page saying the design it shows is already in the shopper's cart, followed by its size and quantity. */
				'text'  => __( 'In your cart', 'pixonaut-for-woocommerce' ),
				'shown' => 'On the product page of an item in the cart, before its options and quantity, on the Personalise button layout',
			),
			self::CHANGE_LINE_DESIGN          => array(
				/* translators: Button on the product page of an item already in the shopper's cart; reopens its design in the Pixonaut designer. */
				'text'  => _x( 'Change design', 'item in the cart button', 'pixonaut-for-woocommerce' ),
				'shown' => 'Button on the product page of an item in the cart, on a product printed on one side, on the Personalise button layout',
			),
			self::VIEW_CART                   => array(
				/* translators: The main button on the product page of an item already in the shopper's cart; goes to the cart. */
				'text'  => _x( 'View cart', 'item in the cart button', 'pixonaut-for-woocommerce' ),
				'shown' => 'Main button on the product page of an item in the cart, on the Personalise button layout',
			),
			self::MAKE_ANOTHER                => array(
				/* translators: Link on the product page of an item already in the shopper's cart; opens the plain product page, to start another design from scratch. */
				'text'  => __( 'Make another with a new design', 'pixonaut-for-woocommerce' ),
				'shown' => 'Link on the product page of an item in the cart, under the button to the cart, on the Personalise button layout',
			),
			self::PROOF_CHANGED               => array(
				'text'  => __( 'This proof has changed since this link was sent. Please use the link in our latest email.', 'pixonaut-for-woocommerce' ),
				'shown' => $changes_page,
			),
			self::EDIT_CLOSED                 => array(
				'text'  => __( 'This design is approved and can no longer be changed.', 'pixonaut-for-woocommerce' ),
				'shown' => $changes_page,
			),
			self::APPROVE_CHANGES             => array(
				/* translators: Button on the page opened from a proof email, shown to the shopper; saves the changes and approves the design. */
				'text'  => __( 'Save and approve design', 'pixonaut-for-woocommerce' ),
				'shown' => 'The add-to-cart button\'s text on a product page opened from a proof the customer asked to change: saving approves the changed design',
			),
			self::CHANGES_LINK_INTRO          => array(
				'text'  => __( 'We received your request to change your design. If you did not finish your changes, you can open the design again here. Nothing is printed until you approve it.', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "edit your design" email, sent after the customer asked for changes to their proof',
			),
			self::EDIT_YOUR_DESIGN            => array(
				/* translators: Button in the email to the shopper after a change request; reopens the design. */
				'text'  => __( 'Edit your design', 'pixonaut-for-woocommerce' ),
				'shown' => 'In the "edit your design" email: the link that opens the design for changes',
			),
			self::CORRECTION_VISIT_UNKNOWN    => array(
				'text'  => __( 'We could not match your changes to this visit. Please change your design again from its product page.', 'pixonaut-for-woocommerce' ),
				'shown' => 'Notice when a customer comes back from changing a design in Pixonaut with a link that expired, was used already, or was opened in another browser (Personalise button layout)',
			),
			self::DESIGN_UPDATED              => array(
				'text'  => __( 'Your design has been updated.', 'pixonaut-for-woocommerce' ),
				'shown' => 'Notice when a customer comes back from changing their design in Pixonaut and the change is saved (Personalise button layout)',
			),
		);
		$states        = array(
			/* translators: Status of an order line on the admin order screen: the design is saved, payment is pending. */
			LineState::COMMITTED         => __( 'Design saved', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the shopper has to approve the proof. */
			LineState::AWAITING_CUSTOMER => __( 'Awaiting your approval', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the design is approved. */
			LineState::APPROVED          => __( 'Design approved', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the print file is made. */
			LineState::PRINTED           => __( 'Print file ready', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the printshop is making it. */
			LineState::ORDERED           => __( 'In production', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the item has shipped. */
			LineState::SHIPPED           => __( 'Shipped', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the printshop could not make it. */
			LineState::FAILED            => __( 'Could not be produced', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the item is cancelled. */
			LineState::CANCELLED         => __( 'Cancelled', 'pixonaut-for-woocommerce' ),
			/* translators: Status of an order line on the admin order screen: the store is looking into the item. */
			LineState::NEEDS_ATTENTION   => __( 'Being reviewed by the store', 'pixonaut-for-woocommerce' ),
		);
		foreach ( $states as $state => $text ) {
			$labels[ self::line_state_key( $state ) ] = array(
				'text'  => $text,
				'shown' => $line_state,
			);
		}
		return $labels;
	}

	/**
	 * @return array<string, string> Key => default text.
	 */
	private function defaults(): array {
		return array_map( static fn ( array $label ): string => $label['text'], $this->definitions() );
	}
}
