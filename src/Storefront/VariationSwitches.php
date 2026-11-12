<?php
/**
 * What the inline designer of a variable product switches between.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;

/**
 * Collects a variable product's mount options and its variations. Each side
 * mounts once, with the options of the variation the page opens on, else of
 * the first configuration that has the side, and the print sizes of every
 * configuration that has it, in the order the configurations are added; each variation names the
 * template, blank, placement, form layout, canvas and print sizes it switches a side to, by
 * index into those sizes, with the size and view the side starts on, so the storefront script switches the mounted
 * designer instead of mounting another, and the blank variant it shows the
 * product in.
 *
 * A side whose every personalised variation fixes one print size draws no
 * size menu of its own: the variation form picks it.
 *
 * A variation is `personalised`, `plain` (bought without a design) or
 * `unavailable` (personalised, but its designer cannot mount now), and
 * carries its `priceHtml`, which the product page shows while it is chosen.
 */
final class VariationSwitches {

	public const PERSONALISED = 'personalised';

	public const PLAIN = 'plain';

	public const UNAVAILABLE = 'unavailable';

	/**
	 * The mount options of each side, from the opening variation or the
	 * first configuration that has it, with the print sizes of all.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $mounts = array();

	/**
	 * The sides in the order configurations add them, by side key; the
	 * opening variation's options do not move a side.
	 *
	 * @var list<string>
	 */
	private array $order = array();

	/**
	 * Each side's name, by side key, from the first configuration that has it.
	 *
	 * @var array<string, string>
	 */
	private array $labels = array();

	/**
	 * What each variation switches to, by variation id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $variations = array();

	/**
	 * Whether every personalised variation with the side fixes its print
	 * size, by side key: false once one offers a choice of sizes.
	 *
	 * @var array<string, bool>
	 */
	private array $sized = array();

	/**
	 * Each variation's price HTML, by variation id.
	 *
	 * @var array<int, string>
	 */
	private array $prices = array();

	/**
	 * Adds a configuration's mount options: a side not seen yet mounts with
	 * them, and every side's menu gains the print sizes it lacks.
	 *
	 * @param array<string, array<string, mixed>> $mounts Mount options by side key.
	 * @param ProductConfig                       $config The configuration they mount.
	 */
	public function add_mounts( array $mounts, ProductConfig $config ): void {
		foreach ( $config->sides as $side ) {
			$this->labels[ $side->key ] ??= $side->label;
		}
		foreach ( $mounts as $key => $options ) {
			if ( ! in_array( (string) $key, $this->order, true ) ) {
				$this->order[] = (string) $key;
			}
			if ( ! isset( $this->mounts[ $key ] ) ) {
				$this->mounts[ $key ] = $options;
				continue;
			}
			foreach ( self::print_sizes( $options ) as $size ) {
				$this->size_index( $key, $size );
			}
		}
	}

	/**
	 * Mounts each side with the options of the variation the page opens on,
	 * before any configuration is added: the page prints that variation's
	 * template, form and preview, and nothing mounts again when the script
	 * finds the variation chosen. Add the variation as any other afterwards.
	 *
	 * @param array<string, array<string, mixed>> $mounts The opening variation's mount options by side key.
	 */
	public function open_on( array $mounts ): void {
		foreach ( $mounts as $key => $options ) {
			$this->mounts[ $key ] ??= $options;
		}
	}

	/**
	 * Adds a variation the designer switches to.
	 *
	 * @param int                                 $variation_id  The variation.
	 * @param array<string, string>               $attributes    Its `attribute_*` values; empty for "any".
	 * @param array<string, array<string, mixed>> $mounts        Its mount options by side key, already added.
	 * @param string                              $approval_mode Its approval mode.
	 * @param string|null                         $variant       The ref of the blank variant it sells; null for none.
	 */
	public function add_personalised( int $variation_id, array $attributes, array $mounts, string $approval_mode, ?string $variant = null ): void {
		$sides = array();
		foreach ( $mounts as $key => $options ) {
			$sizes = array_map( fn ( array $size ): int => $this->size_index( $key, $size ), self::print_sizes( $options ) );
			$start = is_int( $options['selectedPrintSizeIndex'] ?? null ) ? $options['selectedPrintSizeIndex'] : 0;
			$side  = array(
				'templateId' => $options['templateId'] ?? null,
				'printSizes' => $sizes,
			) + ( isset( $sizes[ $start ] ) ? array( 'selectedPrintSizeIndex' => $sizes[ $start ] ) : array() );
			if ( isset( $options['blankId'], $options['placementId'] ) ) {
				$side['blankId']     = $options['blankId'];
				$side['placementId'] = $options['placementId'];
				$side['placements']  = $options['placements'] ?? array();
				$side['views']       = $options['views'] ?? array();
				$side['variants']    = $options['variants'] ?? array();
				if ( isset( $options['initialView'] ) ) {
					$side['initialView'] = $options['initialView'];
				}
			}
			if ( isset( $options['layout'] ) ) {
				$side['layout'] = $options['layout'];
			}
			if ( isset( $options['formGroups'] ) ) {
				$side['formGroups'] = $options['formGroups'];
			}
			if ( is_bool( $options['drawAtTemplateRatio'] ?? null ) ) {
				$side['drawAtTemplateRatio'] = $options['drawAtTemplateRatio'];
			}
			$sides[ $key ]       = $side;
			$this->sized[ $key ] = ( $this->sized[ $key ] ?? true ) && count( $sizes ) <= 1;
		}
		$this->variations[ $variation_id ] = array(
			'state'        => self::PERSONALISED,
			'attributes'   => (object) $attributes,
			'approvalMode' => $approval_mode,
			'sides'        => (object) $sides,
		) + ( null === $variant ? array() : array( 'variant' => $variant ) );
	}

