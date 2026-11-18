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
use Pixonaut\WooCommerce\Abilities\GetProductPictureViews;
use Pixonaut\WooCommerce\Abilities\GetProductSplitListing;
use Pixonaut\WooCommerce\Abilities\LinkProduct;
use Pixonaut\WooCommerce\Abilities\ProductLinks;
use Pixonaut\WooCommerce\Abilities\RegenerateProductPictures;
use Pixonaut\WooCommerce\Abilities\SetAttributeSplitListing;
use Pixonaut\WooCommerce\Abilities\GetAttributeChoicePrices;
use Pixonaut\WooCommerce\Abilities\GetAttributeQueryNames;
use Pixonaut\WooCommerce\Abilities\GetShipping;
use Pixonaut\WooCommerce\Abilities\SetShippingMethod;
use Pixonaut\WooCommerce\Abilities\SetAttributeChoicePrice;
use Pixonaut\WooCommerce\Abilities\SetAttributeQueryName;
use Pixonaut\WooCommerce\Abilities\SetProductSetup;
use Pixonaut\WooCommerce\Abilities\SetProductShowcase;
use Pixonaut\WooCommerce\Abilities\SetProductSplitListing;
use Pixonaut\WooCommerce\Abilities\SetVariationPrintSize;
use Pixonaut\WooCommerce\Abilities\SetVariationVariants;
use Pixonaut\WooCommerce\Abilities\Store\ResourceAbility;
use Pixonaut\WooCommerce\Abilities\Store\StoreResource;
use Pixonaut\WooCommerce\Abilities\Store\StoreResources;
use Pixonaut\WooCommerce\Admin\ShowcaseField;
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
	 * @param Closure $links     Makes what links a template to a product, once a call needs it.
	 * @param Closure $variants  Gives what reads a blank's variants and stores a variation's, once a call needs it.
	 * @param Closure $showcases Makes what lists a template's showcase sets, once a call needs it.
	 * @param Closure $views     Makes the ability that reads what a product's images are drawn on, once a call needs it.
	 * @param Closure $print_size Makes the ability that sets a variation's print size, once a call needs it.
	 * @param Closure $setup      Makes the ability that sets up a product or variation, once a call needs it.
	 * @param Closure $pricing    Makes the abilities that read and change the pricing settings and a product's prices, once a call needs them.
	 * @param Closure $settings   Makes the abilities that read and change the plugin's settings and the active theme's, once a call needs them.
	 * @param Closure $form       Makes the abilities that read and arrange a product's designer form, once a call needs them.
	 * @param Closure $variations Makes the abilities that read a product's variation plan and generate its variations, once a call needs them.
	 * @param Closure $pictures   Makes the ability that draws products' pictures again, once a call needs it.
	 * @phpstan-param Closure(): ProductLinks $links
	 * @phpstan-param Closure(): ProductVariants $variants
	 * @phpstan-param Closure(): ShowcaseField $showcases
	 * @phpstan-param Closure(): GetProductPictureViews $views
	 * @phpstan-param Closure(): SetVariationPrintSize $print_size
	 * @phpstan-param Closure(): SetProductSetup $setup
	 * @phpstan-param Closure(): list<Ability> $pricing
	 * @phpstan-param Closure(): list<Ability> $settings
	 * @phpstan-param Closure(): list<Ability> $form
	 * @phpstan-param Closure(): list<Ability> $variations
	 * @phpstan-param Closure(): RegenerateProductPictures $pictures
	 */
	public function __construct(
		private readonly Closure $links,
		private readonly Closure $variants,
		private readonly Closure $showcases,
		private readonly Closure $views,
		private readonly Closure $print_size,
		private readonly Closure $setup,
		private readonly Closure $pricing,
		private readonly Closure $settings,
		private readonly Closure $form,
		private readonly Closure $variations,
		private readonly Closure $pictures,
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
		foreach ( array( new LinkProduct( $links ), new SetVariationVariants( $links, ( $this->variants )() ), new SetProductShowcase( $links, ( $this->showcases )() ), ( $this->views )(), ( $this->print_size )(), ( $this->setup )(), new GetProductSplitListing(), new SetProductSplitListing(), new SetAttributeSplitListing(), new GetAttributeQueryNames(), new SetAttributeQueryName(), new GetAttributeChoicePrices(), new SetAttributeChoicePrice(), new GetShipping(), new SetShippingMethod(), ...( $this->pricing )(), ...( $this->settings )(), ...( $this->form )(), ...( $this->variations )(), ( $this->pictures )() ) as $tool ) {
			$tools[ $tool->name() ] = $tool;
		}
		return $tools;
	}
}
