<?php
/**
 * What the product screen shows about a linked blank.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Closure;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductLinker;
use Pixonaut\WooCommerce\Catalogue\SearchResults;
use Pixonaut\WooCommerce\DesignerLanguage;

/**
 * The blank's placements, their views and the printshop it prints at, read from Pixonaut
 * through the catalogue cache, and the workspace's printshops. A blank's
 * printshop is only shown: the blank decides it, and it is changed in the
 * Pixonaut studio.
 */
final class LinkedBlank {

	/**
	 * @param Catalogue      $catalogue Reads the blank and the printshops.
	 * @param CatalogueCache $cache     Keeps those reads for a few minutes.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
	) {
	}

	/**
	 * What Pixonaut could not answer in this request, by what was asked. A
	 * failure is not cached, and a page of variations asks for the same blank
	 * once per variation, so each lookup fails once rather than once per row.
	 *
	 * @var array<string, ApiException>
	 */
	private array $failed = array();

	/**
	 * Where a blank's printshop comes from, as Pixonaut resolves it: the
	 * blank's own, its category's, the workspace's default, or none set.
	 */
	public const SOURCE_OWN = 'own';

	public const SOURCE_CATEGORY = 'category';

	public const SOURCE_WORKSPACE = 'workspace';

	public const SOURCE_NONE = 'none';

	private const SOURCES = array( self::SOURCE_OWN, self::SOURCE_CATEGORY, self::SOURCE_WORKSPACE, self::SOURCE_NONE );

