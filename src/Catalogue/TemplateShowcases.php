<?php
/**
 * The showcase sets of a template, and the ones a picture of it is drawn with.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use WC_Product;

/**
 * A product's showcase sets (`ShowcaseImages`) are chosen from its first
 * side's template. A variation set up on another template has none of
 * them: its picture is drawn with that template's set of the same name,
 * else with its first set, so it shows example values as its product does
 * rather than the bare template. A template with no set leaves the choice
 * as it is, and Pixonaut then draws its defaults.
 */
final class TemplateShowcases {

	/**
	 * @param Catalogue      $catalogue Lists a template's showcase sets.
	 * @param CatalogueCache $cache     Keeps the list for a few minutes.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
	) {
	}

	/**
	 * The template's showcase sets and preview, as Pixonaut lists them.
	 *
	 * @param string $template The template and version.
	 *
	 * @return array<int|string, mixed> `showcases` and `preview_url`, as `Catalogue::template_showcases()` gives them.
	 *
	 * @throws ApiException When Pixonaut could not list them.
	 */
	public function listed( string $template ): array {
		return $this->cache->remember( 'template-showcases', array( 'id' => $template ), fn (): array => $this->catalogue->template_showcases( $template ) );
	}

	/**
	 * The template a product or variation is drawn on when it is another
	 * than the one its showcase sets were chosen from: a variation's own,
	 * with its version; null when it is the same, or it has none.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function other_template( WC_Product $product ): ?string {
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		if ( ! $parent instanceof WC_Product ) {
			return null;
		}
		$chosen_from = ProductConfig::stored_on( $parent )->sides[0] ?? null;
		$side        = ProductConfig::inherited_by( $parent, $product )->sides[0] ?? null;
		if ( null === $side || '' === $side->template_base_id || null === $side->template_version || $side->template_base_id === $chosen_from?->template_base_id ) {
			return null;
		}
		return (string) new TemplateId( $side->template_base_id, $side->template_version );
	}

	/**
	 * The choices as the template a product is drawn on has them: each
	 * with that template's set, each drawing once. Unchanged when it is drawn on the
	 * template they were chosen from.
	 *
	 * @param WC_Product                                                                         $product A product or variation.
	 * @param list<array{id: string, name: string, kind: string, view?: string, photo?: string}> $images  The product's choices.
	 *
	 * @return list<array{id: string, name: string, kind: string, view?: string, photo?: string}>
	 *
	 * @throws ApiException When Pixonaut could not list the template's sets.
	 */
	public function drawn_on( WC_Product $product, array $images ): array {
		$template = array() === $images ? null : self::other_template( $product );
		if ( null === $template ) {
			return $images;
		}
		$sets = ShowcaseImages::sets( $this->listed( $template )['showcases'] ?? array() );
		if ( array() === $sets ) {
			return $images;
		}
		$drawn = array();
		foreach ( $images as $index => $image ) {
			$set = self::matching( $sets, $image ) ?? ( 0 === $index ? $sets[0] : null );
			if ( null === $set ) {
				continue;
			}
			$choice = array(
				'id'   => $set['id'],
				'name' => $set['name'],
			) + $image;
			$key    = ShowcaseImages::key( $choice );
			if ( ! isset( $drawn[ $key ] ) ) {
				$drawn[ $key ] = $choice;
			}
		}
		return array_values( $drawn );
	}

	/**
	 * The set of the same id, else of the same name, case and spacing aside.
	 *
	 * @param list<array{id: string, name: string, kind: string, view?: string, photo?: string}> $sets  A template's sets.
	 * @param array{id: string, name: string, kind: string, view?: string, photo?: string}       $image A choice.
	 *
	 * @return array{id: string, name: string, kind: string, view?: string, photo?: string}|null
	 */
	private static function matching( array $sets, array $image ): ?array {
		$name = ProductVariant::normalise( $image['name'] );
		foreach ( array( 'id', 'name' ) as $by ) {
			foreach ( $sets as $set ) {
				if ( 'id' === $by ? $set['id'] === $image['id'] : ProductVariant::normalise( $set['name'] ) === $name ) {
					return $set;
				}
			}
		}
		return null;
	}
}
