<?php
/**
 * One ability the plugin registers.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

/**
 * An ability on the WordPress Abilities API: its name under
 * `Abilities::NAMESPACE`, and what `wp_register_ability()` takes for it
 * besides how it is exposed, which `Abilities` adds, and its category
 * unless it names one.
 */
interface Ability {

	/**
	 * The ability's name, without the namespace, e.g. `list-templates`.
	 */
	public function name(): string;

	/**
	 * `label`, `description`, `input_schema`, `output_schema`, `category`,
	 * `execute_callback`, `permission_callback`, and `annotations`
	 * (`readonly`, `destructive`, `idempotent`).
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array;
}
