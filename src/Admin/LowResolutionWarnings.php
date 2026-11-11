<?php
/**
 * Warnings about images a print size makes print below their recommended resolution.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Api\Answers\LowResolutionImage;
use Pixonaut\WooCommerce\Catalogue\LowResolutionSize;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;

/**
 * In a product's or variation's Pixonaut fields, a row for each image of a
 * side's template that prints below its recommended resolution at one of
 * the side's sizes, as Pixonaut last judged them: which image, at what
 * resolution, at which size. The store manager can accept it at that size,
 * stop warnings about the image or about every image, or open the picture
 * in the Pixonaut studio to upscale it; each is saved with the product.
 * Pixonaut drops an image from its answer once the template uses a picture
 * sharp enough, or the picture is accepted in the studio, so the row goes
 * with the next check. A size Pixonaut refused for its images always shows
 * them, with nothing to accept: only Pixonaut can sell it.
 */
final class LowResolutionWarnings {

	public const META_KEY = Schema::LOW_RESOLUTION_ACCEPTED_META;

	/**
	 * A row's choice: accept the image at this size.
	 */
	public const ACCEPT = 'accept';

	/**
	 * A row's choice: no more warnings about this image, on any product.
	 */
	public const QUIET_IMAGE = 'image';

	/**
	 * A row's choice: no more warnings about any image.
	 */
	public const QUIET_ALL = 'all';

	/**
	 * Resolutions are shown as whole dots per inch.
	 */
	private const DPI_DECIMALS = 0;

	/**
	 * @param Settings  $settings  Whether to warn, and the images not warned about.
	 * @param Templates $templates Renders the rows.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Templates $templates,
	) {
	}

	/**
	 * The rows of a side's sizes, rendered; empty when none is shown.
	 *
	 * @param WC_Product          $product The product or variation the rows are saved on.
	 * @param string              $name    The posted name of the rows' choices, each under its key.
	 * @param string              $side    The side's key.
	 * @param string|null         $label   The side's name, put before each row; null on a product of one side.
	 * @param LowResolutionSize[] $sizes   The side's sizes Pixonaut judged images too soft at.
	 * @phpstan-param list<LowResolutionSize> $sizes
	 */
	public function html( WC_Product $product, string $name, string $side, ?string $label, array $sizes ): string {
		$rows = $this->rows( $product, $name, $side, $label, $sizes );
		return array() === $rows ? '' : $this->templates->html( 'admin/low-resolution-warnings.php', array( 'rows' => $rows ) );
	}