	/**
	 * Adds a variation bought without a design.
	 *
	 * @param int                   $variation_id The variation.
	 * @param array<string, string> $attributes   Its `attribute_*` values.
	 */
	public function add_plain( int $variation_id, array $attributes ): void {
		$this->variations[ $variation_id ] = array(
			'state'      => self::PLAIN,
			'attributes' => (object) $attributes,
		);
	}

	/**
	 * Adds a personalised variation whose designer cannot mount now.
	 *
	 * @param int                   $variation_id The variation.
	 * @param array<string, string> $attributes   Its `attribute_*` values.
	 */
	public function add_unavailable( int $variation_id, array $attributes ): void {
		$this->variations[ $variation_id ] = array(
			'state'      => self::UNAVAILABLE,
			'attributes' => (object) $attributes,
		);
	}

	/**
	 * Sets a variation's price, as WooCommerce formats it for the store, which
	 * the storefront script shows on the product page while the variation is
	 * chosen. An empty price is left out, and the page keeps the product's.
	 *
	 * @param int    $variation_id The variation.
	 * @param string $price_html   Its price HTML.
	 */
	public function set_price_html( int $variation_id, string $price_html ): void {
		if ( '' === $price_html ) {
			unset( $this->prices[ $variation_id ] );
			return;
		}
		$this->prices[ $variation_id ] = $price_html;
	}

	/**
	 * Mount options by side key, in the order the sides were first added. A
	 * side whose every personalised variation fixes its print size mounts
	 * with `showPickers: { size: false }`: the variation form picks the
	 * size, so the designer draws no menu of its own, unless the side's
	 * options already say which pickers to show.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function mounts(): array {
		$mounts = array();
		foreach ( $this->order as $key ) {
			if ( isset( $this->mounts[ $key ] ) ) {
				$mounts[ $key ] = $this->mounts[ $key ];
			}
		}
		foreach ( $this->sized as $key => $sized ) {
			if ( $sized && isset( $mounts[ $key ] ) && ! isset( $mounts[ $key ]['showPickers'] ) ) {
				$mounts[ $key ]['showPickers'] = array( 'size' => false );
			}
		}
		return $mounts;
	}

	/**
	 * What each variation switches to, by variation id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function variations(): array {
		$variations = $this->variations;
		foreach ( $this->prices as $variation_id => $price_html ) {
			if ( isset( $variations[ $variation_id ] ) ) {
				$variations[ $variation_id ]['priceHtml'] = $price_html;
			}
		}
		return $variations;
	}

	/**
	 * Each side's name, by side key.
	 *
	 * @return array<string, string>
	 */
	public function side_labels(): array {
		return $this->labels;
	}

	/**
	 * The side's index of the size, added to its menu when it is not on it.
	 *
	 * @param string               $key  A mounted side.
	 * @param array<string, mixed> $size A print size, as the mount options carry it.
	 */
	private function size_index( string $key, array $size ): int {
		$menu  = self::print_sizes( $this->mounts[ $key ] ?? array() );
		$found = array_search( $size, $menu, true );
		if ( is_int( $found ) ) {
			return $found;
		}
		$menu[]                             = $size;
		$this->mounts[ $key ]['printSizes'] = $menu;
		return count( $menu ) - 1;
	}

	/**
	 * The print sizes in mount options.
	 *
	 * @param array<string, mixed> $options Mount options.
	 * @return list<array<string, mixed>>
	 */
	private static function print_sizes( array $options ): array {
		$sizes = is_array( $options['printSizes'] ?? null ) ? $options['printSizes'] : array();
		return array_values( array_filter( $sizes, 'is_array' ) );
	}
}
