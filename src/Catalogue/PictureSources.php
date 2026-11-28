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
 * the template's own preview when it has no blank. A variable product's is
 * drawn as the variation it stands for (`PicturedVariation`): that
 * variation's template, blank and variant, so it never shows a variant the
 * product does not sell. With showcase sets chosen (`ShowcaseImages`), there
 * is one picture per set, each drawn with its set, on the photo or flat as
 * chosen; a variation has one, with the first set, and one on another
 * template than its product's, with that template's set
 * (`TemplateShowcases`). The store manager may choose the view and photo of
 * each, or of the picture drawn without a set (`PictureView`); a variation
 * may choose its own, else it follows its product's for its image. A choice
 * the blank no longer has is drawn as without one. A variation of a product
 * listed as cards (`SplitCards`) drawn on a photo has a second picture,
 * flat, drawn with the same set: the picture of its design a card in the
 * shop shows (`CardPages::image()`). One drawn flat that a filtered card
 * wants on its blank's photo (`CardPhotos`) has a second picture there,
 * drawn with the same set. With a picture for every variation
 * (`VariationPictures`), a variation whose image would be drawn flat is
 * drawn on its blank's photo instead, with the same set. A product's
 * gallery then shows the other sides chosen for it (`SidePictures`), each
 * with its own template and values.
 */
final class PictureSources {

	private readonly TemplateShowcases $showcases;

	/**
	 * @param Catalogue      $catalogue Reads the blank's views.
	 * @param CatalogueCache $cache     Keeps the blank's views between jobs.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
	) {
		$this->showcases = new TemplateShowcases( $catalogue, $cache );
	}

	/**
	 * What each of the product's pictures is drawn from, its image's first,
	 * and for a variation of a product listed as cards drawn on a photo its
	 * flat picture second, or for one drawn flat that a filtered card wants
	 * on its blank's photo that picture second (`card_photo`):
	 * the template, with the blank, placement, view, variant and the photo
	 * chosen when it is drawn on a photo, and the showcase set it is drawn
	 * with. Empty when the product shows none.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return list<array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string, showcase?: string, card_photo?: true, value_set?: string}>
	 *
	 * @throws ApiException When the blank's views or the template's sets cannot be read.
	 */
	public function of( WC_Product $product ): array {
		$chooser = $product;
		// A variable product is drawn as one of the variations it sells.
		$product = PicturedVariation::of( $chooser ) ?? $chooser;
		$images  = ShowcaseImages::on( $chooser );
		// A variation has one image, and no gallery.
		$images  = $chooser->is_type( 'variation' ) ? array_slice( $images, 0, 1 ) : $images;
		$images  = $this->showcases->drawn_on( $product, $images );
		$own     = $chooser->is_type( 'variation' ) ? PictureView::on( $chooser ) : null;
		$choices = array() === $images ? array( self::followed( $chooser ) ?? array( 'kind' => PictureView::MOCKUP ) ) : $images;
		// Flat pictures alone need nothing of the blank, so its views are not read for them.
		$on_photo = null !== $own || in_array( PictureView::MOCKUP, array_column( $choices, 'kind' ), true );
		$source   = $this->source( $product, $on_photo );
		if ( null === $source ) {
			return array();
		}
		$sources = array();
		foreach ( $choices as $choice ) {
			$drawn     = $this->chosen( $product, $source, $own ?? $choice, null !== $own || ! $chooser->is_type( 'variation' ) );
			$sources[] = $drawn + ( isset( $choice['id'] ) ? array( 'showcase' => $choice['id'] ) : array() );
		}
		if ( ! isset( $sources[0]['blank'] ) && VariationPictures::draws( $chooser ) ) {
			$photo = $this->source( $product, true );
			if ( isset( $photo['blank'] ) ) {
				// Its own picture on its blank's photo, in place of the flat one its product's variations share.
				$sources[0] = $photo + ( isset( $sources[0]['showcase'] ) ? array( 'showcase' => $sources[0]['showcase'] ) : array() );
			}
		}
		if ( ProductPictures::draws_flat( $chooser ) && isset( $sources[0]['blank'] ) ) {
			// The flat picture of its design, which a card shows.
			$sources[] = array( 'template' => $sources[0]['template'] ) + ( isset( $sources[0]['showcase'] ) ? array( 'showcase' => $sources[0]['showcase'] ) : array() );
		}
		$photo = ! isset( $sources[0]['blank'] ) && ProductPictures::draws_flat( $chooser ) && CardPhotos::wanted( $chooser ) ? $this->source( $product, true ) : null;
		if ( isset( $photo['blank'] ) ) {
			// Its image is flat: the picture on its blank's photo a filtered card shows.
			$sources[] = $photo + ( isset( $sources[0]['showcase'] ) ? array( 'showcase' => $sources[0]['showcase'] ) : array() ) + array( 'card_photo' => true );
		}
		$sources = self::with_value_set( $product, $sources );
		if ( ! $chooser->is_type( 'variation' ) ) {
			$sources = array_merge( $sources, $this->of_sides( $product, SidePictures::on( $chooser ) ) );
		}
		return array_slice( $sources, 0, ShowcaseImages::MAX_IMAGES );
	}

