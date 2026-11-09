<?php
/**
 * A blank as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Api\Catalogue;

/**
 * `GET /v1/blanks/{id}` answers for `DesignFixture`'s blank.
 */
final class BlankFixture {

	/**
	 * The workspace's default printshop.
	 */
	public const PRINTSHOP_ID = 'ps_main';

	/**
	 * The ref of the blank's only variant, unless a test gives it others.
	 */
	public const VARIANT_REF = 'v_tee';

	/**
	 * The views the variant has a photo in, as a stored variant keeps them.
	 */
	public const VARIANT_VIEWS = array(
		array(
			'id'        => self::VIEW_ID,
			'placement' => DesignFixture::PLACEMENT_ID,
			'label'     => 'Front',
		),
	);

	public const VIEW_ID = 'view_front';

	/**
	 * How far in a placement folds, as a canvas round its frame does.
	 */
	private const FOLD_MM = 20;

	/**
	 * A view's `source` for the merchant's own photos.
	 */
	private const OWN = 'own';

	/**
	 * A `GET /v1/blanks/{id}` answer.
	 *
	 * @param array<string, string> $placements Placement names customers see, by id.
	 * @param string|null           $printshop  The printshop the blank resolves to.
	 * @param string|null           $source     Where that comes from, a `LinkedBlank::SOURCE_*`; null for
	 *                                          the blank's own, or the workspace's default when it names none.
	 * @return array<string, mixed>
	 */
	public static function answer( array $placements = array( DesignFixture::PLACEMENT_ID => 'Front' ), ?string $printshop = null, ?string $source = null ): array {
		$none     = array(
			'value'  => null,
			'source' => LinkedBlank::SOURCE_NONE,
		);
		$source ??= null === $printshop ? LinkedBlank::SOURCE_WORKSPACE : LinkedBlank::SOURCE_OWN;
		if ( LinkedBlank::SOURCE_WORKSPACE === $source ) {
			$printshop ??= self::PRINTSHOP_ID;
		}
		$own  = LinkedBlank::SOURCE_OWN === $source ? $printshop : null;
		$rows = array();
		foreach ( $placements as $id => $name ) {
			$rows[] = array(
				'id'              => $id,
				'name'            => $name . ' print',
				'shopName'        => $name,
				'maxWidthMm'      => 330,
				'maxHeightMm'     => 420,
				'defaultWidthMm'  => 300,
				'defaultHeightMm' => 360,
				'views'           => array(),
			);
		}
		return array(
			'id'                 => DesignFixture::BLANK_ID,
			'name'               => 'Fixture tee',
			'categoryId'         => null,
			// A blank's printshop is set when it is created.
			'defaultPrintshopId' => $own ?? $printshop ?? self::PRINTSHOP_ID,
			'stockItemKey'       => null,
			'partnerLocked'      => false,
			'machineProfileId'   => null,
			'placements'         => $rows,
			'resolved'           => array(
				'defaultPrintshopId' => array(
					'value'  => LinkedBlank::SOURCE_NONE === $source ? null : $printshop,
					'source' => $source,
				),
				'machineProfileId'   => $none,
				'placements'         => array(),
			),
			'axes'               => array(),
			'copiedFrom'         => null,
			'createdAt'          => 1,
			'updatedAt'          => 1,
			'deleted'            => null,
			'options'            => array(),
			'optionsConfirmedAt' => null,
			'photosVaryBy'       => array(),
			// A new blank starts on its own photos, which are not painted.
			'paintBy'            => null,
			'areasVaryBy'        => array(),
			'referenceRow'       => null,
			'status'             => Catalogue::BLANK_READY,
			'importFailure'      => null,
		);
	}

	/**
	 * `$blank` with the fields the spec answers beside its views: each view
	 * saying its own `source` and `position`, one blank's `order` listing its
	 * stored views and scenes together, and placements that fold.
	 *
	 * @param array<string, mixed> $blank A `GET /v1/blanks/{id}` answer, or one blank of a list.
	 * @param bool                 $one   Whether it answers for one blank, which alone carries `order`.
	 * @return array<string, mixed>
	 */
	public static function with_ordered_views( array $blank, bool $one = true ): array {
		$order    = array();
		$position = 0;
		foreach ( is_array( $blank['placements'] ?? null ) ? $blank['placements'] : array() as $index => $placement ) {
			$blank['placements'][ $index ]['foldMm'] = self::FOLD_MM;
			$blank['placements'][ $index ]['views']  = self::views_with_sources( is_array( $placement['views'] ?? null ) ? $placement['views'] : array(), $position );
			foreach ( $blank['placements'][ $index ]['views'] as $view ) {
				$order[] = array(
					'source' => self::OWN,
					'viewId' => $view['id'],
				);
			}
		}
		if ( $one ) {
			// A scene the blank shows, after its stored views.
			$order[]        = array(
				'source'  => 'stock',
				'sceneId' => 'scene_kitchen',
			);
			$blank['order'] = $order;
		}
		return $blank;
	}

	/**
	 * A `GET /v1/blanks/{id}/mockups` answer whose views each say their
	 * `source` and `position`, and whose placements fold.
	 *
	 * @param array<string, mixed> $mockups A `mockups()` answer.
	 * @return array<string, mixed>
	 */
	public static function mockups_with_ordered_views( array $mockups ): array {
		$position = 0;
		foreach ( is_array( $mockups['placements'] ?? null ) ? $mockups['placements'] : array() as $index => $placement ) {
			$mockups['placements'][ $index ]['foldMm'] = self::FOLD_MM;
			$mockups['placements'][ $index ]['views']  = self::views_with_sources( is_array( $placement['views'] ?? null ) ? $placement['views'] : array(), $position );
		}
		return $mockups;
	}

