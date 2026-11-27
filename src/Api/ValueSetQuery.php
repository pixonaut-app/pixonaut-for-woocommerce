<?php
/**
 * The query naming a template's value set.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * `valueSet=<id>`, which every template read takes beside `tz` and `lang`:
 * the template read, a validation, a proof, a commit, and the preview and
 * mockup pictures, read with the set's values written in as the layers'
 * own. The one place the plugin names it, so every read it sends with a set
 * names it alike. pixonaut-php 31 sends a query it does not declare as it
 * is given, so it goes out now; once the client declares it, this is where
 * a read switches to the client's own parameter.
 */
final class ValueSetQuery {

	/**
	 * The query parameter.
	 */
	public const NAME = 'valueSet';

	/**
	 * The query naming a set; null names none and is left out.
	 *
	 * @param string|null $value_set_id The set, `vs_…`; null for the template's own values.
	 * @return array<string, string|null>
	 */
	public static function of( ?string $value_set_id ): array {
		return array( self::NAME => $value_set_id );
	}
}
