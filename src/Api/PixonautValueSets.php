<?php
/**
 * Value sets, made by Pixonaut with the store's key.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;

/**
 * Asks Pixonaut for a template's value set with the integration key: the
 * only code that sends `templates.valueSets.create`, so the rest of the
 * plugin depends on `ValueSets` alone.
 */
final class PixonautValueSets implements ValueSets {

	/**
	 * Pixonaut's refusal of values the version does not take.
	 */
	public const INVALID = 'value_set_invalid';

	/**
	 * @param Pixonaut $pixonaut The SDK.
	 */
	public function __construct( private readonly Pixonaut $pixonaut ) {
	}

	public function make( string $template_id, array $values ): string {
		$sdk = $this->pixonaut->sdk();
		try {
			$result = $sdk->send( $sdk->prepare( self::CREATE, array( 'id' => $template_id ), array(), array( 'values' => $values ) ) );
		} catch ( ApiException $error ) {
			if ( self::INVALID === $error->errorCode ) {
				throw ValueSetRefused::from_answer( $error->details['issues'] ?? null );
			}
			throw $error;
		}
		$id = $result->object()['valueSetId'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw $result->unexpected();
		}
		return $id;
	}
}
