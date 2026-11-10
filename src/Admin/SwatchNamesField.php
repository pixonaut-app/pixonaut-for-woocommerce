<?php
/**
 * Whether a global attribute's swatches show their values' names, on the
 * attribute screens.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Storefront\SwatchNames;
use Pixonaut\WooCommerce\Templates;

/**
 * A select on a global attribute's add and edit screens: follow the store's
 * "Show value names beside swatches" setting, or show or hide the names of
 * this attribute's colour and picture swatches whatever it says.
 */
final class SwatchNamesField {

	/**
	 * The select's name and id.
	 */
	public const FIELD = 'pixonaut_wc_swatch_names';

	public const NONCE_ACTION = 'pixonaut_wc_save_swatch_names';

	public const NONCE_FIELD = 'pixonaut_wc_swatch_names_nonce';

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
		$this->render( false, SwatchNames::INHERIT );
	}

	public function render_edit(): void {
		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which attribute the screen edits.
		$this->render( true, $attribute_id > 0 ? SwatchNames::choice( $attribute_id ) : SwatchNames::INHERIT );
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
		$choice = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : SwatchNames::INHERIT;
		SwatchNames::set_choice( $attribute_id, $choice );
	}

	/**
	 * @param bool   $editing Whether the edit screen shows it; else the add form.
	 * @param string $choice  The attribute's choice.
	 */
	private function render( bool $editing, string $choice ): void {
		$this->templates->render(
			'admin/swatch-names-field.php',
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
	 * @return array<string, string> Each choice's words, Inherit first.
	 */
	private static function choices(): array {
		return array(
			/* translators: Option of "Value names beside swatches" on the screen that adds or edits a product attribute: follow the store's setting. */
			SwatchNames::INHERIT => _x( 'As the store\'s setting says', 'value names beside swatches', 'pixonaut-for-woocommerce' ),
			/* translators: Option of "Value names beside swatches" on the screen that adds or edits a product attribute. */
			SwatchNames::SHOW    => _x( 'Show', 'value names beside swatches', 'pixonaut-for-woocommerce' ),
			/* translators: Option of "Value names beside swatches" on the screen that adds or edits a product attribute. */
			SwatchNames::HIDE    => _x( 'Hide', 'value names beside swatches', 'pixonaut-for-woocommerce' ),
		);
	}
}
