<?php
/**
 * `GET /v1/designs/{id}` answers for tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;

/**
 * A committed design as the pinned spec describes it, made from the side
 * `front` of template `mini-tee@2` on blank `blank_tee`, printed 280×360 mm.
 */
final class DesignFixture {

	public const ID = '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c';

	public const TEMPLATE_BASE_ID = 'mini-tee';

	public const LIVE_VERSION = 2;

	public const BLANK_ID = 'blank_tee';

	public const PLACEMENT_ID = 'front';

	public const WIDTH_MM = 280.0;

	public const HEIGHT_MM = 360.0;

	public const PROOF_ID = 'prf_9c1e2d3f4a5b6c7d8e9f0a1b';

	public const COMMITTED_AT_MS = 1_800_000_000_000;

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	public static function design( array $overrides = array() ): array {
		$version = $overrides['templateVersion'] ?? self::LIVE_VERSION;
		return array_merge(
			array(
				'id'                   => self::ID,
				'tenantId'             => 'tnt_live',
				'templateId'           => self::TEMPLATE_BASE_ID . '@' . ( is_int( $version ) ? $version : self::LIVE_VERSION ),
				'templateBaseId'       => self::TEMPLATE_BASE_ID,
				'templateVersion'      => self::LIVE_VERSION,
				'templateName'         => 'Mini tee',
				'templateDeleted'      => false,
				'status'               => 'committed',
				'apiKeyId'             => null,
				'committedAt'          => self::COMMITTED_AT_MS,
				'approveAfter'         => null,
				'approvedAt'           => null,
				'approvedVia'          => null,
				'rejectionReason'      => null,
				'fulfilmentPayload'    => null,
				'proofId'              => self::PROOF_ID,
				'proofRevision'        => 1,
				'proofVariant'         => null,
				'proofViewId'          => null,
				'garmentTone'          => 'light',
				'revision'             => 1,
				'duplicatedFrom'       => null,
				'standing'             => false,
				'blankId'              => self::BLANK_ID,
				'placementId'          => self::PLACEMENT_ID,
				'printSize'            => array(
					'widthMm'  => self::WIDTH_MM,
					'heightMm' => self::HEIGHT_MM,
				),
				'variantRow'           => null,
				'externalRef'          => null,
				'previewOpened'        => null,
				'deletable'            => true,
				'approvalConsent'      => null,
				'personalDataErasedAt' => null,
				'shareLinkId'          => null,
				'language'             => 'en',
				'origin'               => array(
					'kind' => 'store',
					'id'   => 'https://shop.example',
				),
				'doc'                  => array(),
				'canDecide'            => true,
				'hasOrder'             => false,
				'hasPrint'             => false,
				'editable'             => true,
				'canEdit'              => true,
				'changeRequest'        => null,
				'correctionForm'       => null,
				'pictureUrl'           => null,
				'choices'              => null,
			),
			$overrides
		);
	}

	/**
	 * Stores the variant of its blank a product, or each variation of a
	 * variable product, sells, as resolving it against
	 * `BlankFixture::variants()` would.
	 *
	 * @param \WC_Product $product A product or variation.
	 */
	public static function sell_variant( \WC_Product $product ): void {
		// Read afresh: a variable product read before its variations were saved lists none.
		$sold = $product->is_type( 'variable' )
			? array_map(
				'wc_get_product',
				get_posts(
					array(
						'post_parent' => $product->get_id(),
						'post_type'   => 'product_variation',
						'post_status' => 'any',
						'fields'      => 'ids',
						'numberposts' => -1,
					)
				)
			)
			: array( $product );
		foreach ( $sold as $one ) {
			if ( $one instanceof \WC_Product ) {
				$blank_id = ProductConfig::config_for( $one )->blank_id ?? self::BLANK_ID;
				( new ProductVariant( $blank_id, null, BlankFixture::VARIANT_REF, '', null, BlankFixture::VARIANT_VIEWS, ProductVariants::attributes( $one ) ) )->save_to( $one );
			}
		}
	}
}
