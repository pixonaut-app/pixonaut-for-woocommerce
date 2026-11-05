<?php
/**
 * An email to the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

/**
 * An email the store receives, at the addresses its settings name after the
 * switch that enables it; the site's admin address when they name none.
 */
abstract class StoreEmail extends Email {

	public function __construct() {
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * WooCommerce's settings for an email, with the recipients the store
	 * sends this one to after the switch that enables it.
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$admin             = (string) get_option( 'admin_email' );
		$recipient         = array(
			'recipient' => array(
				/* translators: Field label in the email settings for who receives this email, in WooCommerce > Settings > Emails. */
				'title'       => __( 'Recipient(s)', 'pixonaut-for-woocommerce' ),
				'type'        => 'text',
				/* translators: %s: the site's admin email address. */
				'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'pixonaut-for-woocommerce' ), '<code>' . esc_html( $admin ) . '</code>' ),
				'placeholder' => $admin,
				'default'     => '',
				'desc_tip'    => true,
			),
		);
		$after             = array_search( 'enabled', array_keys( $this->form_fields ), true );
		$offset            = false === $after ? 0 : $after + 1;
		$this->form_fields = array_slice( $this->form_fields, 0, $offset, true ) + $recipient + array_slice( $this->form_fields, $offset, null, true );
	}
}