	/**
	 * What the pictures of the product's other sides are drawn from, each
	 * with the value set of its side; a side the product no longer has, or
	 * one with no template, draws none.
	 *
	 * @param WC_Product                              $product The product, or the variation a variable product is drawn as.
	 * @param list<array{side: string, kind: string}> $chosen  The sides chosen (`SidePictures`).
	 *
	 * @return list<array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, value_set?: string}>
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	private function of_sides( WC_Product $product, array $chosen ): array {
		if ( array() === $chosen ) {
			return array();
		}
		$by_key = array();
		foreach ( ProductConfig::config_for( $product )->sides as $side ) {
			$by_key[ $side->key ] ??= $side;
		}
		$sources = array();
		foreach ( $chosen as $choice ) {
			$side = $by_key[ $choice['side'] ] ?? null;
			if ( null === $side ) {
				continue;
			}
			$source = $this->source( $product, PictureView::MOCKUP === $choice['kind'], $side );
			if ( null === $source ) {
				continue;
			}
			$set       = SideValues::set_of( $product, $side );
			$sources[] = $source + ( null === $set ? array() : array( 'value_set' => $set ) );
		}
		return $sources;
	}

	/**
	 * The sources, each with the value set of the side it draws: the
	 * variation's own when it has a setup of its own, else its product's
	 * (`SideValues`). Only with one, so a product without values keeps the
	 * pictures it has; a picture of another set is another picture, and is
	 * never shared with one of this set.
	 *
	 * @param WC_Product $product The product or variation drawn.
	 * @param array      $sources What each picture is drawn from.
	 * @phpstan-param list<array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string, showcase?: string, card_photo?: true}> $sources
	 *
	 * @return list<array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null, photo?: string, showcase?: string, card_photo?: true, value_set?: string}>
	 */
	private static function with_value_set( WC_Product $product, array $sources ): array {
		$side = ProductConfig::config_for( $product )->sides[0] ?? null;
		$set  = null === $side ? null : SideValues::set_of( $product, $side );
		if ( null === $set ) {
			return $sources;
		}
		return array_map( static fn ( array $source ): array => $source + array( 'value_set' => $set ), $sources );
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
	 * @param WC_Product                                                                                        $product A product or variation, or the variation a variable product is drawn as.
	 * @param array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null} $source  What it is drawn from without a choice.
	 * @param array{kind: string, view?: string, photo?: string}                                                $choice  The choice.
	 * @param bool                                                                                              $own     Whether the choice is the picture's own, rather than one it follows.
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
	 * What the picture of one of the product's sides is drawn from with the
	 * template's defaults: its first side's unless another is given; null
	 * when the product shows none.
	 *
	 * @param WC_Product $product  A product or variation, or the variation a variable product is drawn as.
	 * @param bool       $on_photo Whether it is drawn on the blank's photo when it can be; false for the template alone.
	 * @param Side|null  $side     The side drawn; null for its first.
	 *
	 * @return array{template: string, blank?: string, placement?: string, view?: string, variant?: string|null}|null
	 *
	 * @throws ApiException When the blank's views cannot be read.
	 */
	private function source( WC_Product $product, bool $on_photo, ?Side $side = null ): ?array {
		$config = ProductConfig::config_for( $product );
		$side ??= $config->sides[0] ?? null;
		if ( ! $config->personalise || null === $side || '' === $side->template_base_id || null === $side->template_version ) {
			return null;
		}
		$template = (string) new TemplateId( $side->template_base_id, $side->template_version );
		if ( ! $on_photo || null === $config->blank_id || null === $side->placement_id ) {
			return array( 'template' => $template );
		}
		// A variable product selling no variation stands for none: it has no variant.
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
