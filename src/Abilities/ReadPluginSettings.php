<?php
/**
 * The ability that reads the plugin's settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;

/**
 * Every setting on the Pixonaut settings page, by its key, as the page
 * shows it: a stored value that is no longer valid reads as the default.
 * The hidden developer tools' addresses are left out. Whoever may change
 * the settings may read them.
 */
final class ReadPluginSettings implements Ability {

	/**
	 * @param Settings $settings The settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	public const NAME = 'get-plugin-settings';

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the Pixonaut settings', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Gives every setting on the Pixonaut settings page, by its key: the default layout, form layout, designer language, loading and accent colour, the print area\'s frame, product pictures, the design\'s fields and picture in the cart, the default approval, and proof deadlines and reminders.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => self::schema( $this->settings ),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( self::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		return self::values( $this->settings );
	}

	/**
	 * Whether the user may read and change the settings, as on the settings page.
	 */
	public static function can_manage(): bool {
		return current_user_can( SettingsPage::CAPABILITY );
	}

	/**
	 * The settings the abilities read and write: the settings page's own,
	 * not the developer tools'.
	 *
	 * @param Settings $settings The settings.
	 * @return array<string, Setting>
	 */
	public static function settings( Settings $settings ): array {
		return $settings->section( Setting::GENERAL );
	}

	/**
	 * Each setting's value now, by its key.
	 *
	 * @param Settings $settings The settings.
	 * @return array<string, mixed>
	 */
	public static function values( Settings $settings ): array {
		$values = array();
		foreach ( array_keys( self::settings( $settings ) ) as $key ) {
			$values[ $key ] = $settings->get( $key );
		}
		return $values;
	}

	/**
	 * The settings as an object's schema, one property per setting.
	 *
	 * @param Settings $settings The settings.
	 * @return array{type: string, properties: array<string, array<string, mixed>>}
	 */
	public static function schema( Settings $settings ): array {
		return array(
			'type'       => 'object',
			'properties' => array_map( static fn ( Setting $setting ): array => $setting->schema(), self::settings( $settings ) ),
		);
	}
}
