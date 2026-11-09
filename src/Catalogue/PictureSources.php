<?php
/**
 * What a product's Pixonaut pictures are drawn from.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use WC_Product;

/**
 * A product's picture is its first side's template on the first view of its
 * placement the product is shown in, from the photo of its blank variant, or
 * the template's own preview when it has no blank. With showcase sets chosen
 * (`ShowcaseImages`), there is one picture per set, each drawn with its set,
 * on the photo or flat as chosen; a variation has one, with the first set.
 * The store manager may choose the view and photo of each, or of the picture
 * drawn without a set (`PictureView`); a variation may choose its own, else
 * it follows its product's for its image. A choice the blank no longer has
 * is drawn as without one.
 */
final class PictureSources {

	/**
	 * @param Catalogue      $catalogue Reads the blank's views.
	 * @param CatalogueCache $cache     Keeps the blank's views between jobs.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
	) {
	}

	/**
	 * What each of the product's pictures is drawn from, its image's first:
	 * the template, with the blank, placement, view, variant and the photo
	 * chosen when it is drawn on a photo, and the showcase set it is drawn
	 * with. Empty when the product shows none.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return list<array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string, showcase?: string}>
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	public function of( WC_Product $product ): array {
		$images = ShowcaseImages::on( $product );
		// A variation has one image, and no gallery.
		$images  = $product->is_type( 'variation' ) ? array_slice( $images, 0, 1 ) : $images;
		$own     = $product->is_type( 'variation' ) ? PictureView::on( $product ) : null;
		$choices = array() === $images ? array( self::followed( $product ) ?? array( 'kind' => PictureView::MOCKUP ) ) : $images;
		// Flat pictures alone need nothing of the blank, so its views are not read for them.
		$on_photo = null !== $own || in_array( PictureView::MOCKUP, array_column( $choices, 'kind' ), true );
		$source   = $this->source( $product, $on_photo );
		if ( null === $source ) {
			return array();
		}
		$sources = array();
		foreach ( $choices as $choice ) {
			$drawn     = $this->chosen( $product, $source, $own ?? $choice, null !== $own || ! $product->is_type( 'variation' ) );
			$sources[] = $drawn + ( isset( $choice['id'] ) ? array( 'showcase' => $choice['id'] ) : array() );
		}
		return $sources;
	}

	/**
	 * The choice a picture drawn without a showcase set follows: the
	 * product's, a variation's product's; null for none.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return array{kind: string, view?: string, photo?: string}|null
	 */
	private static function followed( WC_Product $product ): ?array {
		$owner = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		return $owner instanceof WC_Product ? PictureView::on( $owner ) : null;
	}

	/**
	 * What a picture is drawn from under a choice: flat, on the view and
	 * photo it names while the blank has them, else as without a choice.
	 *
	 * @param WC_Product                                                                                $product A product or variation.
	 * @param array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null} $source  What it is drawn from without a choice.
	 * @param array{kind: string, view?: string, photo?: string}                                        $choice  The choice.
	 * @param bool                                                                                      $own     Whether the choice is the picture's own, rather than one it follows.
	 *
	 * @return array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string}
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	private function chosen( WC_Product $product, array $source, array $choice, bool $own ): array {
		$flat = array( 'template' => $source['template'] );
		if ( PictureView::PREVIEW === $choice['kind'] ) {
			return $flat;
		}
		if ( ! isset( $choice['view'] ) ) {
			return $source;
		}
		$config = ProductConfig::config_for( $product );
		$side   = $config->sides[0] ?? null;
		if ( null === $config->blank_id || null === $side?->placement_id ) {
			return $source;
		}
		$variant = $product->is_type( 'variable' ) ? null : ProductVariant::sold_on( $product, $config )?->ref;
		$where   = PictureView::resolve( PictureView::views_of( $this->mockups( $config->blank_id ), $side->placement_id ), $choice, $variant, $own );
		if ( null === $where ) {
			return $source;
		}
		return $flat + array(
			'blank'     => $config->blank_id,
			'placement' => $side->placement_id,
			'view'      => $where['view'],
			'variant'   => $variant,
		) + ( null === $where['photo'] ? array() : array( 'photo' => $where['photo'] ) );
	}

	/**
	 * The blank's placements with their views and photos, as Pixonaut lists them.
	 *
	 * @param string $blank_id The blank.
	 *
	 * @return array<mixed>
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	private function mockups( string $blank_id ): array {
		return $this->cache->remember( 'blank-mockups', array( 'blank' => $blank_id ), fn (): array => $this->catalogue->mockups( $blank_id ) );
	}

	/**
	 * What the product's picture is drawn from with the template's defaults;
	 * null when the product shows none.
	 *
	 * @param WC_Product $product  A product or variation.
	 * @param bool       $on_photo Whether it is drawn on the blank's photo when it can be; false for the template alone.
	 *
	 * @return array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null}|null
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	private function source( WC_Product $product, bool $on_photo ): ?array {
		$config = ProductConfig::config_for( $product );
		$side   = $config->sides[0] ?? null;
		if ( ! $config->personalise || null === $side || '' === $side->template_base_id || null === $side->template_version ) {
			return null;
		}
		$template = (string) new TemplateId( $side->template_base_id, $side->template_version );
		if ( ! $on_photo || null === $config->blank_id || null === $side->placement_id ) {
			return array( 'template' => $template );
		}
		// A variable product stands for all its variations: it has no variant.
		$variant = $product->is_type( 'variable' ) ? null : ProductVariant::sold_on( $product, $config );
		$view    = null === $variant ? self::first_shown( $config, $this->photographed_views( $config->blank_id, $side->placement_id ) ) : $variant->first_view( $side->placement_id, $config );
		if ( null === $view ) {
			return array( 'template' => $template );
		}
		return array(
			'template'  => $template,
			'blank'     => $config->blank_id,
			'placement' => $side->placement_id,
			'view'      => $view,
			'variant'   => $variant?->ref,
		);
	}

	/**
	 * The placement's views with a photo, in the blank's order.
	 *
	 * @param string $blank_id     The blank.
	 * @param string $placement_id One of its placements.
	 *
	 * @return list<string>
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	private function photographed_views( string $blank_id, string $placement_id ): array {
		$placements = $this->mockups( $blank_id );
		$views      = array();
		foreach ( $placements as $placement ) {
			$id = $placement['id'] ?? null;
			if ( $id !== $placement_id ) {
				continue;
			}
			foreach ( is_array( $placement['views'] ?? null ) ? $placement['views'] : array() as $view ) {
				if ( is_array( $view ) && is_string( $view['id'] ?? null ) && '' !== $view['id'] && array() !== ( $view['photos'] ?? array() ) ) {
					$views[] = $view['id'];
				}
			}
		}
		return $views;
	}

	/**
	 * The first of the views that the product is shown in; null for none.
	 *
	 * @param ProductConfig $config The product's configuration.
	 * @param string[]      $views  View ids, in order.
	 * @phpstan-param list<string> $views
	 */
	private static function first_shown( ProductConfig $config, array $views ): ?string {
		foreach ( $views as $view ) {
			if ( array() === $config->views || in_array( $view, $config->views, true ) ) {
				return $view;
			}
		}
		return null;
	}
}
