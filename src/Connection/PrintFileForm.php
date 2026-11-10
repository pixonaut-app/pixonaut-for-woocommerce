<?php
/**
 * The form the store's print files are made in.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use InvalidArgumentException;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreTextTolerance;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Enum\ConnectedStoreUpdateRequestPrintFileForm as Form;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequestPrintFileTextToleranceMm;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateRequestPrintFileTextTolerancePx;

/**
 * Pixonaut hands a print file out flat, layered (each printed layer its own
 * optional content group, text as shapes), or layered with plain text live,
 * which may lie from its outline by up to a tolerance. The workspace sets
 * both; the store may choose its own, and a template outranks either.
 */
final class PrintFileForm {

	public const FLAT = Form::FLAT;

	public const LAYERED = Form::LAYERED;

	public const LAYERED_LIVE_TEXT = Form::LAYERED_LIVE_TEXT;

	/**
	 * Device pixels.
	 */
	public const PX = 'px';

	/**
	 * Millimetres.
	 */
	public const MM = 'mm';

	/**
	 * The least and the most live text tolerance `PATCH /v1/connect` takes, by unit.
	 */
	public const TOLERANCE_LIMITS = array(
		self::PX => array(
			'min' => 1,
			'max' => 100,
		),
		self::MM => array(
			'min' => 0.01,
			'max' => 5,
		),
	);

	/**
	 * The most decimals a tolerance is shown with: Pixonaut takes any
	 * number in the limits, and a finer one prints no differently.
	 */
	private const MOST_DECIMALS = 6;

	/**
	 * The forms the store may choose, by value, named for the merchant.
	 *
	 * @return array<string, string>
	 */
	public static function forms(): array {
		return array(
			/* translators: Print file form on the connection card: one flat image per print. */
			self::FLAT              => __( 'Flat', 'pixonaut-for-woocommerce' ),
			/* translators: Print file form on the connection card: each printed layer its own PDF layer, with text turned into shapes. */
			self::LAYERED           => __( 'Layers, text as shapes', 'pixonaut-for-woocommerce' ),
			/* translators: Print file form on the connection card: each printed layer its own PDF layer, with plain text kept as editable text. */
			self::LAYERED_LIVE_TEXT => __( 'Layers with live text', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * A form as the merchant reads it; one this release does not know, as Pixonaut names it.
	 *
	 * @param string $form A form Pixonaut answered.
	 */
	public static function name( string $form ): string {
		return self::forms()[ $form ] ?? $form;
	}

	/**
	 * A tolerance as the merchant reads it: `1 px`, `0.25 mm`.
	 *
	 * @param ConnectedStoreTextTolerance $tolerance The tolerance.
	 */
	public static function tolerance_name( ConnectedStoreTextTolerance $tolerance ): string {
		/* translators: 1: a number, 2: its unit, px or mm. A live text tolerance on the connection card, e.g. 0.5 mm. */
		return sprintf( __( '%1$s %2$s', 'pixonaut-for-woocommerce' ), self::number( $tolerance->value ), $tolerance->unit );
	}

	/**
	 * A number with as many decimals as it has, up to MOST_DECIMALS, so a
	 * tolerance is never shown tighter than it is: 1, 0.5, 0.014.
	 *
	 * @param float $number The number.
	 */
	private static function number( float $number ): string {
		$decimals = self::MOST_DECIMALS;
		while ( $decimals > 0 && round( $number, $decimals - 1 ) === round( $number, $decimals ) ) {
			--$decimals;
		}
		return number_format_i18n( $number, $decimals );
	}

	/**
	 * The limits of a tolerance as the merchant reads them: `1 to 100 px, or 0.01 to 5 mm`.
	 */
	public static function tolerance_limits(): string {
		return sprintf(
			/* translators: 1: least and 2: most device pixels, 3: least and 4: most millimetres a live text tolerance may be. Shown on the connection card. */
			__( '%1$s to %2$s px, or %3$s to %4$s mm', 'pixonaut-for-woocommerce' ),
			self::number( self::TOLERANCE_LIMITS[ self::PX ]['min'] ),
			self::number( self::TOLERANCE_LIMITS[ self::PX ]['max'] ),
			self::number( self::TOLERANCE_LIMITS[ self::MM ]['min'] ),
			self::number( self::TOLERANCE_LIMITS[ self::MM ]['max'] )
		);
	}

	/**
	 * The change `PATCH /v1/connect` makes for the form posted: a form, or
	 * empty to follow the workspace's. A tolerance goes with live text
	 * only; an empty one follows the workspace's, as does any with another
	 * form, which has no live text. A form this release does not know is
	 * one Pixonaut answered as the store's, offered as it is: saving it
	 * changes nothing, so there is nothing to send.
	 *
	 * @param string $form  The form posted.
	 * @param string $value The tolerance posted, a number or empty.
	 * @param string $unit  Its unit, `px` or `mm`.
	 *
	 * @return ConnectedStoreUpdateRequest|null Null when nothing changes.
	 *
	 * @throws InvalidArgumentException When the tolerance is not a number in its unit's limits.
	 */
	public static function request( string $form, string $value, string $unit ): ?ConnectedStoreUpdateRequest {
		if ( '' !== $form && ! array_key_exists( $form, self::forms() ) ) {
			return null;
		}
		$tolerance = self::LAYERED_LIVE_TEXT === $form ? self::tolerance( $value, $unit ) : null;
		return new ConnectedStoreUpdateRequest(
			printFileForm: '' === $form ? null : $form,
			printFileTextTolerance: $tolerance,
		);
	}

	/**
	 * @param string $value The tolerance posted, a number or empty.
	 * @param string $unit  Its unit.
	 *
	 * @throws InvalidArgumentException When it is not a number in its unit's limits.
	 */
	private static function tolerance( string $value, string $unit ): ConnectedStoreUpdateRequestPrintFileTextTolerancePx|ConnectedStoreUpdateRequestPrintFileTextToleranceMm|null {
		$value = trim( str_replace( ',', '.', $value ) );
		if ( '' === $value ) {
			return null;
		}
		$limits = self::TOLERANCE_LIMITS[ $unit ] ?? null;
		if ( null === $limits || ! is_numeric( $value ) ) {
			throw new InvalidArgumentException( 'Not a tolerance.' );
		}
		$number = (float) $value;
		if ( $number < $limits['min'] || $number > $limits['max'] ) {
			throw new InvalidArgumentException( 'The tolerance is out of its limits.' );
		}
		return self::PX === $unit
			? new ConnectedStoreUpdateRequestPrintFileTextTolerancePx( unit: self::PX, value: $number )
			: new ConnectedStoreUpdateRequestPrintFileTextToleranceMm( unit: self::MM, value: $number );
	}
}
