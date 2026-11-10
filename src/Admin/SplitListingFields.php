<?php
/**
 * Whether each value of an attribute is listed as its own product, on the
 * attribute screens and the product's Attributes tab.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use LogicException;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WC_Product_Attribute;

/**
 * A checkbox on a global attribute's add and edit screens turns its default
 * on; a three-way control on each global attribute's row in the product's
 * Attributes tab follows it (Auto) or turns it on or off for the product.
 * The row's choice is saved with the product and with WooCommerce's Save
 * attributes button. Custom attributes have no control (`SplitListing`).
 */
final class SplitListingFields {

	/**
	 * The attribute screens' checkbox.
	 */
	public const ATTRIBUTE_FIELD = 'pixonaut_wc_split_attribute';

	/**
	 * The product rows' choices, by the row's index in WooCommerce's `attribute_names`.
	 */
	public const PRODUCT_FIELD = 'pixonaut_wc_split_listing';

	public const NONCE_ACTION = 'pixonaut_wc_save_split_listing';

	public const NONCE_FIELD = 'pixonaut_wc_split_listing_nonce';

	/**
	 * After WooCommerce saved the product's attributes and `ProductPanel` its configuration, at 10.
	 */
	private const SAVE_PRIORITY = 20;

	/**
	 * Before WooCommerce's own Save attributes handlers, at 10, which end the request.
	 */
	private const AJAX_PRIORITY = 5;

	/**
	 * What WordPress puts before an admin-ajax action's name to make its hook.
	 */
	private const AJAX_PREFIX = 'wp_ajax_';

	/**
	 * WooCommerce's admin-ajax actions that save the Attributes tab without
	 * the product, with the nonce action each checks.
	 */
	private const AJAX_SAVES = array(
		'woocommerce_save_attributes'               => 'save-attributes',
		'woocommerce_add_attributes_and_variations' => 'add-attributes-and-variations',
	);

