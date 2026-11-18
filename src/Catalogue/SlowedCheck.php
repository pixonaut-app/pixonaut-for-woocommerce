<?php
/**
 * What a save keeps when Pixonaut refused to check it for too many requests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WC_Product;

/**
 * Pixonaut refusing a check because the store sent too many requests says
 * nothing about the configuration. A save that entered nothing new, such
 * as the variations screen saving every variation's panel with a price,
 * keeps the configuration stored and its verdict, so a product that sold
 * still sells; the re-check its save queues checks it once Pixonaut takes
 * checks again. A save that changed what is printed has nothing checked
 * to sell yet, and is stored as refused, until that re-check.
 */
final class SlowedCheck {

	/**
	 * What a stored side holds that a check found rather than the store
	 * manager entered, by `Side::to_array()` key: left out when comparing
	 * what was entered. The menu entered is compared in their place.
	 */
	private const SIDE_FOUND = array( 'label', 'placement', 'template', 'printSizes', 'pendingSizes', 'startSize', 'standingDesignId', 'standingRows', 'misfitSizes', 'rowSizes', 'lowResolution' );

	/**
	 * What a stored configuration holds that a check found, by
	 * `ProductConfig::to_array()` key.
	 */
	private const CONFIG_FOUND = array( 'blank', 'sides', 'validity' );

	/**
	 * The configuration a save stores: the one stored, when Pixonaut
	 * refused to check the save for too many requests and it entered
	 * nothing new, unless the one stored waits for a check itself; else
	 * the one checked.
	 *
	 * @param WC_Product    $product The product or variation saved.
	 * @param ProductConfig $checked The configuration its save checked.
	 */
	public static function kept( WC_Product $product, ProductConfig $checked ): ProductConfig {
		if ( ! self::slowed( $checked ) || ! ProductConfig::is_stored_on( $product ) ) {
			return $checked;
		}
		$stored = ProductConfig::stored_on( $product );
		if ( in_array( $stored->invalid_reason, InvalidReason::ASK_AGAIN, true ) ) {
			return $checked;
		}
		return self::entered( $stored ) === self::entered( $checked ) ? $stored : $checked;
	}

	/**
	 * Whether a configuration was refused because Pixonaut was sent too many requests.
	 *
	 * @param ProductConfig $config The configuration.
	 */
	public static function slowed( ProductConfig $config ): bool {
		return InvalidReason::CHECK_FAILED === $config->invalid_reason && ErrorCode::RATE_LIMITED === $config->invalid_detail?->code;
	}

	/**
	 * What the store manager entered of a configuration.
	 *
	 * @param ProductConfig $config The configuration.
	 * @return array<string, mixed>
	 */
	private static function entered( ProductConfig $config ): array {
		$entered          = array_diff_key( $config->to_array(), array_flip( self::CONFIG_FOUND ) );
		$entered['blank'] = $config->blank_id;
		$entered['sides'] = array_map(
			static fn ( Side $side ): array => array_diff_key( $side->to_array(), array_flip( self::SIDE_FOUND ) ) + array(
				'label'     => $side->typed_label(),
				'placement' => $side->placement_id,
				'template'  => array( $side->template_base_id, $side->template_version, $side->pinned ),
				'menu'      => array_map( static fn ( ?PrintSize $size ): ?array => $size?->stored(), $side->menu() ),
				'start'     => $side->start_size,
			),
			$config->sides
		);
		return $entered;
	}
}
