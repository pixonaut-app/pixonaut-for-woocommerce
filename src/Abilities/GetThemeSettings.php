<?php
/**
 * The ability that reads the active theme's settings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Theme\ThemeSettings;

/**
 * The active theme, and each setting it declares for a store to set, with
 * its value now: a stored value that does not fit the setting's schema
 * reads as the default. Whoever may change the theme's options may read
 * them.
 */
final class GetThemeSettings implements Ability {

	public const NAME = 'get-theme-settings';

	/**
	 * The capability that reads and changes the theme's settings, as the Customizer asks.
	 */
	public const CAPABILITY = 'edit_theme_options';

	/**
	 * Any JSON value: a setting's default and value are what its schema says.
	 */
	private const ANY = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );

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
			'label'               => __( 'Read the theme\'s settings', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Gives the active theme and each setting it lets the store set: its key, label, description, the JSON schema a value must fit, its default and its value now. A theme that declares none has no settings.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => self::schema(),
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
	 * @return array{theme: array{stylesheet: string, name: string, version: string}, settings: list<array<string, mixed>>}
	 */
	public function execute(): array {
		return self::answer( $this->settings );
	}

	/**
	 * Whether the user may read and change the theme's settings.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * The active theme and its settings, as both abilities answer.
	 *
	 * @param ThemeSettings $settings The theme's settings.
	 * @return array{theme: array{stylesheet: string, name: string, version: string}, settings: list<array<string, mixed>>}
	 */
	public static function answer( ThemeSettings $settings ): array {
		$theme = wp_get_theme();
		return array(
			'theme'    => array(
				'stylesheet' => $theme->get_stylesheet(),
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
			),
			'settings' => $settings->read(),
		);
	}

	/**
	 * What both abilities answer, as a schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'theme'    => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => __( 'The active theme\'s directory name, a child theme\'s when one is active.', 'pixonaut-for-woocommerce' ),
						),
						'name'       => array( 'type' => 'string' ),
						'version'    => array( 'type' => 'string' ),
					),
				),
				'settings' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'         => array(
								'type'        => 'string',
								'description' => __( 'The WordPress option the setting is stored in.', 'pixonaut-for-woocommerce' ),
							),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'schema'      => array(
								'type'        => 'object',
								'description' => __( 'The JSON schema a value must fit.', 'pixonaut-for-woocommerce' ),
							),
							'default'     => array(
								'type'        => self::ANY,
								'description' => __( 'What the theme shows while the setting is not set.', 'pixonaut-for-woocommerce' ),
							),
							'value'       => array(
								'type'        => self::ANY,
								'description' => __( 'The stored value when it fits the schema, otherwise the default.', 'pixonaut-for-woocommerce' ),
							),
						),
					),
				),
			),
		);
	}
}