	/**
	 * The blank's placements, each with the largest print it takes when
	 * Pixonaut says, the printshop it resolves to, and whether it is
	 * deleted: Pixonaut still reads a deleted blank, which sells nothing.
	 *
	 * @param string $blank_id The blank.
	 * @return array{placements: list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>, printshop_id: string|null, printshop_source: string, deleted: bool}|null Null when Pixonaut cannot be asked.
	 */
	public function read( string $blank_id ): ?array {
		try {
			$blank = $this->remember(
				'linked-blank',
				array( 'blank' => $blank_id ),
				function () use ( $blank_id ): array {
					$blank    = $this->catalogue->blank( $blank_id );
					$resolved = is_array( $blank['resolved']['defaultPrintshopId'] ?? null ) ? $blank['resolved']['defaultPrintshopId'] : null;
					$own      = is_string( $blank['defaultPrintshopId'] ?? null ) ? $blank['defaultPrintshopId'] : null;
					return array(
						'placements'       => SearchResults::placements( $blank ),
						'printshop_id'     => null === $resolved ? $own : ( is_string( $resolved['value'] ?? null ) ? $resolved['value'] : null ),
						'printshop_source' => is_string( $resolved['source'] ?? null ) ? $resolved['source'] : null,
						'deleted'          => ProductLinker::is_deleted( $blank ),
					);
				}
			);
		} catch ( ApiException $e ) {
			return null;
		}
		$placements = array();
		foreach ( is_array( $blank['placements'] ?? null ) ? $blank['placements'] : array() as $placement ) {
			if ( is_array( $placement ) && is_string( $placement['id'] ?? null ) && is_string( $placement['text'] ?? null ) ) {
				$placements[] = array(
					'id'   => $placement['id'],
					'text' => $placement['text'],
				) + SearchResults::limit( $placement );
			}
		}
		$printshop_id = is_string( $blank['printshop_id'] ?? null ) ? $blank['printshop_id'] : null;
		$source       = $blank['printshop_source'] ?? null;
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			// Without Pixonaut's resolution, a blank naming no printshop prints at the workspace's default.
			$source = null === $printshop_id ? self::SOURCE_WORKSPACE : self::SOURCE_OWN;
		}
		return array(
			'placements'       => $placements,
			'printshop_id'     => $printshop_id,
			'printshop_source' => $source,
			'deleted'          => true === ( $blank['deleted'] ?? null ),
		);
	}

	/**
	 * The blank's views the product can be shown in, placement by placement
	 * in Pixonaut's order: each placement's id, name and its views, each one's
	 * id, label and the thumbnail of its first photo (empty without one).
	 * Labels are in the site's language where the blank has them in it.
	 *
	 * @param string $blank_id The blank.
	 * @return list<array{id: string, name: string, views: list<array{id: string, label: string, thumbnail: string}>}>|null Null when Pixonaut cannot be asked.
	 */
	public function views( string $blank_id ): ?array {
		$language = DesignerLanguage::of_site();
		$args     = array( 'blank' => $blank_id ) + ( null === $language ? array() : array( 'lang' => $language ) );
		try {
			$placements = $this->remember( 'blank-mockups', $args, fn (): array => $this->catalogue->mockups( $blank_id, $language ) );
		} catch ( ApiException $e ) {
			return null;
		}
		$listed = array();
		foreach ( $placements as $placement ) {
			if ( ! is_array( $placement ) || ! is_string( $placement['id'] ?? null ) ) {
				continue;
			}
			$views = array();
			foreach ( is_array( $placement['views'] ?? null ) ? $placement['views'] : array() as $view ) {
				if ( ! is_array( $view ) || ! is_string( $view['id'] ?? null ) || '' === $view['id'] ) {
					continue;
				}
				$photos  = is_array( $view['photos'] ?? null ) ? array_values( $view['photos'] ) : array();
				$first   = is_array( $photos[0] ?? null ) ? $photos[0] : array();
				$views[] = array(
					'id'        => $view['id'],
					'label'     => is_string( $view['label'] ?? null ) && '' !== $view['label'] ? $view['label'] : $view['id'],
					'thumbnail' => is_string( $first['thumbnailUrl'] ?? null ) ? $first['thumbnailUrl'] : '',
				);
			}
			$listed[] = array(
				'id'    => $placement['id'],
				'name'  => is_string( $placement['name'] ?? null ) ? $placement['name'] : $placement['id'],
				'views' => $views,
			);
		}
		return $listed;
	}

	/**
	 * A placement's views with the photos a picture can be drawn on
	 * (`PictureView::views_of()`), labels in the site's language where the
	 * blank has them in it.
	 *
	 * @param string $blank_id     The blank.
	 * @param string $placement_id One of its placements.
	 * @return list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null Null when Pixonaut cannot be asked.
	 */
	public function placement_photos( string $blank_id, string $placement_id ): ?array {
		$language = DesignerLanguage::of_site();
		$args     = array( 'blank' => $blank_id ) + ( null === $language ? array() : array( 'lang' => $language ) );
		try {
			$placements = $this->remember( 'blank-mockups', $args, fn (): array => $this->catalogue->mockups( $blank_id, $language ) );
		} catch ( ApiException $e ) {
			return null;
		}
		return PictureView::views_of( $placements, $placement_id );
	}

	/**
	 * The thumbnail of a view's photo of a variant, else of its first photo,
	 * as a picture drawn on that view uses it; empty when the view has no
	 * photo.
	 *
	 * @param string      $blank_id     The blank.
	 * @param string      $placement_id One of its placements.
	 * @param string      $view_id      One of the placement's views.
	 * @param string|null $variant      A variant ref of the blank; null for the view's first photo.
	 * @return string|null Null when Pixonaut cannot be asked.
	 */
	public function view_photo( string $blank_id, string $placement_id, string $view_id, ?string $variant ): ?string {
		$language = DesignerLanguage::of_site();
		$args     = array( 'blank' => $blank_id ) + ( null === $language ? array() : array( 'lang' => $language ) );
		try {
			$placements = $this->remember( 'blank-mockups', $args, fn (): array => $this->catalogue->mockups( $blank_id, $language ) );
		} catch ( ApiException $e ) {
			return null;
		}
		foreach ( $placements as $placement ) {
			if ( ! is_array( $placement ) || ( $placement['id'] ?? null ) !== $placement_id ) {
				continue;
			}
			foreach ( is_array( $placement['views'] ?? null ) ? $placement['views'] : array() as $view ) {
				if ( ! is_array( $view ) || ( $view['id'] ?? null ) !== $view_id ) {
					continue;
				}
				$photos = array_values( array_filter( is_array( $view['photos'] ?? null ) ? $view['photos'] : array(), 'is_array' ) );
				$shown  = array_values( array_filter( $photos, static fn ( array $photo ): bool => null !== $variant && in_array( $variant, is_array( $photo['variantRefs'] ?? null ) ? $photo['variantRefs'] : array(), true ) ) );
				$photo  = $shown[0] ?? $photos[0] ?? array();
				return is_string( $photo['thumbnailUrl'] ?? null ) ? $photo['thumbnailUrl'] : '';
			}
		}
		return '';
	}

	/**
	 * The thumbnail of each placement's first view, by placement id: the
	 * picture its picker shows beside its name.
	 *
	 * @param list<array{id: string, name: string, views: list<array{id: string, label: string, thumbnail: string}>}>|null $views The blank's views, as views() gave them.
	 * @return array<string, string> Empty for a placement whose first view has no photo.
	 */
	public static function placement_thumbnails( ?array $views ): array {
		$thumbnails = array();
		foreach ( $views ?? array() as $placement ) {
			$thumbnails[ $placement['id'] ] = $placement['views'][0]['thumbnail'] ?? '';
		}
		return $thumbnails;
	}

	/**
	 * The printshop line: the printshop the blank resolves to and where it
	 * comes from, and where in the studio to change it.
	 *
	 * @param string                    $blank_id The blank.
	 * @param array<string, mixed>|null $blank    The blank as read() gave it; null when Pixonaut could not be asked.
	 * @phpstan-param array{placements: list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>, printshop_id: string|null, printshop_source: string, deleted: bool}|null $blank
	 * @return array{name: string, url: string}
	 */
	public function printshop( string $blank_id, ?array $blank ): array {
		return array(
			'name' => null === $blank ? self::unknown() : $this->name( $blank['printshop_id'], $blank['printshop_source'] ),
			'url'  => self::studio_url( $blank_id ),
		);
	}

	/**
	 * @param string|null $printshop_id The printshop the blank resolves to.
	 * @param string      $source       Where it comes from, a `SOURCE_*`.
	 */
	private function name( ?string $printshop_id, string $source ): string {
		if ( self::SOURCE_NONE === $source ) {
			return __( 'None set: Pixonaut cannot route this blank’s orders until a printshop is chosen.', 'pixonaut-for-woocommerce' );
		}

		$connections = $this->printshops();
		$named       = null;
		foreach ( $connections ?? array() as $connection ) {
			// Only the workspace's default is found without an id.
			$match = null === $printshop_id
				? self::SOURCE_WORKSPACE === $source && $connection['default']
				: $connection['id'] === $printshop_id;
			if ( $match ) {
				$named = $connection['name'];
			}
		}

		if ( self::SOURCE_WORKSPACE === $source ) {
			return null === $named
				/* translators: Shown to the merchant on the product edit page as the printshop of a blank: the default printshop of the Pixonaut workspace. */
				? __( 'Workspace default', 'pixonaut-for-woocommerce' )
				/* translators: %s: the workspace's default printshop. */
				: sprintf( __( 'Workspace default (%s)', 'pixonaut-for-woocommerce' ), $named );
		}
		if ( null === $named ) {
			return null === $connections ? self::unknown() : __( 'A printshop no longer connected to the workspace', 'pixonaut-for-woocommerce' );
		}
		return self::SOURCE_CATEGORY === $source
			/* translators: %s: the printshop the blank's category names. */
			? sprintf( __( '%s (from the blank’s category)', 'pixonaut-for-woocommerce' ), $named )
			: $named;
	}

	/**
	 * The workspace's printshops, in the order Pixonaut lists them: the
	 * ones a product without a blank can send its orders to.
	 *
	 * @return list<array{id: string, name: string, default: bool}>|null Null when Pixonaut cannot be asked.
	 */
	public function printshops(): ?array {
		try {
			$connections = $this->remember( 'printshop-connections', array(), fn (): array => $this->catalogue->printshop_connections() );
		} catch ( ApiException $e ) {
			return null;
		}
		$printshops = array();
		foreach ( $connections as $connection ) {
			if ( is_array( $connection ) && is_string( $connection['id'] ?? null ) && '' !== $connection['id'] && is_string( $connection['name'] ?? null ) ) {
				$printshops[] = array(
					'id'      => $connection['id'],
					'name'    => $connection['name'],
					'default' => true === ( $connection['isDefault'] ?? null ),
				);
			}
		}
		return $printshops;
	}

	private static function unknown(): string {
		return __( 'Pixonaut could not be asked which printshop this is.', 'pixonaut-for-woocommerce' );
	}

	/**
	 * The blank's page in the Pixonaut studio; empty while the workspace's
	 * slug is not known.
	 *
	 * @param string $blank_id The blank.
	 */
	public static function studio_url( string $blank_id ): string {
		return StudioPage::url( StudioPage::BLANKS, $blank_id );
	}

	/**
	 * A catalogue cache read that is not repeated in this request once it failed.
	 *
	 * @param string                $what Which answer.
	 * @param array<string, string> $args What it is asked with.
	 * @param Closure               $load Asks Pixonaut.
	 * @return array<mixed>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached, now or earlier in this request.
	 */
	private function remember( string $what, array $args, Closure $load ): array {
		$key = $what . ':' . wp_json_encode( $args );
		if ( isset( $this->failed[ $key ] ) ) {
			throw $this->failed[ $key ];
		}
		try {
			return $this->cache->remember( $what, $args, $load );
		} catch ( ApiException $e ) {
			$this->failed[ $key ] = $e;
			throw $e;
		}
	}
}
