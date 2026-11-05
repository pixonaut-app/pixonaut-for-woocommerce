<?php
/**
 * The "edit your design" email.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Templates;
use WC_Order_Item_Product;

/**
 * Sent when the customer asked for changes to a proof: the proof page takes
 * them back to the store to make them, and this link opens the design again
 * for a customer who closed the tab first.
 */
final class ChangesLinkEmail extends LineEmail {

	public const ID = 'pixonaut_changes_link';

	/**
	 * The address that opens the design again while the email is sent.
	 *
	 * @var string
	 */
	private string $edit_url = '';

	/**
	 * @param Templates $templates Renders the email's templates.
	 * @param Labels    $labels    The customer-facing texts.
	 */
	public function __construct( Templates $templates, Labels $labels ) {
		$this->id = self::ID;
		/* translators: Name of the email to the customer with a link to change their design, shown in WooCommerce > Settings > Emails. */
		$this->title          = _x( 'Edit your design', 'name of the edit your design email in WooCommerce email settings', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when they asked for changes to the proof of their design, with a link that opens the design again for the changes.', 'pixonaut-for-woocommerce' );
		$this->template_html  = Emails::CHANGES_LINK_HTML;
		$this->template_plain = Emails::CHANGES_LINK_PLAIN;
		parent::__construct( $templates, $labels );
	}

	/**
	 * @param WC_Order_Item_Product $item     The line whose proof the customer asked to change.
	 * @param string                $edit_url The address that opens the design again.
	 */
	public function trigger( WC_Order_Item_Product $item, string $edit_url ): void {
		$this->edit_url = $edit_url;
		$this->send_for( $item );
		$this->edit_url = '';
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		/* translators: Default subject of the email to the customer. {order_number}, {site_title} and similar in braces are filled in by WooCommerce; keep them as written. */
		return __( 'Edit your design for order {order_number}', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		/* translators: Heading at the top of the email to the customer with a link to change their design. */
		return _x( 'Edit your design', 'heading of the email with a link to edit the design', 'pixonaut-for-woocommerce' );
	}

	/**
	 * @return array{edit_url: string, intro: string, link_text: string}
	 */
	protected function template_args(): array {
		$context = array( 'item_id' => null === $this->item ? 0 : $this->item->get_id() );
		return array(
			'edit_url'  => $this->edit_url,
			'intro'     => $this->labels->get( Labels::CHANGES_LINK_INTRO, $context ),
			'link_text' => $this->labels->get( Labels::EDIT_YOUR_DESIGN, $context ),
		);
	}
}
