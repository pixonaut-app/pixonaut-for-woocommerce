<?php
/**
 * What the printshop charges for a blank's variants.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Pricing\VariantCost;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * Pixonaut reads each blank variant's price and cheapest shipping from its
 * printshop in the background, and keeps them current. The store reads them
 * to price what it sells on the variant. A country asked about for the
 * first time is read within moments: until then its list is short, or empty.
 */
final class VariantCosts {

	private const OPERATION = 'blanks.variants.costs';

	/**
	 * Pixonaut's refusal of a country its printshops publish no costs for.
	 */
	public const UNKNOWN_REGION = 'unknown_region';

	public function __construct( private readonly Sdk $pixonaut ) {
	}

	/**
	 * `GET /v1/blanks/{id}/variants/costs?region`: each variant's cost with
	 * shipping to a region.
	 *
	 * @param string $blank_id The blank.
	 * @param string $region   The country shipping is priced to, as its two-letter code.
	 * @return array<string, VariantCost> The costs by variant ref; a variant the printshop publishes no price for has a cost without one, and one Pixonaut has not read yet is left out.
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown blank, `422` for an unknown region), could not be reached, or answered without the list.
	 */
	public function of_blank( string $blank_id, string $region ): array {
		$answer = $this->pixonaut->call( self::OPERATION, array( 'id' => $blank_id ), array( 'region' => $region ) )->object();
		$costs  = $answer['costs'] ?? null;
		if ( ! is_array( $costs ) || ! array_is_list( $costs ) ) {
			throw new ApiException( self::OPERATION, 200, ApiException::UNEXPECTED_RESPONSE, false );
		}
		return VariantCost::list_from( $costs );
	}
}
