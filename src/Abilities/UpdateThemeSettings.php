<?php
/**
 * The ability that changes the active theme's settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Theme\ThemeSettings;
use WP_Error;

/**
 * Changes the theme's settings given, each checked against its schema
 * before any is stored: a key the theme does not declare, or a value that
 * does not fit, changes nothing. Null deletes a setting's option, so the
 * theme shows its default.
 */
final class UpdateThemeSettings implements Ability {

	public const NAME = 'update-theme-settings';

	public const UNKNOWN = 'pixonaut_wc_ability_theme_setting_unknown';

	public const INVALID = 'pixonaut_wc_ability_theme_setting_invalid';

	/**
	 * @param ThemeSettings $settings The theme's settings.
	 */
	public function __construct( private readonly ThemeSettings $settings ) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Change the theme\'s settings', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that reads the theme's settings. */
				__( 'Changes the active theme\'s settings given in `settings`, by their keys as %s reads them, each to a value that fits its schema, or to null to clear it so the theme shows its default; the others keep their values. A key the theme does not have, or a value that does not fit, changes nothing. Answers with every setting.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( GetThemeSettings::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'settings' => array(
						'type'          => 'object',
						'description'   => __( 'The new values, by key; null clears a setting.', 'pixonaut-for-woocommerce' ),
						'minProperties' => 1,
					),
				),
				'required'             => array( 'settings' ),
				'additionalProperties' => false,
			),
			'output_schema'       => GetThemeSettings::schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( GetThemeSettings::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `settings`: the values to store, by key.
	 * @return array<string, mixed>|WP_Error Every setting, or why nothing changed.
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$values  = is_array( $input ) && is_array( $input['settings'] ?? null ) ? $input['settings'] : array();
		$refusal = $this->settings->refusal( $values );
		if ( null !== $refusal && null === $refusal['error'] ) {
			/* translators: %s: the key of a theme's setting, e.g. my_theme_header. */
			return new WP_Error( self::UNKNOWN, sprintf( __( 'The theme has no setting %s. Nothing was changed.', 'pixonaut-for-woocommerce' ), $refusal['key'] ) );
		}
		if ( null !== $refusal ) {
			return new WP_Error(
				self::INVALID,
				sprintf(
					/* translators: 1: the key of a theme's setting, e.g. my_theme_header, 2: why its schema does not take the value. */
					__( 'The setting %1$s does not take that value: %2$s Nothing was changed.', 'pixonaut-for-woocommerce' ),
					$refusal['key'],
					$refusal['error']->get_error_message()
				)
			);
		}
		$this->settings->write( $values );
		return GetThemeSettings::answer( $this->settings );
	}
}
