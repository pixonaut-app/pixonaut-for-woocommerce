<?php
/**
 * Design approval modes.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Pixonaut\WooCommerce\Settings\Settings;

/**
 * How a customer approves a design before it is printed.
 */
final class ApprovalMode {

	/**
	 * Adding to the cart approves the design.
	 */
	public const ADD_TO_CART = 'add_to_cart';

	/**
	 * The customer ticks "I approve the preview" before adding to the cart.
	 */
	public const CHECKBOX = 'checkbox';

	/**
	 * After payment the customer approves a proof sent by email.
	 */
	public const PROOF_EMAIL = 'proof_email';

	/**
	 * The feature that gates each mode.
	 */
	public const FEATURES = array(
		self::ADD_TO_CART => Features::APPROVAL_ADD_TO_CART,
		self::CHECKBOX    => Features::APPROVAL_CHECKBOX,
		self::PROOF_EMAIL => Features::APPROVAL_PROOF_EMAIL,
	);

	/**
	 * @return list<string> The modes whose feature is enabled.
	 */
	public static function available(): array {
		return array_keys( array_filter( self::FEATURES, array( Features::class, 'enabled' ) ) );
	}

	/**
	 * Each mode's name, for the store manager.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			/* translators: Option in the approval setting, shown to the merchant on the settings page and the product page. */
			self::ADD_TO_CART => __( 'Adding to the cart approves the design', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the approval setting, shown to the merchant; the quoted text is the checkbox label the shopper sees. */
			self::CHECKBOX    => __( 'Customer ticks "I approve the preview"', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the approval setting, shown to the merchant: the shopper approves a proof sent by email once the order is paid. */
			self::PROOF_EMAIL => __( 'Proof by email after payment', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * The mode a product uses: its own when it names an available one, else
	 * the store's default.
	 *
	 * @param string|null $override The product's override; null follows the setting.
	 * @param Settings    $settings The store's settings.
	 */
	public static function resolve( ?string $override, Settings $settings ): string {
		return in_array( $override, self::available(), true )
			? (string) $override
			: (string) $settings->get( Settings::DEFAULT_APPROVAL_MODE );
	}

	/**
	 * The consent a customer gives in a mode by adding to the cart, or by
	 * updating the design in their cart: the mode's own kind for a mode that
	 * takes it then; null for one whose proof by email takes it after payment.
	 *
	 * @param string $mode  The line's approval mode.
	 * @param int    $at_ms When, in epoch milliseconds.
	 *
	 * @return array{kind: string, at: int}|null
	 */
	public static function consent_in_cart( string $mode, int $at_ms ): ?array {
		return in_array( $mode, array( self::ADD_TO_CART, self::CHECKBOX ), true )
			? array(
				'kind' => $mode,
				'at'   => $at_ms,
			)
			: null;
	}
}
