<?php
/**
 * What turning a picture for every variation on or off does to the shop's
 * storage.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * Before a picture is drawn for every variation (`VariationPictures`), the
 * store manager sees what it costs: how many new pictures it draws, times
 * the average space of the Pixonaut pictures the shop keeps already
 * (`FALLBACK_BYTES` while it keeps none), against the free space where the
 * shop keeps its media (`MediaSpace`). Past `WARN_PERCENT` of what is free,
 * or when that cannot be read, turning it on needs a confirmation.
 * Turning it off says how much space the variation pictures nothing else
 * uses free.
 *
 * A new picture is one per template and variant among the variations
 * whose image is drawn flat now, as their product's showcase set or
 * picture view chooses, counted whether product pictures are on yet or
 * not: variations of one variant on one template (sizes of a poster)
 * share one picture.
 */
final class VariationPictureCost {

	/**
	 * The share of the shop's free media space, in percent, above which
	 * turning it on warns and needs a confirmation.
	 */
	public const WARN_PERCENT = 10;

	/**
	 * The space a picture is taken to need while the shop keeps none: a
	 * 1200 px PNG and the image sizes WordPress makes from it.
	 */
	public const FALLBACK_BYTES = 750 * self::BYTES_PER_KB;

	/**
	 * WordPress's `KB_IN_BYTES`, which a class constant cannot use: the unit suite loads no WordPress.
	 */
	private const BYTES_PER_KB = 1024;

	/**
	 * Products read per query when the store-wide setting changes.
	 */
	private const PAGE = 100;

	/**
	 * Percent, for the share of free space.
	 */
	private const PERCENT = 100;

	/**
	 * @param Settings       $settings The store's settings.
	 * @param PictureStorage $storage  The pictures the shop keeps.
	 * @param MediaSpace     $space    The shop's free media space.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly PictureStorage $storage,
		private readonly MediaSpace $space,
	) {
	}

	/**
	 * What turning it on for the products costs.
	 *
	 * @param WC_Product[] $products Variable products it would turn on for.
	 * @phpstan-param list<WC_Product> $products
	 *
	 * @return array{pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool}
	 */
	public function turning_on( array $products ): array {
		$pictures = 0;
		foreach ( $products as $product ) {
			$pictures += $this->new_pictures( $product );
		}
		$bytes   = $pictures * ( $this->storage->average_bytes() ?? self::FALLBACK_BYTES );
		$free    = 0 === $pictures ? null : $this->space->free();
		$percent = null === $free ? null : ( 0 === $free ? self::PERCENT : (int) ceil( $bytes * self::PERCENT / $free ) );
		return array(
			'pictures' => $pictures,
			'bytes'    => $bytes,
			'free'     => $free,
			'percent'  => $percent,
			'warn'     => $pictures > 0 && ( null === $percent || $percent > self::WARN_PERCENT ),
		);
	}

