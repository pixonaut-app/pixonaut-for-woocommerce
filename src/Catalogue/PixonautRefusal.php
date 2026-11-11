<?php
/**
 * What Pixonaut's refusals of a product check tell the store manager.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;

/**
 * The error codes Pixonaut can answer the requests that check a product
 * (its blank, template, constraints, print sizes and standing designs),
 * and the client's own for a request that got no usable answer, each with
 * what is wrong and what to do. Codes that mean the same thing to
 * a store manager share a message. A code not listed here has none: the
 * caller names the code itself.
 */
final class PixonautRefusal {

	private const NOT_CONNECTED = 'not_connected';

	private const UNREACHABLE = 'unreachable';

	private const UNEXPECTED = 'unexpected';

	private const CONNECTION = 'connection';

	private const PERMISSION = 'permission';

	private const ORIGIN = 'origin';

	private const FEATURE = 'feature';

	private const SUSPENDED = 'suspended';

	private const RATE = 'rate';

	private const PIXONAUT_FAULT = 'pixonaut_fault';

	private const GONE = 'gone';

	private const REQUEST = 'request';

	private const CONSTRAINTS = 'constraints';

	private const TECHNIQUE = 'technique';

	private const UNREADABLE = 'unreadable';

	private const PROOF = 'proof';

	private const PLACEMENT = 'placement';

	private const VARIANT = 'variant';

	private const PRINTSHOP = 'printshop';

	/**
	 * The codes by the message they share.
	 */
	private const GROUPS = array(
		self::NOT_CONNECTED  => array( ApiException::NOT_CONNECTED ),
		self::UNREACHABLE    => array( ApiException::TRANSPORT_FAILED ),
		self::UNEXPECTED     => array( ApiException::UNEXPECTED_RESPONSE ),
		self::CONNECTION     => array( 'unauthorized', 'member_link_required', 'member_link_revoked' ),
		self::PERMISSION     => array( 'forbidden', 'secret_key_required' ),
		self::ORIGIN         => array( 'origin_forbidden' ),
		self::FEATURE        => array( 'feature_disabled' ),
		self::SUSPENDED      => array( 'workspace_suspended' ),
		self::RATE           => array( 'rate_limited' ),
		self::PIXONAUT_FAULT => array( 'db_error', 'print_box_unavailable' ),
		self::GONE           => array( 'not_found' ),
		self::REQUEST        => array( 'bad_json', 'bad_request', 'invalid_body', 'invalid_query', 'invalid_doc', 'payload_too_large', 'template_version_required', 'sdk_version_invalid', 'sdk_outdated' ),
		self::CONSTRAINTS    => array( 'constraint_violation' ),
		self::TECHNIQUE      => array( 'design_not_stitchable', 'spot_plate_required' ),
		self::UNREADABLE     => array( 'design_unreadable' ),
		self::PROOF          => array( 'layout_proof_mismatch', 'not_a_layout_proof', 'proof_expired', 'unknown_proof' ),
		self::PLACEMENT      => array( 'unknown_placement' ),
		self::VARIANT        => array( 'variant_not_of_blank', 'variant_row_invalid', 'variant_required' ),
		self::PRINTSHOP      => array( 'printshop_differs_from_blank', 'printshop_disconnected', 'printshop_unavailable', 'unknown_printshop' ),
	);

	/**
	 * Whether a code has a message of its own.
	 *
	 * @param string $code An error code Pixonaut answered.
	 */
	public static function knows( string $code ): bool {
		return null !== self::group( $code );
	}

	/**
	 * What a refusal tells the store manager; null for a code not known.
	 *
	 * @param string $code      An error code Pixonaut answered.
	 * @param bool   $variation Whether it is about a variation's own configuration, set in its Pixonaut fields.
	 */
	public static function message( string $code, bool $variation = false ): ?string {
		$place = InvalidReason::place( $variation );
		switch ( self::group( $code ) ) {
			case self::NOT_CONNECTED:
				return __( 'The store is not connected to Pixonaut. Connect it on the Pixonaut settings page, then update the product.', 'pixonaut-for-woocommerce' );
			case self::UNREACHABLE:
				return InvalidReason::message( InvalidReason::CHECK_FAILED );
			case self::UNEXPECTED:
				return __( 'Pixonaut answered in a way this version of Pixonaut for WooCommerce does not understand. Update the plugin, then update the product.', 'pixonaut-for-woocommerce' );
			case self::CONNECTION:
				return __( 'Pixonaut no longer accepts the store’s connection. Connect the store again on the Pixonaut settings page, then update the product.', 'pixonaut-for-woocommerce' );
			case self::PERMISSION:
				return __( 'The store’s connection may not do this in Pixonaut. Check the connection’s permissions in the Pixonaut studio, then update the product.', 'pixonaut-for-woocommerce' );
			case self::ORIGIN:
				return __( 'Pixonaut refused the store’s web address. If the store moved to another address, connect it again on the Pixonaut settings page, then update the product.', 'pixonaut-for-woocommerce' );
			case self::FEATURE:
				return __( 'The Pixonaut workspace does not include the API. Check its plan in the Pixonaut studio, then update the product.', 'pixonaut-for-woocommerce' );
			case self::SUSPENDED:
				return __( 'The Pixonaut workspace is suspended. Contact Pixonaut to lift it, then update the product.', 'pixonaut-for-woocommerce' );
			case self::RATE:
				return __( 'Pixonaut was asked too often. Wait a minute, then update the product.', 'pixonaut-for-woocommerce' );
			case self::PIXONAUT_FAULT:
				return __( 'Pixonaut had a problem of its own. Update the product again in a while.', 'pixonaut-for-woocommerce' );
			case self::GONE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The template, blank or print position no longer exists in Pixonaut. Choose another in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::REQUEST:
				return __( 'Pixonaut could not read what the store sent. Update Pixonaut for WooCommerce, then update the product.', 'pixonaut-for-woocommerce' );
			case self::CONSTRAINTS:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The template’s own design breaks one of its rules at this print size. Fix the template in the Pixonaut studio, or choose another size or template in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::TECHNIQUE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The template cannot be made with the blank’s print technique, such as embroidery or spot colours. Choose a template made for it in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::UNREADABLE:
				return __( 'Pixonaut could not read the template’s design. Publish the template again in the Pixonaut studio, then update the product.', 'pixonaut-for-woocommerce' );
			case self::PROOF:
				return __( 'Pixonaut could not use an earlier proof of the design. Update the product again.', 'pixonaut-for-woocommerce' );
			case self::PLACEMENT:
				return InvalidReason::message( InvalidReason::UNKNOWN_PLACEMENT, null, $variation );
			case self::VARIANT:
				return __( 'The blank needs a variant for this print position, or the variant chosen is not one of the blank’s. Pick the variant in the variation’s Pixonaut fields.', 'pixonaut-for-woocommerce' );
			case self::PRINTSHOP:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The printshop is unknown, disconnected, unavailable, or not the one the blank is printed at. Choose another printshop in %s, or connect it again in the Pixonaut studio.', 'pixonaut-for-woocommerce' ),
					$place
				);
			default:
				return null;
		}
	}

	/**
	 * The message a code shares; null for a code not known.
	 *
	 * @param string $code An error code.
	 */
	private static function group( string $code ): ?string {
		foreach ( self::GROUPS as $group => $codes ) {
			if ( in_array( $code, $codes, true ) ) {
				return $group;
			}
		}
		return null;
	}
}
