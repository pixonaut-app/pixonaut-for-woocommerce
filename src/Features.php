<?php
/**
 * Switchable features.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

/**
 * Registry of the features that could later be gated: each layout and each
 * approval mode. Every check for one goes through enabled(), so gating is a
 * change in this class alone. All features are on.
 */
final class Features {

	public const LAYOUT_INLINE = 'layout_inline';

	public const LAYOUT_BUTTON = 'layout_button';

	public const APPROVAL_ADD_TO_CART = 'approval_add_to_cart';

	public const APPROVAL_CHECKBOX = 'approval_checkbox';

	public const APPROVAL_PROOF_EMAIL = 'approval_proof_email';

	public const ALL = array(
		self::LAYOUT_INLINE,
		self::LAYOUT_BUTTON,
		self::APPROVAL_ADD_TO_CART,
		self::APPROVAL_CHECKBOX,
		self::APPROVAL_PROOF_EMAIL,
	);

	/**
	 * Whether a feature is available. Unknown features are off.
	 *
	 * @param string $feature One of the class constants.
	 */
	public static function enabled( string $feature ): bool {
		return in_array( $feature, self::ALL, true );
	}
}
