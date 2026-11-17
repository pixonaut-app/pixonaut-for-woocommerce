<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\BlankPhoto;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class BlankPhotoTest extends TestCase {

	public function test_a_photo_is_drawn_on_through_its_mount_or_its_scene(): void {
		$this->assertTrue( BlankPhoto::drawable( array( 'mount' => array( 'url' => 'https://example.test/a.png' ) ) ) );
		$this->assertTrue( BlankPhoto::drawable( array( 'mount' => null ) + self::scene() ) );
		$this->assertFalse( BlankPhoto::drawable( array( 'mount' => null ) ) );
		$this->assertFalse( BlankPhoto::drawable( array() ) );
	}

	public function test_the_views_a_store_manager_picks_from_list_photos_of_a_setting(): void {
		$placements = array(
			array(
				'id'    => 'default',
				'views' => array(
					array(
						'id'     => 'view_wall',
						'label'  => 'Wall',
						'photos' => array(
							array(
								'id'           => 'sp_wall',
								'thumbnailUrl' => 'https://example.test/wall.png',
								'variantRefs'  => array( 'v_a' ),
								'mount'        => null,
							) + self::scene(),
							array(
								'id'           => 'sp_uploading',
								'thumbnailUrl' => 'https://example.test/uploading.png',
								'variantRefs'  => array( 'v_b' ),
								'mount'        => null,
							),
						),
					),
				),
			),
		);

		$views = PictureView::views_of( $placements, 'default' );

		$this->assertSame( array( 'view_wall' ), array_column( $views, 'id' ) );
		$this->assertSame( array( 'sp_wall' ), array_column( $views[0]['photos'], 'id' ) );
	}

	/**
	 * @return array{photoScene: array<string, mixed>}
	 */
	private static function scene(): array {
		return array(
			'photoScene' => array(
				'bakeKey'     => 'bake',
				'exact'       => true,
				'maskUrl'     => 'https://example.test/mask.png',
				'photoSizePx' => array(
					'w' => 825,
					'h' => 1024,
				),
			),
		);
	}
}