	/**
	 * What turning it off for the products frees: the pictures their
	 * variations show for it that nothing else does.
	 *
	 * @param WC_Product[] $products Variable products it would turn off for.
	 * @phpstan-param list<WC_Product> $products
	 *
	 * @return array{pictures: int, bytes: int}
	 */
	public function turning_off( array $products ): array {
		$families = $this->storage->families();
		$freed    = array();
		foreach ( $products as $product ) {
			if ( ! self::image_drawn_flat( $product ) ) {
				// Drawn on the photo either way: turning it off changes no picture.
				continue;
			}
			$pictures = $families[ $product->get_id() ] ?? array();
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				// A filtered card's photo is drawn again beside the flat image: it frees nothing.
				if ( ! $variation instanceof WC_Product || ! VariationPictures::draws( $variation, $this->settings ) || ( CardPhotos::wanted( $variation ) && ProductPictures::draws_flat( $variation ) ) ) {
					continue;
				}
				$attachment = ProductPictures::stored( $variation )['attachment'];
				if ( isset( $pictures[ $attachment ] ) && $pictures[ $attachment ]['variation'] ) {
					$freed[ $attachment ] = $pictures[ $attachment ]['bytes'];
				}
			}
		}
		return array(
			'pictures' => count( $freed ),
			'bytes'    => array_sum( $freed ),
		);
	}

	/**
	 * The variable products whose variations the store-wide setting decides:
	 * those that chose nothing of their own.
	 *
	 * @return list<WC_Product>
	 */
	public function following_store_wide(): array {
		return $this->variable_products( VariationPictures::DEFAULT );
	}

	/**
	 * The configured variable products, with that choice of their own when given.
	 *
	 * @param string|null $choice DEFAULT, ON or OFF; null for any.
	 * @return list<WC_Product>
	 */
	private function variable_products( ?string $choice ): array {
		$products = array();
		$after    = 0;
		do {
			$ids = ProductIndex::configured( $after, self::PAGE );
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( $product instanceof WC_Product && $product->is_type( 'variable' ) && ( null === $choice || VariationPictures::choice( $product ) === $choice ) ) {
					$products[] = $product;
				}
			}
			$read  = count( $ids );
			$after = 0 === $read ? $after : $ids[ $read - 1 ];
		} while ( self::PAGE === $read );
		return $products;
	}

	/**
	 * The variable products whose variations would each get a picture with
	 * the store-wide setting as given: their own choice, else that setting.
	 * What turning product pictures on draws for variations.
	 *
	 * @param bool $store_wide The store-wide setting.
	 * @return list<WC_Product>
	 */
	public function resolving_on( bool $store_wide ): array {
		return array_values(
			array_filter(
				$this->variable_products( null ),
				static fn ( WC_Product $product ): bool => VariationPictures::resolves( VariationPictures::choice( $product ), $store_wide )
			)
		);
	}

	/**
	 * What changing to a setting costs or frees: the products it turns on
	 * for and those it turns off for.
	 *
	 * @param WC_Product[] $products Variable products whose variations it decides.
	 * @phpstan-param list<WC_Product> $products
	 * @param bool         $on       Whether it would be on for them.
	 *
	 * @return array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool}
	 */
	public function changing_to( array $products, bool $on ): array {
		$changed = array_values( array_filter( $products, fn ( WC_Product $product ): bool => VariationPictures::on_for( $product, $this->settings ) !== $on ) );
		if ( $on ) {
			return array( 'on' => true ) + $this->turning_on( $changed );
		}
		return array(
			'on'      => false,
			'free'    => null,
			'percent' => null,
			'warn'    => false,
		) + $this->turning_off( $changed );
	}

	/**
	 * What the store manager reads before saving: the estimate, and where
	 * the pictures are kept.
	 *
	 * @param array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool} $cost From `changing_to()`.
	 */
	public static function summary( array $cost ): string {
		if ( ! $cost['on'] ) {
			if ( 0 === $cost['pictures'] ) {
				return __( 'No variation pictures are deleted: no variation has one only it shows.', 'pixonaut-for-woocommerce' );
			}
			return sprintf(
				/* translators: 1: a number of pictures, 2: an amount of disk space, e.g. 310 MB. */
				_n( 'Deletes about %1$s variation picture nothing else uses, freeing about %2$s of your shop\'s storage.', 'Deletes about %1$s variation pictures nothing else uses, freeing about %2$s of your shop\'s storage.', $cost['pictures'], 'pixonaut-for-woocommerce' ),
				number_format_i18n( $cost['pictures'] ),
				size_format( $cost['bytes'] )
			);
		}
		if ( 0 === $cost['pictures'] ) {
			return __( 'No new pictures: no variation is drawn flat now.', 'pixonaut-for-woocommerce' );
		}
		return sprintf(
			/* translators: 1: a number of pictures, 2: an amount of disk space, e.g. 310 MB. */
			_n( 'About %1$s new picture, about %2$s. Pictures are stored in your shop\'s media library, on your shop\'s own server, not in Pixonaut.', 'About %1$s new pictures, about %2$s. Pictures are stored in your shop\'s media library, on your shop\'s own server, not in Pixonaut.', $cost['pictures'], 'pixonaut-for-woocommerce' ),
			number_format_i18n( $cost['pictures'] ),
			size_format( $cost['bytes'] )
		);
	}

	/**
	 * The warning that needs a confirmation; null when none is needed.
	 *
	 * @param array{on: bool, pictures: int, bytes: int, free: int|null, percent: int|null, warn: bool} $cost From `changing_to()`.
	 */
	public static function warning( array $cost ): ?string {
		if ( ! $cost['warn'] ) {
			return null;
		}
		if ( null === $cost['percent'] ) {
			return __( 'Your shop\'s free storage could not be read, so how much of it this will use is unknown. Check the space your host gives you before you turn it on.', 'pixonaut-for-woocommerce' );
		}
		return sprintf(
			/* translators: %d: a percentage of the shop's free disk space. */
			__( 'This will use about %d%% of your shop\'s remaining storage for product pictures.', 'pixonaut-for-woocommerce' ),
			min( self::PERCENT, $cost['percent'] )
		);
	}

	/**
	 * The new pictures turning it on draws for the product's variations: one
	 * per variant among those drawn flat now.
	 *
	 * @param WC_Product $product A variable product.
	 */
	private function new_pictures( WC_Product $product ): int {
		if ( ! $product->is_type( 'variable' ) || ! self::image_drawn_flat( $product ) ) {
			return 0;
		}
		$showcase = ShowcaseImages::on( $product )[0]['id'] ?? '';
		$sources  = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			// Whether product pictures are on now or not: turning them on later draws these too.
			if ( ! $variation instanceof WC_Product || null !== PictureView::on( $variation ) || null === ProductPictures::basis( $variation ) ) {
				continue;
			}
			$config = ProductConfig::config_for( $variation );
			$side   = $config->sides[0] ?? null;
			if ( ! $config->personalise || null === $config->blank_id || null === $side ) {
				// Not drawn, or nothing to draw it on.
				continue;
			}
			// One picture per what it is drawn from, as pictures are shared (`SharedPictures`).
			$sources[ implode( ' ', array( $side->template_base_id, (string) $side->template_version, $config->blank_id, ProductVariant::sold_by( $variation, $config ) ?? 'variation-' . $variation->get_id(), $showcase ) ) ] = true;
		}
		return count( $sources );
	}

	/**
	 * Whether the product's variations follow a choice that draws their
	 * image flat: its first showcase set, else its picture view.
	 *
	 * @param WC_Product $product A variable product.
	 */
	private static function image_drawn_flat( WC_Product $product ): bool {
		$images = ShowcaseImages::on( $product );
		$choice = array() === $images ? PictureView::on( $product ) : $images[0];
		return PictureView::PREVIEW === ( $choice['kind'] ?? PictureView::MOCKUP );
	}
}