	/**
	 * The rows shown for a side's sizes: every image at a size Pixonaut
	 * refused, and, while warnings are on, every other image not accepted
	 * at its size nor quieted.
	 *
	 * @param WC_Product          $product The product or variation the rows are saved on.
	 * @param string              $name    The posted name of the rows' choices, each under its key.
	 * @param string              $side    The side's key.
	 * @param string|null         $label   The side's name, put before each row; null on a product of one side.
	 * @param LowResolutionSize[] $sizes   The side's sizes Pixonaut judged images too soft at.
	 * @phpstan-param list<LowResolutionSize> $sizes
	 * @return list<array{key: string, name: string, text: string, refused: bool, studio: string}>
	 */
	public function rows( WC_Product $product, string $name, string $side, ?string $label, array $sizes ): array {
		$warn     = true === $this->settings->get( Settings::LOW_RESOLUTION_WARNINGS );
		$quiet    = $this->settings->quiet_images();
		$accepted = self::accepted_on( $product );
		$rows     = array();
		foreach ( $sizes as $size ) {
			foreach ( self::softest( $size->images ) as $image ) {
				$key = LowResolutionSize::key( $side, $size->size, $image );
				if ( ! $size->refused && ( ! $warn || in_array( $image->asset_id, $quiet, true ) || in_array( $key, $accepted, true ) ) ) {
					continue;
				}
				$text   = self::text( $image, $size->size, $size->refused );
				$rows[] = array(
					'key'     => $key,
					'name'    => $name . '[' . $key . ']',
					'text'    => null === $label ? $text : sprintf(
						/* translators: 1: a product side's name, e.g. "Front", 2: what is wrong with an image on it. Admin product page. */
						__( '%1$s: %2$s', 'pixonaut-for-woocommerce' ),
						$label,
						$text
					),
					'refused' => $size->refused,
					'studio'  => StudioPage::asset( $image->asset_id ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Saves what the store manager chose on a product's or variation's rows.
	 * Accepts are kept only for images still warned of now, so one whose
	 * side, size or picture changed warns again.
	 *
	 * @param WC_Product $product The product or variation, as stored now.
	 * @param mixed      $posted  The rows' choices, by key, unslashed.
	 */
	public function save( WC_Product $product, mixed $posted ): void {
		$current  = self::current_keys( $product );
		$accepted = array_values( array_intersect( self::accepted_on( $product ), $current ) );
		$quiet    = $this->settings->quiet_images();
		foreach ( is_array( $posted ) ? $posted : array() as $key => $choice ) {
			$key = sanitize_text_field( (string) $key );
			if ( ! in_array( $key, $current, true ) || ! is_string( $choice ) ) {
				continue;
			}
			switch ( sanitize_key( $choice ) ) {
				case self::ACCEPT:
					$accepted[] = $key;
					break;
				case self::QUIET_IMAGE:
					$quiet[] = self::asset_of( $key );
					break;
				case self::QUIET_ALL:
					$this->settings->set( Settings::LOW_RESOLUTION_WARNINGS, false );
					break;
			}
		}
		self::store_accepted( $product, array_values( array_unique( $accepted ) ) );
		$quiet = array_values( array_unique( $quiet ) );
		if ( $quiet !== $this->settings->quiet_images() ) {
			$this->settings->set( Settings::LOW_RESOLUTION_QUIET_IMAGES, $quiet );
		}
	}

	/**
	 * Drops the accepts of images a product or variation no longer warns
	 * of, once a check stored new verdicts on it, so an image that warns
	 * again later is shown again.
	 *
	 * @param WC_Product $product The product or variation, as stored now.
	 */
	public static function prune( WC_Product $product ): void {
		$accepted = self::accepted_on( $product );
		$kept     = array_values( array_intersect( $accepted, self::current_keys( $product ) ) );
		if ( $kept !== $accepted ) {
			self::store_accepted( $product, $kept );
		}
	}

	/**
	 * The keys a product or variation warns of as stored: an accept can be
	 * kept for these only.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return list<string>
	 */
	public static function current_keys( WC_Product $product ): array {
		$config = ProductConfig::stored_on( $product );
		if ( ! $product->is_type( 'variation' ) ) {
			return self::keys( $config );
		}
		// A variation that follows its product shows the product's sides, whose warnings are the product's.
		return self::keys( $config->override ? $config : $config->with_sides( array() ), VariationSize::stored_on( $product ) );
	}

	/**
	 * The keys of every image a configuration's sides, and a variation's
	 * own or attribute value's sizes, warn of: those an accept can be kept for.
	 *
	 * @param ProductConfig      $config Its configuration as stored.
	 * @param VariationSize|null $sizes  A variation's sizes; null for a product.
	 * @return list<string>
	 */
	public static function keys( ProductConfig $config, ?VariationSize $sizes = null ): array {
		$keys = array();
		foreach ( $config->sides as $side ) {
			foreach ( $side->low_resolution as $size ) {
				foreach ( $size->images as $image ) {
					$keys[] = LowResolutionSize::key( $side->key, $size->size, $image );
				}
			}
		}
		foreach ( null === $sizes ? array() : $sizes->sizes as $side => $entry ) {
			foreach ( $entry['soft'] ?? array() as $image ) {
				$keys[] = LowResolutionSize::key( (string) $side, $entry['size'], $image );
			}
		}
		return $keys;
	}

	/**
	 * The keys accepted on a product or variation.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return list<string>
	 */
	public static function accepted_on( WC_Product $product ): array {
		$stored = $product->get_meta( self::META_KEY, true );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * What a row says of one image at one size.
	 *
	 * @param LowResolutionImage $image   The image.
	 * @param PrintSize          $size    The size.
	 * @param bool               $refused Whether Pixonaut refused the size for it.
	 */
	public static function text( LowResolutionImage $image, PrintSize $size, bool $refused ): string {
		$dpi         = number_format_i18n( $image->effective_dpi, self::DPI_DECIMALS );
		$recommended = number_format_i18n( $image->recommended_dpi, self::DPI_DECIMALS );
		return $refused
			? sprintf(
				/* translators: 1: the template layer the image fills, e.g. "photo", 2: its resolution in dots per inch at the size, e.g. "122", 3: the print size, e.g. "1000 × 1000 mm", 4: the resolution Pixonaut recommends, e.g. "300". Admin product page. */
				__( 'The image in “%1$s” prints at %2$s dpi at %3$s, below the %4$s dpi recommended, so Pixonaut does not sell this size.', 'pixonaut-for-woocommerce' ),
				$image->layer_id,
				$dpi,
				$size->described(),
				$recommended
			)
			: sprintf(
				/* translators: 1: the template layer the image fills, e.g. "photo", 2: its resolution in dots per inch at the size, e.g. "122", 3: the print size, e.g. "1000 × 1000 mm", 4: the resolution Pixonaut recommends, e.g. "300". Admin product page. */
				__( 'The image in “%1$s” prints at %2$s dpi at %3$s, below the %4$s dpi recommended, so it may look soft.', 'pixonaut-for-woocommerce' ),
				$image->layer_id,
				$dpi,
				$size->described(),
				$recommended
			);
	}

	/**
	 * One image per picture of a size, for a row each: a picture filling
	 * several layers is one choice, named by the layer it prints softest in.
	 *
	 * @param LowResolutionImage[] $images The images at one size.
	 * @phpstan-param list<LowResolutionImage> $images
	 * @return list<LowResolutionImage>
	 */
	private static function softest( array $images ): array {
		$by_asset = array();
		foreach ( $images as $image ) {
			$kept = $by_asset[ $image->asset_id ] ?? null;
			if ( null === $kept || $image->effective_dpi < $kept->effective_dpi ) {
				$by_asset[ $image->asset_id ] = $image;
			}
		}
		return array_values( $by_asset );
	}

	/**
	 * Stores the keys accepted on a product or variation; none removes the meta.
	 *
	 * @param WC_Product $product  The product or variation.
	 * @param string[]   $accepted The keys accepted.
	 * @phpstan-param list<string> $accepted
	 */
	private static function store_accepted( WC_Product $product, array $accepted ): void {
		if ( array() === $accepted ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, $accepted );
		}
		$product->save_meta_data();
	}

	/**
	 * The picture a key names: its last part.
	 *
	 * @param string $key A key from `LowResolutionSize::key()`.
	 */
	private static function asset_of( string $key ): string {
		$parts = explode( '|', $key );
		return (string) end( $parts );
	}
}
