<?php
/**
 * The ability that changes the plugin's settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\Accent;
use WP_Error;

/**
 * Changes the settings it is given, as saving the settings page does, in
 * one write, so what follows a change (product pictures drawn again or put
 * back) runs once. A value the setting does not take is refused rather
 * than saved as the default.
 */
final class UpdatePluginSettings implements Ability {

	/**
	 * @param Settings $settings The settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	public function name(): string {
		return 'update-plugin-settings';
	}

	public function definition(): array {
		$schema = ReadPluginSettings::schema( $this->settings );
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Change the Pixonaut settings', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that reads the settings. */
				__( 'Changes the Pixonaut settings given, by their keys as %s reads them; the others keep their values. A custom accent colour needs `designer_accent_colour`. Answers with every setting.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( ReadPluginSettings::NAME )
			),
			'input_schema'        => $schema + array(
				'minProperties'        => 1,
				'additionalProperties' => false,
			),
			'output_schema'       => $schema,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ReadPluginSettings::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input The settings to change, by key.
	 * @return array<string, mixed>|WP_Error Every setting, or why nothing changed.
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input    = is_array( $input ) ? $input : array();
		$settings = ReadPluginSettings::settings( $this->settings );
		foreach ( $input as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) ) {
				/* translators: %s: the key of a setting, e.g. sdk_url. */
				return new WP_Error( 'pixonaut_wc_ability_setting_unknown', sprintf( __( 'The Pixonaut settings have no setting %s.', 'pixonaut-for-woocommerce' ), $key ) );
			}
			if ( ! $settings[ $key ]->accepts( $value ) ) {
				/* translators: %s: the key of a setting, e.g. default_layout. */
				return new WP_Error( 'pixonaut_wc_ability_setting_invalid', sprintf( __( 'The setting %s does not take that value.', 'pixonaut-for-woocommerce' ), $key ) );
			}
		}
		$accent = $input[ Settings::DESIGNER_ACCENT ] ?? $this->settings->get( Settings::DESIGNER_ACCENT );
		$colour = $settings[ Settings::DESIGNER_ACCENT_COLOUR ]->resolve( $input[ Settings::DESIGNER_ACCENT_COLOUR ] ?? $this->settings->get( Settings::DESIGNER_ACCENT_COLOUR ) );
		if ( Accent::CUSTOM === $accent && '' === $colour ) {
			return new WP_Error( 'pixonaut_wc_ability_accent_colour', __( 'A custom accent colour needs the colour: give designer_accent_colour, e.g. #1e73be.', 'pixonaut-for-woocommerce' ) );
		}
		$this->settings->set_many( $input );
		return ReadPluginSettings::values( $this->settings );
	}
}