	/**
	 * @param Templates $templates Renders the fields.
	 */
	public function __construct( private readonly Templates $templates ) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_add_attribute_fields', array( $this, 'render_add' ) );
		add_action( 'woocommerce_after_edit_attribute_fields', array( $this, 'render_edit' ) );
		add_action( 'woocommerce_attribute_added', array( $this, 'save_attribute' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'save_attribute' ) );
		add_action( 'woocommerce_after_product_attribute_settings', array( $this, 'render_row' ), 10, 2 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ), self::SAVE_PRIORITY );
		foreach ( array_keys( self::AJAX_SAVES ) as $action ) {
			add_action( self::AJAX_PREFIX . $action, array( $this, 'save_attributes_tab' ), self::AJAX_PRIORITY );
		}
	}

	public function render_add(): void {
		$this->render_attribute_field( false, false );
	}

	public function render_edit(): void {
		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which attribute the screen edits.
		$this->render_attribute_field( true, $attribute_id > 0 && SplitListing::attribute_default( $attribute_id ) );
	}

	/**
	 * Saves the checkbox when the attribute screen posted it.
	 *
	 * @param int $attribute_id The attribute added or updated.
	 */
	public function save_attribute( int $attribute_id ): void {
		if ( ! self::posted_nonce_valid( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is what is checked.
			return;
		}
		SplitListing::set_attribute_default( $attribute_id, isset( $_POST[ self::ATTRIBUTE_FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
	}

	/**
	 * Prints the control on a global attribute's row.
	 *
	 * @param mixed $attribute The row's attribute.
	 * @param mixed $index     The row's index in the posted rows.
	 */
	public function render_row( mixed $attribute, mixed $index ): void {
		if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->is_taxonomy() || $attribute->get_id() < 1 ) {
			return;
		}
		$product = self::product_on_screen();
		$config  = null === $product ? new ProductConfig() : ProductConfig::stored_on( $product );
		$row     = (string) ( is_int( $index ) || is_string( $index ) ? $index : '' );
		$this->templates->render(
			'admin/split-listing-control.php',
			array(
				'field'       => self::PRODUCT_FIELD . '[' . $row . ']',
				'id'          => 'pixonaut-split-listing-' . $row,
				'choice'      => SplitListing::choice( $config, $attribute->get_id() ),
				'states'      => self::states( SplitListing::attribute_default( $attribute->get_id() ) ),
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}

	/**
	 * Saves the rows' choices with the product, then drops those of the
	 * attributes it no longer has: with its last global attribute removed,
	 * no row, and so no choice, is posted.
	 *
	 * @param int $product_id The product.
	 */
	public function save_product( int $product_id ): void {
		$this->save_choices( $product_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_choices() checks the rows' nonce.
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product ) {
			SplitListing::forget_removed_attributes( $product );
		}
	}

	/**
	 * Saves the rows' choices from WooCommerce's Save attributes request,
	 * which then answers with the rows drawn again.
	 */
	public function save_attributes_tab(): void {
		$action = (string) current_action();
		$nonce  = self::AJAX_SAVES[ substr( $action, strlen( self::AJAX_PREFIX ) ) ] ?? null;
		// save_choices() checks the user may edit the product.
		if ( null === $nonce || false === check_ajax_referer( $nonce, 'security', false ) ) {
			return;
		}
		if ( ! isset( $_POST['data'], $_POST['post_id'] ) || ! is_string( $_POST['data'] ) ) {
			return;
		}
		parse_str( wp_unslash( $_POST['data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_choices() keeps only known names and choices.
		$this->save_choices( absint( $_POST['post_id'] ), $data );
	}

	/**
	 * Stores the posted rows' choices on the product; the attributes no row
	 * names lose theirs. Nothing is stored when the rows were not posted, or
	 * when the choices are what is stored.
	 *
	 * @param int                 $product_id The product.
	 * @param array<mixed, mixed> $posted    The posted form.
	 */
	public function save_choices( int $product_id, array $posted ): void {
		if ( ! self::posted_nonce_valid( $posted ) || ! is_array( $posted[ self::PRODUCT_FIELD ] ?? null ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}
		$names   = is_array( $posted['attribute_names'] ?? null ) ? $posted['attribute_names'] : array();
		$choices = array();
		foreach ( $posted[ self::PRODUCT_FIELD ] as $row => $choice ) {
			$name         = $names[ $row ] ?? null;
			$attribute_id = is_string( $name ) ? wc_attribute_taxonomy_id_by_name( sanitize_text_field( wp_unslash( $name ) ) ) : 0;
			if ( $attribute_id > 0 ) {
				$choices[ $attribute_id ] = $choice;
			}
		}
		$config = ProductConfig::stored_on( $product );
		if ( SplitListing::choices_from( $choices ) === $config->split_listing ) {
			return;
		}
		try {
			$config->with_split_listing( $choices )->save_to( $product );
		} catch ( LogicException $e ) {
			// A newer plugin version's configuration is kept as it is.
			return;
		}
	}

	/**
	 * What each choice reads as beside the control.
	 *
	 * @param bool $attribute_default Whether the attribute is on by default.
	 * @return array<string, string> By choice.
	 */
	private static function states( bool $attribute_default ): array {
		return array(
			SplitListing::AUTO => $attribute_default
				/* translators: Beside the "Show each value as its own product" control on a product's attribute row, when it follows the attribute's setting, which is on. */
				? __( 'Auto: on, as the attribute is', 'pixonaut-for-woocommerce' )
				/* translators: Beside the "Show each value as its own product" control on a product's attribute row, when it follows the attribute's setting, which is off. */
				: __( 'Auto: off, as the attribute is', 'pixonaut-for-woocommerce' ),
			/* translators: Beside the "Show each value as its own product" control on a product's attribute row, when the product turned it on, whatever the attribute's setting. */
			SplitListing::ON   => __( 'On for this product', 'pixonaut-for-woocommerce' ),
			/* translators: Beside the "Show each value as its own product" control on a product's attribute row, when the product turned it off, whatever the attribute's setting. */
			SplitListing::OFF  => __( 'Off for this product', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * @param bool $editing Whether the edit screen shows it; else the add form.
	 * @param bool $checked Whether the setting is on.
	 */
	private function render_attribute_field( bool $editing, bool $checked ): void {
		$this->templates->render(
			'admin/split-attribute-field.php',
			array(
				'editing'     => $editing,
				'field'       => self::ATTRIBUTE_FIELD,
				'checked'     => $checked,
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}

	/**
	 * Whether a posted form carries a valid nonce of these fields.
	 *
	 * @param array<mixed, mixed> $posted The posted form, slashed.
	 */
	private static function posted_nonce_valid( array $posted ): bool {
		$nonce = $posted[ self::NONCE_FIELD ] ?? null;
		return is_string( $nonce ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $nonce ) ), self::NONCE_ACTION );
	}

	/**
	 * The product whose Attributes tab is drawn: the product screen's, or
	 * the one WooCommerce's Save attributes request names.
	 */
	private static function product_on_screen(): ?WC_Product {
		global $product_object;
		if ( $product_object instanceof WC_Product ) {
			return $product_object;
		}
		$product_id = wp_doing_ajax() && isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked its nonce; only reads which product is drawn.
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
		return $product instanceof WC_Product ? $product : null;
	}
}
