<?php
/**
 * The name a global attribute goes by in the shop's addresses, on the
 * attribute screens.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Storefront\QueryNames;
use Pixonaut\WooCommerce\Templates;

/**
 * A text field on a global attribute's add and edit screens: the short name
 * the shop's addresses give the attribute (`QueryNames`), empty for its
 * slug. A name WordPress or WooCommerce reads, or another attribute's, is
 * refused when the attribute is saved, which saves the rest; the screen
 * WooCommerce shows next says why under the field.
 */
final class QueryNameField {

	/**
	 * The text field's name and id.
	 */
	public const FIELD = 'pixonaut_wc_query_name';

	public const NONCE_ACTION = 'pixonaut_wc_save_query_name';

	public const NONCE_FIELD = 'pixonaut_wc_query_name_nonce';

	/**
	 * Why the name the screen just posted was refused; empty when it was not.
	 *
	 * @var string
	 */
	private string $refusal = '';

	/**
	 * The name the screen just posted, when it was refused.
	 *
	 * @var string
	 */
	private string $refused = '';

	/**
	 * @param Templates $templates Renders the field.
	 */
	public function __construct( private readonly Templates $templates ) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_add_attribute_fields', array( $this, 'render_add' ) );
		add_action( 'woocommerce_after_edit_attribute_fields', array( $this, 'render_edit' ) );
		add_action( 'woocommerce_attribute_added', array( $this, 'save_attribute' ), 10, 2 );
		add_action( 'woocommerce_attribute_updated', array( $this, 'save_attribute' ), 10, 2 );
	}

	public function render_add(): void {
		$this->render( false, '', '' );
	}

	public function render_edit(): void {
		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which attribute the screen edits.
		$own          = $attribute_id > 0 ? QueryNames::own( $attribute_id ) : '';
		$this->render( true, '' === $this->refused ? $own : $this->refused, $attribute_id > 0 ? QueryNames::default_name( $attribute_id ) : '' );
	}

	/**
	 * Saves the name when the attribute screen posted it, or keeps why it
	 * was refused for the screen shown next. WooCommerce still gives an
	 * updated attribute's old slug, so the slug saved comes from its data.
	 *
	 * @param int                  $attribute_id The attribute added or updated.
	 * @param array<string, mixed> $data         The attribute's row as saved; its `attribute_name` is its slug.
	 */
	public function save_attribute( int $attribute_id, array $data = array() ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is what is checked.
		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		$name    = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) ) ) : '';
		$slug    = isset( $data['attribute_name'] ) && is_string( $data['attribute_name'] ) ? wc_sanitize_taxonomy_name( $data['attribute_name'] ) : null;
		$refusal = QueryNames::set( $attribute_id, $name, $slug );
		if ( null !== $refusal ) {
			$this->refusal = $refusal;
			$this->refused = $name;
		}
	}

	/**
	 * @param bool   $editing     Whether the edit screen shows it; else the add form.
	 * @param string $name        The name in the field.
	 * @param string $placeholder The attribute's slug, which it goes by without a name.
	 */
	private function render( bool $editing, string $name, string $placeholder ): void {
		$this->templates->render(
			'admin/attribute-query-name-field.php',
			array(
				'editing'     => $editing,
				'field'       => self::FIELD,
				'name'        => $name,
				'placeholder' => $placeholder,
				'max_length'  => QueryNames::MAX_LENGTH,
				'refusal'     => $this->refusal,
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}
}
