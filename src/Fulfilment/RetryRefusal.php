<?php
/**
 * Why Pixonaut refused to retry an order, in words.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Api\ErrorCodes;

/**
 * Every code `POST /v1/orders/{id}/retry` declares has words a merchant can
 * act on; a unit test keeps the list in step with the pinned spec. A code
 * this version does not know is named as it is, so a refused retry never
 * looks like nothing happened.
 */
final class RetryRefusal {

	/**
	 * Why the retry was refused, in words a merchant can act on.
	 *
	 * @param string $code Pixonaut's error code.
	 */
	public static function explain( string $code ): string {
		return self::words( $code ) ?? sprintf(
			/* translators: %s: the error code Pixonaut answered, e.g. order_at_printshop. */
			__( 'Pixonaut refused to place the order again with "%s". Nothing was changed.', 'pixonaut-for-woocommerce' ),
			$code
		);
	}

	/**
	 * The words for a code this version knows; null for any other.
	 *
	 * @param string $code Pixonaut's error code.
	 */
	public static function words( string $code ): ?string {
		return match ( $code ) {
			ErrorCodes::PRINT_NOT_COMPLETED       => __( 'The print is still being made. Try again once it is finished.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::ORDER_NOT_RETRYABLE       => __( 'The printshop order is no longer failed in Pixonaut, so it was not placed again.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::ORDER_AT_PRINTSHOP        => __( 'The printshop has the order already, so it is not placed again.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::ORDER_SUPERSEDED          => __( 'The order was placed again already, by an earlier retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINT_SUPERSEDED          => __( 'The design changed in Pixonaut since its print was made, so the print is no longer the design\'s. Check the design in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINT_FILES_DELETED       => __( 'The print\'s files were deleted in Pixonaut, so it cannot be sent to the printshop again.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::DESIGN_ALREADY_ORDERED    => __( 'The design has another printshop order in Pixonaut that this item did not place, for example one placed in the studio. Check that order in Pixonaut, and cancel it there or settle the item with the customer.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINTSHOP_PAUSED          => __( 'The printshop is paused in Pixonaut. Resume it in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINT_TICKET_STALE,
			ErrorCodes::STALE_UPDATE,
			ErrorCodes::LOCK_TIMEOUT,
			ErrorCodes::DB_ERROR                  => __( 'Pixonaut was busy with the order and changed nothing. Try again in a moment.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::NOT_FOUND,
			ErrorCodes::ORDER_NOT_FOUND,
			ErrorCodes::PRINT_NOT_FOUND           => __( 'Pixonaut no longer has the printshop order or its print. Check the order in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::BAD_REQUEST,
			ErrorCodes::PAYLOAD_TOO_LARGE         => __( 'Pixonaut did not accept the request the store sent. Check the Pixonaut log under WooCommerce > Status > Logs.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::FORBIDDEN,
			ErrorCodes::ORIGIN_FORBIDDEN          => __( 'The store\'s key may not place orders again in Pixonaut. Check the key in Pixonaut, or reconnect the store.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::FEATURE_DISABLED          => __( 'Printshop orders are turned off for the workspace in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::WORKSPACE_SUSPENDED       => __( 'The Pixonaut workspace is suspended. Check its billing in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PLAN_LIMIT_REACHED        => __( 'The workspace\'s plan in Pixonaut allows no more of this. Check the plan in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::UNKNOWN_PRINTSHOP,
			ErrorCodes::PRINTSHOP_UNAVAILABLE     => __( 'The printshop the order went to is no longer available in Pixonaut. Check the printshop in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PRINTSHOP_CANNOT_COMBINE,
			ErrorCodes::SPOT_PLATE_REQUIRED,
			ErrorCodes::PRINT_PROFILE_UNAVAILABLE,
			ErrorCodes::PRINT_RASTER_TOO_LARGE,
			ErrorCodes::TECHNIQUE_NOT_OFFERED,
			ErrorCodes::TECHNIQUE_DIFFERS,
			ErrorCodes::TECHNIQUE_UNKNOWN         => __( 'The printshop cannot print the item as it is: it does not offer the print\'s method or colour output, or the print file is too large for it. Check the printshop and the blank in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::PLACEMENT_UNFILLED,
			ErrorCodes::PLACEMENT_NOT_OF_BLANK,
			ErrorCodes::PLACEMENT_TAKEN,
			ErrorCodes::PLACEMENT_NAME_DIFFERS,
			ErrorCodes::PRINT_WITHOUT_PLACEMENT,
			ErrorCodes::DUPLICATE_PLACEMENT,
			ErrorCodes::MIXED_BLANKS,
			ErrorCodes::PRINTS_DISAGREE,
			ErrorCodes::PRINT_COMMITTED_ELSEWHERE => __( 'The item\'s prints do not fit the blank\'s placements in Pixonaut: a placement the blank must print is empty, or prints share or miss one. Check the blank\'s placements in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::REROUTE_BLANK_NOT_FOUND,
			ErrorCodes::REROUTE_SAME_BLANK,
			ErrorCodes::PRINT_NOT_OF_ORDER,
			ErrorCodes::PRINT_NOT_MAPPED,
			ErrorCodes::PRINT_MAPPED_TWICE        => __( 'Pixonaut refused moving the order to another blank. Place it again as it is, or move it in Pixonaut.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::POSTAL_CODE_REQUIRED      => __( 'The delivery address lacks the postcode the printshop needs. Add it to the order\'s shipping address, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::SAVED_ADDRESS_NOT_FOUND   => __( 'The saved address the order ships to is gone in Pixonaut. Check the order\'s shipping address, then retry.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::MISSING_FULFILMENT_KEYS   => NeedsAttention::explain( ErrorCodes::FIELD_VALUE_MISSING ),
			ErrorCodes::PRINT_EXCEEDS_POSITION,
			ErrorCodes::PRINT_EXCEEDS_PLACEMENT,
			ErrorCodes::PRINT_SHORT_OF_AREA       => NeedsAttention::explain( ErrorCodes::PRINT_NOT_POSITION_SIZE ),
			ErrorCodes::VARIANT_NOT_OF_DESIGN_ROW => NeedsAttention::explain( ErrorCodes::VARIANT_NOT_OF_BLANK ),
			// Shared with placing the order the first time.
			ErrorCodes::UNAUTHORIZED,
			ErrorCodes::DESIGN_ERASED,
			ErrorCodes::PRINT_NEEDS_PRINTSHOP,
			ErrorCodes::PRINTSHOP_DIFFERS_FROM_BLANK,
			ErrorCodes::PRINTSHOP_DISCONNECTED,
			ErrorCodes::PRINT_NOT_POSITION_SIZE,
			ErrorCodes::PRINT_EXCEEDS_MACHINE,
			ErrorCodes::FIELD_VALUE_MISSING,
			ErrorCodes::VARIANT_NOT_OF_BLANK,
			ErrorCodes::VARIANT_RETIRED,
			ErrorCodes::VARIANT_REQUIRED          => NeedsAttention::explain( $code ),
			default                               => null,
		};
	}
}
