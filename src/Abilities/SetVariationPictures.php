<?php
/**
 * Ability: turns a picture for every variation on or off, store-wide or for
 * a variable product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\VariationPictureCost;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WP_Error;

/**
 * Sets "Generate a picture for every variation" as the settings page or the
 * product's Pixonaut tab does: turning it on past the warning (more than
 * `VariationPictureCost::WARN_PERCENT` of the shop's free storage, or free
 * storage that cannot be read) needs `confirm`, as the store manager ticks
 * it there. Turning it off deletes the variation pictures nothing else
 * uses. Answers as `GetVariationPictures` reads it afterwards, with what
 * the change did.
 */
final class SetVariationPictures implements Ability {

	public const NAME = 'set-variation-pictures';

	/**
	 * @param VariationPicturesState $state    Reads it and its costs.
	 * @param Settings               $settings The store-wide setting.
	 */
	public function __construct(
		private readonly VariationPicturesState $state,
		private readonly Settings $settings,
	) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Change the picture for every variation setting', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that reads the setting. */
				__( 'Turns drawing a Pixonaut picture for every variation on or off, store-wide (`choice` `on` or `off`) or for a variable product (`default` follows the store-wide setting). Read %s first and tell the store manager what it costs: turning it on draws one picture per variant into the shop\'s own media library. When its `warning` applies, turning it on is refused unless `confirm` is true, which only the store manager may decide. Turning it off deletes the variation pictures nothing else uses. Answers with the setting as it is now and `changed`, what the change costs.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( GetVariationPictures::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => VariationPicturesState::product_input(),
					'choice'     => array(
						'type'        => 'string',
						'enum'        => VariationPictures::CHOICES,
						'description' => __( '`on` or `off`; for a product also `default`, the store-wide setting.', 'pixonaut-for-woocommerce' ),
					),
					'confirm'    => array(
						'type'        => 'boolean',
						'description' => __( 'The store manager accepts the warning about the shop\'s storage.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'choice' ),
				'additionalProperties' => false,
			),
			'output_schema'       => VariationPicturesState::output_schema( true ),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( self::class, 'can_use' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Whether the user may read and change it: a product's as on its edit
	 * screen, the store-wide one as on the settings page.
	 *
	 * @param mixed $input The ability's input.
	 */
	public static function can_use( mixed $input = null ): bool {
		return 0 === Abilities::integer( $input, 'product_id', 0 ) ? ReadPluginSettings::can_manage() : ProductLinks::can_edit( $input );
	}

	/**
	 * @param mixed $input `choice`, `confirm`, and `product_id` for a product.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = VariationPicturesState::product( $input );
		if ( $product instanceof WP_Error ) {
			return $product;
		}
		$choice = is_array( $input ) && is_string( $input['choice'] ?? null ) ? $input['choice'] : '';
		if ( ! in_array( $choice, null === $product ? array( VariationPictures::ON, VariationPictures::OFF ) : VariationPictures::CHOICES, true ) ) {
			return new WP_Error( 'pixonaut_wc_ability_variation_pictures_choice', __( 'The store-wide setting is on or off; a product\'s is default, on or off.', 'pixonaut-for-woocommerce' ) );
		}
		$cost    = $this->state->cost_of( $product, $choice );
		$warning = VariationPictureCost::warning( $cost );
		if ( null !== $warning && true !== ( $input['confirm'] ?? null ) ) {
			return new WP_Error( 'pixonaut_wc_ability_variation_pictures_unconfirmed', $warning . ' ' . __( 'Nothing was changed. Tell the store manager, and call again with confirm true only if they accept it.', 'pixonaut-for-woocommerce' ) );
		}
		if ( null === $product ) {
			$this->settings->set( Settings::VARIATION_PICTURES, VariationPictures::ON === $choice );
		} else {
			VariationPictures::choose( $product, $choice );
		}
		$fresh = null === $product ? null : wc_get_product( $product->get_id() );
		return $this->state->read( $fresh instanceof WC_Product ? $fresh : null ) + array( 'changed' => VariationPicturesState::described( $cost ) );
	}
}
