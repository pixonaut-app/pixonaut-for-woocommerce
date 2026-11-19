<?php
/**
 * Which price a global attribute's choices show, on the attribute screens.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Storefront\ChoicePrices;
use Pixonaut\WooCommerce\Templates;

/**
 * A select on a global attribute's add and edit screens: its swatches show
 * no price, what each value adds to the base product, each value's lowest
 * price, or each value's price with the other choices (`ChoicePrices`).
 */
final class ChoicePriceField {

	/**
	 * The select's name and id.
	 */
	public const FIELD = 'pixonaut_wc_choice_price';

	public const NONCE_ACTION = 'pixonaut_wc_save_choice_price';

	public const NONCE_FIELD = 'pixonaut_wc_choice_price_nonce';

	/**
	 * @param Templates $templates Renders the field.
	 */
	public function __construct( private readonly Templates $templates ) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_add_attribute_fields', array( $this, 'render_add' ) );
		add_action( 'woocommerce_after_edit_attribute_fields', array( $this, 'render_edit' ) );
		add_action( 'woocommerce_attribute_added', array( $this, 'save_attribute' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'save_attribute' ) );
	}

	public function render_add(): void {
		$this->render( false, ChoicePrices::NONE );
	}

	public function render_edit(): void {
		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which attribute the screen edits.
		$this->render( true, $attribute_id > 0 ? ChoicePrices::choice( $attribute_id ) : ChoicePrices::NONE );
	}

	/**
	 * Saves the choice when the attribute screen posted it.
	 *
	 * @param int $attribute_id The attribute added or updated.
	 */
	public function save_attribute( int $attribute_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is what is checked.
		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		$choice = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : ChoicePrices::NONE;
		ChoicePrices::set_choice( $attribute_id, $choice );
	}

	/**
	 * @param bool   $editing Whether the edit screen shows it; else the add form.
	 * @param string $choice  The attribute's choice.
	 */
	private function render( bool $editing, string $choice ): void {
		$this->templates->render(
			'admin/choice-price-field.php',
			array(
				'editing'     => $editing,
				'field'       => self::FIELD,
				'choice'      => $choice,
				'choices'     => self::choices(),
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}

	/**
	 * @return array<string, string> Each choice's words, None first.
	 */
	private static function choices(): array {
		return array(
			/* translators: Option of "Price beside each choice" on the screen that adds or edits a product attribute: its options show no price. */
			ChoicePrices::NONE   => _x( 'None', 'price beside each choice', 'pixonaut-for-woocommerce' ),
			/* translators: Option of "Price beside each choice" on the screen that adds or edits a product attribute: each option, such as a frame, shows what it adds to the product's price, like "+180 kr". */
			ChoicePrices::ADD_ON => _x( 'What it adds, as an add-on ("+180 kr")', 'price beside each choice', 'pixonaut-for-woocommerce' ),
			/* translators: Option of "Price beside each choice" on the screen that adds or edits a product attribute: each option, such as a size, shows the lowest price the product has with it, like "from 469 kr". */
			ChoicePrices::FROM   => _x( 'Its lowest price ("from 469 kr")', 'price beside each choice', 'pixonaut-for-woocommerce' ),
			/* translators: Option of "Price beside each choice" on the screen that adds or edits a product attribute: each option, such as a size, shows the price the product has with it and the customer's other choices, like "599 kr". */
			ChoicePrices::PRICE  => _x( 'Its price with the other choices ("599 kr")', 'price beside each choice', 'pixonaut-for-woocommerce' ),
		);
	}
}
