<?php
/**
 * The blank option a global attribute stands for, on its edit screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\BlankOptionChoices;
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Templates;

/**
 * A select of the blank options the attribute's products use, and beside
 * each of its values a select of the option's values, on a global
 * attribute's edit screen. The values listed are the option's saved: a new
 * option lists its values once saved.
 */
final class BlankOptionField {

	/**
	 * The option select's name and id.
	 */
	public const OPTION_FIELD = 'pixonaut_wc_attribute_option';

	/**
	 * The value selects' name, by term id.
	 */
	public const VALUES_FIELD = 'pixonaut_wc_attribute_option_values';

	public const NONCE_ACTION = 'pixonaut_wc_save_blank_option';

	public const NONCE_FIELD = 'pixonaut_wc_blank_option_nonce';

	/**
	 * @param Templates          $templates Renders the field.
	 * @param BlankOptionChoices $choices   The options the attribute can stand for.
	 */
	public function __construct(
		private readonly Templates $templates,
		private readonly BlankOptionChoices $choices,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_edit_attribute_fields', array( $this, 'render_edit' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'save_attribute' ) );
	}

	public function render_edit(): void {
		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which attribute the screen edits.
		$taxonomy     = $attribute_id > 0 ? wc_attribute_taxonomy_name_by_id( $attribute_id ) : '';
		if ( '' === $taxonomy ) {
			return;
		}
		$link    = BlankOptionLinks::of( $attribute_id );
		$choices = $this->choices->for_taxonomy( $taxonomy );
		if ( null !== $link && ! isset( $choices[ $link['option'] ] ) ) {
			// A linked option no blank lists now stays selected.
			$choices[ $link['option'] ] = array();
		}
		$offered = null === $link ? array() : $choices[ $link['option'] ];
		$values  = array();
		foreach ( BlankOptionLinks::terms( $taxonomy ) as $term ) {
			$linked   = null === $link ? null : $link['values'][ $term->term_id ] ?? null;
			$same     = null === $link ? null : BlankOptionLinks::same_name( $term->name, $offered );
			$listed   = null !== $linked && ! in_array( $linked, $offered, true ) ? array_merge( $offered, array( $linked ) ) : $offered;
			$values[] = array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'value'   => $linked ?? '',
				'values'  => $listed,
				'same'    => $same,
			);
		}
		$this->templates->render(
			'admin/blank-option-field.php',
			array(
				'option_field' => self::OPTION_FIELD,
				'values_field' => self::VALUES_FIELD,
				'option'       => $link['option'] ?? '',
				'options'      => array_keys( $choices ),
				'values'       => $values,
				'nonce_field'  => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}

	/**
	 * Saves the link when the attribute screen posted it. Values posted for
	 * another option than the one saved are not kept: they were the old
	 * option's.
	 *
	 * @param int $attribute_id The attribute updated.
	 */
	public function save_attribute( int $attribute_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is what is checked.
		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		$option = isset( $_POST[ self::OPTION_FIELD ] ) && is_string( $_POST[ self::OPTION_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_FIELD ] ) ) : '';
		$posted = isset( $_POST[ self::VALUES_FIELD ] ) && is_array( $_POST[ self::VALUES_FIELD ] ) ? wp_unslash( $_POST[ self::VALUES_FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised below.
		$values = array();
		if ( ( BlankOptionLinks::of( $attribute_id )['option'] ?? null ) === $option ) {
			foreach ( $posted as $term_id => $value ) {
				if ( is_string( $value ) ) {
					$values[ (int) $term_id ] = sanitize_text_field( $value );
				}
			}
		}
		BlankOptionLinks::set( $attribute_id, $option, $values );
	}
}
