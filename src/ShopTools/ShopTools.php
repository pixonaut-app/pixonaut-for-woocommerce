<?php
/**
 * What the store's credential reaches.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\ShopTools;

use Closure;
use Pixonaut\WooCommerce\Abilities\Ability;
use Pixonaut\WooCommerce\Abilities\LinkProduct;
use Pixonaut\WooCommerce\Abilities\ProductLinks;
use Pixonaut\WooCommerce\Abilities\SetVariationVariants;
use Pixonaut\WooCommerce\Abilities\Store\ResourceAbility;
use Pixonaut\WooCommerce\Abilities\Store\StoreResource;
use Pixonaut\WooCommerce\Abilities\Store\StoreResources;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;

/**
 * The tools Pixonaut may call with the store's credential, and nothing else:
 * a closed list, so a resource added to the abilities for the current user
 * in wp-admin does not widen it. They are the abilities of the same names, so
 * they take and answer the same; only who is let in differs.
 */
final class ShopTools {

	/**
	 * The resources and what the credential may do with each.
	 */
	private const RESOURCES = array(
		'product'           => StoreResource::OPERATIONS,
		'product-variation' => StoreResource::OPERATIONS,
		'product-attribute' => StoreResource::OPERATIONS,
		'attribute-term'    => StoreResource::OPERATIONS,
		'product-category'  => StoreResource::OPERATIONS,
		'product-tag'       => StoreResource::OPERATIONS,
		'order'             => array( StoreResource::LIST, StoreResource::GET ),
	);

	/**
	 * @param Closure $links    Makes what links a template to a product, once a call needs it.
	 * @param Closure $variants Gives what reads a blank's variants and stores a variation's, once a call needs it.
	 * @phpstan-param Closure(): ProductLinks $links
	 * @phpstan-param Closure(): ProductVariants $variants
	 */
	public function __construct(
		private readonly Closure $links,
		private readonly Closure $variants,
	) {
	}

	/**
	 * The tools, by name.
	 *
	 * @return array<string, Ability>
	 */
	public function all(): array {
		$tools = array();
		foreach ( StoreResources::all() as $resource ) {
			foreach ( self::RESOURCES[ $resource->key ] ?? array() as $operation ) {
				$tool                   = new ResourceAbility( $resource, $operation );
				$tools[ $tool->name() ] = $tool;
			}
		}
		$links = ( $this->links )();
		foreach ( array( new LinkProduct( $links ), new SetVariationVariants( $links, ( $this->variants )() ) ) as $tool ) {
			$tools[ $tool->name() ] = $tool;
		}
		return $tools;
	}
}
