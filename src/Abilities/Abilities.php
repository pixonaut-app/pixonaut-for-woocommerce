<?php
/**
 * The plugin's abilities on the WordPress Abilities API.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Plugin;

/**
 * Registers the plugin's abilities, under `pixonaut-for-woocommerce/`, in
 * one category, so the MCP Adapter, WooCommerce's MCP or an assistant in the
 * admin can act on Pixonaut through the store, and the ones that manage the
 * store's own things in a second. Each is shown in the REST API and marked
 * public for MCP; each checks the user's capability itself.
 */
final class Abilities {

	/**
	 * The namespace every ability's name starts with.
	 */
	public const NAMESPACE = Plugin::SLUG;

	/**
	 * What the MCP Adapter puts between an ability's namespace and its name
	 * in a tool's name, for the `/` a tool's name cannot have.
	 */
	public const TOOL_SEPARATOR = '-';

	/**
	 * The category the abilities are listed under.
	 */
	public const CATEGORY = 'pixonaut';

	/**
	 * The category of the abilities that manage the store's own things
	 * (`Store\StoreResources`), which an ability names in its definition.
	 */
	public const STORE_CATEGORY = 'pixonaut-store';

	/**
	 * @param Closure $abilities Makes the abilities to register, once the Abilities API asks for them.
	 * @phpstan-param Closure(): list<Ability> $abilities
	 */
	public function __construct( private readonly Closure $abilities ) {
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				/* translators: Name of a group of abilities an AI assistant or an MCP client can use on the store. */
				'label'       => __( 'Pixonaut', 'pixonaut-for-woocommerce' ),
				'description' => __( 'Personalised products and orders in Pixonaut: templates, product links, proofs and production.', 'pixonaut-for-woocommerce' ),
			)
		);
		wp_register_ability_category(
			self::STORE_CATEGORY,
			array(
				/* translators: Name of a group of abilities an AI assistant or an MCP client can use on the store. */
				'label'       => __( 'Store management', 'pixonaut-for-woocommerce' ),
				'description' => __( 'The store\'s variations, product categories, tags, brands and attributes, pages, posts, menus, media, coupons, shipping, orders and settings, managed through its REST API as the user who asks.', 'pixonaut-for-woocommerce' ),
			)
		);
	}

	public function register_abilities(): void {
		foreach ( ( $this->abilities )() as $ability ) {
			$definition  = $ability->definition();
			$annotations = $definition['annotations'] ?? array();
			unset( $definition['annotations'] );
			wp_register_ability(
				self::name( $ability ),
				$definition + array(
					'category' => self::CATEGORY,
					'meta'     => array(
						'annotations'  => $annotations,
						'show_in_rest' => true,
						'public'       => true,
						// Read by the MCP Adapter's default server, which lists only public abilities.
						'mcp'          => array( 'public' => true ),
					),
				)
			);
		}
	}

	/**
	 * A whole number from an ability's input. The REST API passes a GET or
	 * DELETE run's input as query parameters, so a number the schema accepts
	 * may arrive as a string of digits.
	 *
	 * @param mixed  $input    The ability's input.
	 * @param string $key      The field.
	 * @param int    $fallback What a missing or unusable field reads as.
	 */
	public static function integer( mixed $input, string $key, int $fallback ): int {
		$value = is_array( $input ) ? ( $input[ $key ] ?? null ) : null;
		if ( is_int( $value ) ) {
			return $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ? (int) $value : $fallback;
	}

	/**
	 * The tool an MCP client calls one of the plugin's abilities by, as the
	 * MCP Adapter names it (the full name with `-` for `/`). A description
	 * names another ability this way.
	 *
	 * @param string $name The ability's name without the namespace, e.g. `list-products`.
	 */
	public static function tool( string $name ): string {
		return self::NAMESPACE . self::TOOL_SEPARATOR . $name;
	}

	/**
	 * An ability's full name.
	 *
	 * @param Ability $ability The ability.
	 * @return lowercase-string&non-falsy-string
	 */
	public static function name( Ability $ability ): string {
		return strtolower( self::NAMESPACE . '/' . $ability->name() );
	}
}
