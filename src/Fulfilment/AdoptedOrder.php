<?php
/**
 * A printshop order one of the workspace's automations placed for a line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Cart\StandingSide;

/**
 * A design has one live order in Pixonaut, whoever placed it: an order of a
 * design an automation ordered first is refused `design_already_ordered`,
 * naming that order. When the automation's order makes the line's item as
 * the customer bought it, the line takes it on instead of needing the
 * merchant, and keeps the automation's name and the ref the order was
 * placed under, so the order box can say so and the order's events still
 * find the line. Any other order the refusal names still needs the merchant.
 */
final class AdoptedOrder {

	/**
	 * An order history entry's `via` when one of the workspace's automations made it.
	 */
	public const VIA_AUTOMATION = 'automation';

	/**
	 * The live order a refusal names, when Pixonaut refused an order because
	 * the design has one.
	 *
	 * @param ApiException $error A refusal of `POST /v1/orders` or its retry.
	 *
	 * @return string|null The order's id; null for any other refusal.
	 */
	public static function named_by( ApiException $error ): ?string {
		$order_id = $error->details['orderId'] ?? null;
		return ErrorCode::DESIGN_ALREADY_ORDERED === $error->errorCode && is_string( $order_id ) && '' !== $order_id ? $order_id : null;
	}

	/**
	 * Whether the line may take an order on: an automation placed it, and it
	 * makes the line's item as the customer bought it.
	 *
	 * @param LineState            $line  The line.
	 * @param array<string, mixed> $order `GET /v1/orders/{id}`'s answer.
	 */
	public static function takes( LineState $line, array $order ): bool {
		$created = self::created( $order );
		return self::VIA_AUTOMATION === ( $created['via'] ?? null ) && self::fits( $line, $order );
	}

	/**
	 * Whether an order makes the line's item as the customer bought it: a
	 * print of every side's design, a customer's at the line's revision and a
	 * standing one at whichever revision it printed, and no other design's
	 * (a fill print of a placement left undesigned holds none); the line's
	 * quantity, the variant the line sells, and the printshop its
	 * product chose, when it chose one. A refusal names any
	 * order holding one of the designs, so an order of the front alone, or of
	 * fewer copies, is not one the line can take on.
	 *
	 * @param LineState            $line  The line.
	 * @param array<string, mixed> $order `GET /v1/orders/{id}`'s answer.
	 */
	private static function fits( LineState $line, array $order ): bool {
		$wanted = array();
		foreach ( $line->sides() as $side ) {
			$wanted[] = $line->side_ref( $side, LineState::SIDE_DESIGN_ID ) . '@' . $line->side_ref( $side, LineState::SIDE_REVISION );
		}
		$standing = array_map( static fn ( StandingSide $side ): string => $side->design_id, $line->standing() );
		$held     = array();
		foreach ( is_array( $order['prints'] ?? null ) ? $order['prints'] : array() as $print ) {
			// A fill print, of an optional placement the order left undesigned,
			// is made from no design and holds no side of the line.
			if ( ! is_array( $print ) || ! is_string( $print['designId'] ?? null ) ) {
				continue;
			}
			$at = array_search( $print['designId'], $standing, true );
			if ( false !== $at ) {
				unset( $standing[ $at ] );
				continue;
			}
			$held[] = $print['designId'] . '@' . ( $print['designRevision'] ?? '' );
		}
		if ( array() !== $standing ) {
			return false;
		}
		sort( $wanted );
		sort( $held );
		$variant   = $line->ref( LineState::REF_VARIANT );
		$printshop = $line->ref( LineState::REF_PRINTSHOP );
		return $wanted === $held
			&& ( $order['quantity'] ?? null ) === max( 1, $line->item()->get_quantity() )
			&& ( ! is_string( $variant ) || '' === $variant || ( $order['variantRef'] ?? null ) === $variant )
			&& ( ! is_string( $printshop ) || '' === $printshop || ( $order['printshopConnectionId'] ?? null ) === $printshop );
	}

	/**
	 * Stores the automation that placed an order the line takes on, and the
	 * ref the order was placed under when that is not the line's. The caller
	 * saves the line.
	 *
	 * @param LineState            $line  The line, about to hold the order's id.
	 * @param array<string, mixed> $order `GET /v1/orders/{id}`'s answer.
	 */
	public static function record( LineState $line, array $order ): void {
		$automation = self::created( $order )['automation'] ?? null;
		$line->set_ref( LineState::REF_ORDER_AUTOMATION, is_array( $automation ) && is_string( $automation['name'] ?? null ) ? $automation['name'] : '' );
		$ref = is_string( $order['externalRef'] ?? null ) ? $order['externalRef'] : null;
		$line->set_ref( LineState::REF_ORDER_EXTERNAL_REF, null !== $ref && $ref !== $line->ref( LineState::REF_EXTERNAL_REF ) ? $ref : null );
	}

	/**
	 * The fingerprint, by OrderAddress::fingerprint(), of who and where an
	 * order ships: the workspace's saved address when it ships there, else
	 * its customer's.
	 *
	 * @param array<string, mixed> $order `GET /v1/orders/{id}`'s answer.
	 */
	public static function address( array $order ): string {
		$ship_to = is_array( $order['shipTo'] ?? null ) ? $order['shipTo'] : null;
		return OrderAddress::fingerprint(
			array(
				'recipient'       => $order['recipient'] ?? null,
				'shippingAddress' => null === $ship_to ? ( $order['shippingAddress'] ?? null ) : ( $ship_to['address'] ?? null ),
			)
		);
	}

	/**
	 * What the order box says about a line's order an automation placed.
	 *
	 * @param LineState $line The line.
	 *
	 * @return string '' when the store placed it.
	 */
	public static function label( LineState $line ): string {
		$name = $line->ref( LineState::REF_ORDER_AUTOMATION );
		if ( ! is_string( $name ) ) {
			return '';
		}
		return '' === $name
			/* translators: Line detail on the order screen, for an item whose printshop order an automation in Pixonaut placed. The merchant reads it. */
			? __( 'A Pixonaut automation placed the printshop order.', 'pixonaut-for-woocommerce' )
			/* translators: %s: the automation's name in Pixonaut. */
			: sprintf( __( 'The Pixonaut automation "%s" placed the printshop order.', 'pixonaut-for-woocommerce' ), $name );
	}

	/**
	 * The first entry of an order's history: who created it.
	 *
	 * @param array<string, mixed> $order `GET /v1/orders/{id}`'s answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function created( array $order ): array {
		$history = is_array( $order['history'] ?? null ) ? $order['history'] : array();
		$first   = reset( $history );
		return is_array( $first ) ? $first : array();
	}
}
