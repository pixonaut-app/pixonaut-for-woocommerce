<?php
/**
 * What makes a template's value sets.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;

/**
 * `POST /v1/templates/{id}/value-sets`: a set of values for a template's
 * layers, judged against the version named with every prop treated as
 * offered, and kept by Pixonaut under an id a template read names as
 * `valueSet`. The same values for the same template answer the same set.
 */
interface ValueSets {

	/**
	 * The operation that makes a set.
	 */
	public const CREATE = 'templates.valueSets.create';

	/**
	 * Makes the set of `$values` for a template, or finds the one they made before.
	 *
	 * @param string                              $template_id `<baseId>@<version>`, the version the values are judged against.
	 * @param array<string, array<string, mixed>> $values      Values by layer id and prop.
	 * @return string The set's id, `vs_…`.
	 *
	 * @throws ValueSetRefused      When Pixonaut refused the values, saying which.
	 * @throws ValueSetsUnavailable When the plugin cannot ask Pixonaut for one.
	 * @throws ApiException         When Pixonaut refused otherwise (`404 template_not_found`), could not be reached, or answered without the set.
	 */
	public function make( string $template_id, array $values ): string;
}
