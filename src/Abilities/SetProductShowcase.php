<?php
/**
 * The ability that sets which showcase pictures a product shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\ShowcaseField;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use WC_Product;
use WP_Error;

/**
 * Sets the showcase sets a personalised product's images are drawn with,
 * as the product images field of its Pixonaut tab does: each one of its
 * template's sets, drawn on the blank's photo or as the flat design; a set
 * still drawn on the photo keeps the view and photo chosen for it
 * (`SetProductPictureView`). The pictures are drawn again once the choice
 * is saved. Whoever may edit the
 * product may set them.
 */
final class SetProductShowcase implements Ability {

	/**
	 * @param ProductLinks  $links     Gives the answer.
	 * @param ShowcaseField $showcases Lists the template's sets.
	 */
	public function __construct( private readonly ProductLinks $links, private readonly ShowcaseField $showcases ) {
	}

	public function name(): string {
		return 'set-product-showcase';
	}

	public function definition(): array {
		$schema                                      = ProductLinks::output_schema();
		$schema['properties']['available_showcases'] = self::available_schema( 'array', __( 'The sets the product\'s template offers, by id and name.', 'pixonaut-for-woocommerce' ) );
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set a product\'s showcase pictures', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %d: how many showcase pictures a product shows at most, e.g. 10. */
				__( 'Sets which of its template\'s showcase sets a personalised simple or variable product\'s images are drawn with, in order, up to %d; replaces the ones it has, and an empty list removes them. Each is drawn on the blank\'s photo (`mockup`) or as the flat design (`preview`); a product without a blank has only the flat design. The pictures are drawn again. The answer lists the sets the template offers.', 'pixonaut-for-woocommerce' ),
				ShowcaseImages::MAX_IMAGES
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'images'     => array(
						'type'     => 'array',
						'maxItems' => ShowcaseImages::MAX_IMAGES,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'   => array(
									'type'        => 'string',
									'description' => __( 'One of the template\'s showcase sets, by id.', 'pixonaut-for-woocommerce' ),
								),
								'kind' => array(
									'type'    => 'string',
									'enum'    => array( ShowcaseImages::MOCKUP, ShowcaseImages::PREVIEW ),
									'default' => ShowcaseImages::MOCKUP,
								),
							),
							'required'             => array( 'id' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'product_id', 'images' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $schema,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id` and `images`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$template = ShowcaseField::template( $product );
		if ( null === $template ) {
			return new WP_Error( 'pixonaut_wc_ability_not_linked', __( 'The product is not personalised with a template, so it has no showcase sets. Set it up first.', 'pixonaut-for-woocommerce' ) );
		}
		try {
			$sets = $this->showcases->offered( $template );
		} catch ( ApiException $e ) {
			return new WP_Error( 'pixonaut_wc_ability_pixonaut_unreachable', __( 'Pixonaut could not be asked for the template\'s showcase sets. Try again later.', 'pixonaut-for-woocommerce' ) );
		}
		$by_id   = array_column( $sets, null, 'id' );
		$on_flat = null === ProductConfig::stored_on( $product )->blank_id;
		$before  = array_column( ShowcaseImages::on( $product ), null, 'id' );
		$images  = array();
		foreach ( is_array( $input['images'] ?? null ) ? $input['images'] : array() as $image ) {
			$id  = is_array( $image ) && is_string( $image['id'] ?? null ) ? $image['id'] : '';
			$set = $by_id[ $id ] ?? null;
			if ( null === $set ) {
				return new WP_Error(
					'pixonaut_wc_ability_unknown_showcase',
					sprintf(
						/* translators: 1: a showcase set's id; 2: the ids the template offers, e.g. "graduate, wedding". */
						__( 'The template has no showcase set "%1$s". It has: %2$s.', 'pixonaut-for-woocommerce' ),
						$id,
						array() === $sets ? _x( 'none', 'showcase sets a template offers', 'pixonaut-for-woocommerce' ) : implode( ', ', array_column( $sets, 'id' ) )
					)
				);
			}
			$kind     = $on_flat || ShowcaseImages::PREVIEW === ( $image['kind'] ?? null ) ? ShowcaseImages::PREVIEW : ShowcaseImages::MOCKUP;
			$earlier  = $before[ $set['id'] ] ?? array( 'kind' => $kind );
			$images[] = array(
				'id'   => $set['id'],
				'name' => $set['name'],
			) + ( $earlier['kind'] === $kind ? array_intersect_key( $earlier, array_flip( array( 'kind', 'view', 'photo' ) ) ) : array( 'kind' => $kind ) );
		}
		ShowcaseImages::save( $product, $images );
		return $this->links->summary( $product ) + array( 'available_showcases' => $sets );
	}

	/**
	 * The schema of the sets a product's template offers, as this ability
	 * and `get-product-picture-views` answer them.
	 *
	 * @param string|list<string> $type        `array`, or with `null` when the sets may be unknown.
	 * @param string              $description What the list is.
	 *
	 * @return array<string, mixed>
	 */
	public static function available_schema( string|array $type, string $description ): array {
		return array(
			'type'        => $type,
			'description' => $description,
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'string' ),
					'name' => array( 'type' => 'string' ),
				),
			),
		);
	}
}