	/**
	 * `$views`, each with its `source` and its place in the blank's one list.
	 *
	 * @param array<int, mixed> $views    Views of one placement.
	 * @param int               $position The next place in the list, moved past these views.
	 * @return list<array<string, mixed>>
	 */
	private static function views_with_sources( array $views, int &$position ): array {
		$sourced = array();
		foreach ( $views as $view ) {
			if ( is_array( $view ) ) {
				$sourced[] = $view + array(
					'source'   => self::OWN,
					'position' => $position++,
				);
			}
		}
		return $sourced;
	}

	/**
	 * A `GET /v1/blanks/{id}` answer for the blank once deleted, which
	 * Pixonaut still reads by id.
	 *
	 * @return array<string, mixed>
	 */
	public static function deleted(): array {
		return array(
			'deleted' => array(
				'at' => 2,
				'by' => null,
			),
		) + self::answer();
	}

	/**
	 * A `GET /v1/blanks/{id}/variants` answer. By default the blank has one
	 * variant without options, which every product and variation is.
	 *
	 * @param array<array<string, mixed>> $variants Each variant's fields that differ from the default's.
	 * @param string[]                    $axes     The blank's axes.
	 * @phpstan-param list<array<string, mixed>> $variants
	 * @phpstan-param list<string> $axes
	 * @return array<string, mixed>
	 */
	public static function variants( array $variants = array( array() ), array $axes = array() ): array {
		$options = array();
		foreach ( $axes as $axis ) {
			$values = array();
			foreach ( $variants as $variant ) {
				$value = is_array( $variant['options'] ?? null ) ? ( $variant['options'][ $axis ] ?? null ) : null;
				if ( is_string( $value ) && ! in_array( $value, $values, true ) ) {
					$values[] = $value;
				}
			}
			$options[] = array(
				'name'        => $axis,
				'values'      => $values,
				// No value has an appearance: each is drawn as its name.
				'appearances' => (object) array_fill_keys( $values, null ),
				// Every value is a light garment, as Pixonaut answers a value with no tone of its own.
				'tones'       => (object) array_fill_keys( $values, 'light' ),
			);
		}
		return array(
			'axes'        => $axes,
			'options'     => $options,
			'areasVaryBy' => array(),
			// The blank's own photos, which are not painted.
			'paintBy'     => null,
			'variants'    => array_map(
				static fn ( array $variant ): array => array_merge(
					array(
						'ref'     => self::VARIANT_REF,
						'options' => new \stdClass(),
						'retired' => null,
						'views'   => array(
							array(
								'placementId' => DesignFixture::PLACEMENT_ID,
								'viewId'      => self::VIEW_ID,
								'label'       => 'Front',
							),
						),
					),
					$variant
				),
				$variants
			),
		);
	}

	/**
	 * A `GET /v1/blanks/{id}/mockups` answer: each placement with one view
	 * named after it, `view-<placement>`, holding one photo and, on the
	 * front, one more whose upload has not finished.
	 *
	 * @param string                $base_url   Where the photos' links point.
	 * @param array<string, string> $placements Placement names, by id.
	 * @return array<string, mixed>
	 */
	public static function mockups( string $base_url, array $placements = array( DesignFixture::PLACEMENT_ID => 'Front' ) ): array {
		$rows = array();
		foreach ( $placements as $id => $name ) {
			$photo  = static fn ( string $mockup_id ): string => $base_url . '/i/' . $mockup_id;
			$photos = array(
				array(
					'id'           => 'mock-' . $id,
					'thumbnailUrl' => $photo( 'thumb-' . $id ),
					'width'        => 1000,
					'height'       => 1000,
					'variantRefs'  => array(),
					'mount'        => array(
						'url'             => $photo( 'mount-' . $id ),
						'placementRectPx' => array(
							'x' => 250,
							'y' => 200,
							'w' => 500,
							'h' => 600,
						),
						'photoSizePx'     => array(
							'w' => 1000,
							'h' => 1000,
						),
					),
				),
			);
			if ( DesignFixture::PLACEMENT_ID === $id ) {
				$photos[] = array(
					'id'           => 'mock-uploading',
					'thumbnailUrl' => $photo( 'thumb-uploading' ),
					'width'        => null,
					'height'       => null,
					'variantRefs'  => array(),
					'mount'        => null,
				);
			}
			$rows[] = array(
				'id'              => $id,
				'name'            => $name . ' print',
				'maxWidthMm'      => 330,
				'maxHeightMm'     => 420,
				'defaultWidthMm'  => 300,
				'defaultHeightMm' => 360,
				'bleedMm'         => 3,
				'safeAreaMm'      => 5,
				'areas'           => array(),
				'coverage'        => 'within',
				'views'           => array(
					array(
						'id'              => 'view-' . $id,
						'label'           => $name,
						'photoSizePx'     => array(
							'widthPx'  => 1000,
							'heightPx' => 1000,
						),
						'placementRectPx' => array(
							'x' => 250,
							'y' => 200,
							'w' => 500,
							'h' => 600,
						),
						'photos'          => $photos,
					),
				),
			);
		}
		return array(
			'placements' => $rows,
			'views'      => array(),
		);
	}
}
