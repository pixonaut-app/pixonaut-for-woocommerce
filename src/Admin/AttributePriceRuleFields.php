<?php
/**
 * The pricing rule's parts on a global attribute value's screens.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Pricing\AttributeRules;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Templates;
use WP_Term;

/**
 * Fields for each part of the pricing rule on the screens that add and edit
 * a value of a global attribute (Products → Attributes → Configure terms):
 * what the value changes for every variation with it, in every product. An
 * empty field follows the store. The edit screen warns when a value of
 * another attribute sets the same part on a variation with both, and says
 * which one wins.
 */
final class AttributePriceRuleFields {

	/**
	 * The posted field holding the parts, by part.
	 */
	public const FIELD = 'pixonaut_term_price_rule';

	public const NONCE_ACTION = 'pixonaut_wc_save_term_price_rule';

	public const NONCE_FIELD = 'pixonaut_wc_term_price_rule_nonce';

	/**
	 * @param ProductPricing $pricing   Reads the store's rule.
	 * @param Templates      $templates Renders the fields.
	 */
	public function __construct(
		private readonly ProductPricing $pricing,
		private readonly Templates $templates,
	) {
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'hook_taxonomies' ) );
	}

	/**
	 * Adds the fields to each global attribute's term screens.
	 */
	public function hook_taxonomies(): void {
		foreach ( AttributeRules::taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit' ) );
			add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
	}

	public function render_add(): void {
		$this->render( null );
	}

	/**
	 * @param mixed $term The value being edited.
	 */
	public function render_edit( mixed $term ): void {
		if ( $term instanceof WP_Term ) {
			$this->render( $term );
		}
	}

	/**
	 * Stores the parts the screen posted.
	 *
	 * @param mixed $term_id The value added or edited.
	 */
	public function save( mixed $term_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is what is checked.
		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each part is checked as a number.
		AttributeRules::save( (int) $term_id, array_filter( $posted, 'is_string' ) );
	}

	/**
	 * @param WP_Term|null $term The value being edited; null on the add form.
	 */
	private function render( ?WP_Term $term ): void {
		$this->templates->render(
			'admin/term-price-rule.php',
			array(
				'editing'     => null !== $term,
				'field'       => self::FIELD,
				'own_parts'   => null === $term ? array() : AttributeRules::parts( $term->term_id ),
				'store'       => $this->pricing->store_parts(),
				'parts'       => PricingPanel::part_labels(),
				'clashes'     => null === $term ? array() : self::clash_texts( $term ),
				'settings'    => SettingsPage::url( SettingsPage::PRICES ),
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}

	/**
	 * What the screen says of each part another attribute's value sets too.
	 *
	 * @param WP_Term $term The value.
	 * @return list<string>
	 */
	private static function clash_texts( WP_Term $term ): array {
		$labels = PricingPanel::part_labels();
		$texts  = array();
		foreach ( AttributeRules::clashes( $term ) as $clash ) {
			$other   = AttributeRules::source( $clash['term'] );
			$texts[] = $clash['wins']
				? sprintf(
					/* translators: 1: a value of another product attribute, e.g. Ram; 2: that attribute, e.g. Frame; 3: a part of the pricing rule, e.g. Fixed amount per item; 4: the attribute of this value, e.g. Size. On the screen that edits a product attribute's value. */
					__( '%1$s (%2$s) sets %3$s too. On a variation with both, this value’s wins: %4$s comes first in the store’s attribute order.', 'pixonaut-for-woocommerce' ),
					$other->value,
					$other->attribute,
					$labels[ $clash['part'] ] ?? $clash['part'],
					wc_attribute_label( $term->taxonomy )
				)
				: sprintf(
					/* translators: 1: a value of another product attribute, e.g. Ram; 2: that attribute, e.g. Frame; 3: a part of the pricing rule, e.g. Fixed amount per item. On the screen that edits a product attribute's value. */
					__( '%1$s (%2$s) sets %3$s too. On a variation with both, %1$s wins: %2$s comes first in the store’s attribute order (Products → Attributes).', 'pixonaut-for-woocommerce' ),
					$other->value,
					$other->attribute,
					$labels[ $clash['part'] ] ?? $clash['part']
				);
		}
		return $texts;
	}
}
