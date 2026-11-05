<?php
/**
 * The ability that lists Pixonaut's templates.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Catalogue\SearchResults;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use WC_Product;
use WP_Error;

/**
 * The workspace's live templates, a page at a time, each at its latest live
 * version, by the id a product is linked with (`LinkProduct`), with its
 * aspect ratio and the print sizes the store's products already sell it in,
 * so a new product can take a size the store uses for the same ratio.
 * Whoever may search the catalogue on the product screen may list them.
 */
final class ListTemplates implements Ability {

	/**
	 * Pixonaut's longest search text.
	 */
	private const MAX_QUERY_LENGTH = 200;

	/**
	 * Templates on a page when the input does not say.
	 */
	private const DEFAULT_PER_PAGE = Catalogue::SEARCH_PAGE_SIZE;

	/**
	 * Most templates on one page.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Products read from the index at once for a template's print sizes.
	 */
	private const PRODUCT_BATCH = 50;

	/**
	 * @param Catalogue $catalogue Pixonaut's catalogue.
	 */
	public function __construct( private readonly Catalogue $catalogue ) {
	}

	public function name(): string {
		return 'list-templates';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'List Pixonaut templates', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Lists the live templates of the store\'s Pixonaut workspace, optionally those whose name contains a search text, a page at a time, with how many there are in all. Each template\'s id is what a product is linked with; its ratio and the print sizes the store already sells it in help choose a new product\'s size.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Text the template\'s name contains; empty for all.', 'pixonaut-for-woocommerce' ),
						'default'     => '',
						'maxLength'   => self::MAX_QUERY_LENGTH,
					),
					'page'     => array(
						'type'        => 'integer',
						'description' => __( 'The page, from 1.', 'pixonaut-for-woocommerce' ),
						'default'     => 1,
						'minimum'     => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => self::DEFAULT_PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'templates' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'                     => array(
									'type'        => 'string',
									'description' => __( 'The template version\'s id, e.g. `mini-tee@2`.', 'pixonaut-for-woocommerce' ),
								),
								'name'                   => array( 'type' => 'string' ),
								'thumbnail'              => array(
									'type'        => 'string',
									'description' => __( 'A picture of the template, valid for an hour or two; empty when it has none.', 'pixonaut-for-woocommerce' ),
								),
								'offers_properties'      => array(
									'type'        => array( 'boolean', 'null' ),
									'description' => __( 'Whether the template offers the customer a field to fill in; null when Pixonaut did not say.', 'pixonaut-for-woocommerce' ),
								),
								'background_see_through' => array(
									'type'        => array( 'boolean', 'null' ),
									'description' => __( 'Whether the template\'s background shows through: on a product without a blank, customers then see it on white paper in the designer, and it prints on white paper. Null when Pixonaut does not know.', 'pixonaut-for-woocommerce' ),
								),
								'ratio'                  => array(
									'type'        => array( 'object', 'null' ),
									'description' => __( 'The template\'s aspect ratio, width to height; a print size must have the same. Null when Pixonaut did not say.', 'pixonaut-for-woocommerce' ),
									'properties'  => array(
										'width'  => array( 'type' => 'number' ),
										'height' => array( 'type' => 'number' ),
									),
								),
								'print_sizes'            => array(
									'type'        => 'array',
									'description' => __( 'The print sizes the store\'s products linked to the template are sold in, in millimetres; empty when none is.', 'pixonaut-for-woocommerce' ),
									'items'       => array(
										'type'       => 'object',
										'properties' => array(
											'width_mm'  => array( 'type' => 'number' ),
											'height_mm' => array( 'type' => 'number' ),
											'label'     => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
					),
					'total'     => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'How many templates match, on every page; null when Pixonaut did not say.', 'pixonaut-for-woocommerce' ),
					),
					'more'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a later page has more.', 'pixonaut-for-woocommerce' ),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => static fn (): bool => current_user_can( CatalogueSearch::CAPABILITY ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `search`, `page` and `per_page`.
	 * @return array{templates: list<array{id: string, name: string, thumbnail: string, offers_properties: bool|null, background_see_through: bool|null, ratio: array{width: float, height: float}|null, print_sizes: list<array{width_mm: float, height_mm: float, label: string}>}>, total: int|null, more: bool}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input    = is_array( $input ) ? $input : array();
		$page     = max( 1, Abilities::integer( $input, 'page', 1 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, Abilities::integer( $input, 'per_page', self::DEFAULT_PER_PAGE ) ) );
		try {
			$found = $this->catalogue->templates( is_string( $input['search'] ?? null ) ? $input['search'] : '', $page, $per_page );
		} catch ( ApiError $e ) {
			return AbilityError::from( $e );
		}
		$ratios    = self::ratios( $found['rows'] );
		$templates = array();
		foreach ( SearchResults::templates( $found['rows'] ) as $option ) {
			$templates[] = array(
				'id'                     => $option['id'],
				'name'                   => $option['text'],
				'thumbnail'              => $option['thumbnail'],
				'offers_properties'      => $option['offersProperties'] ?? null,
				'background_see_through' => $option['backgroundSeeThrough'] ?? null,
				'ratio'                  => $ratios[ $option['id'] ] ?? null,
				'print_sizes'            => self::print_sizes( $option['id'] ),
			);
		}
		return array(
			'templates' => $templates,
			'total'     => $found['total'],
			'more'      => $found['more'],
		);
	}

	/**
	 * Each template's aspect ratio by the id it is listed with, when
	 * Pixonaut gave one with both parts above zero.
	 *
	 * @param list<array<string, mixed>> $rows `GET /v1/templates` items.
	 * @return array<string, array{width: float, height: float}>
	 */
	private static function ratios( array $rows ): array {
		$ratios = array();
		foreach ( $rows as $row ) {
			$ratio   = is_array( $row['ratio'] ?? null ) ? $row['ratio'] : array();
			$width   = $ratio['w'] ?? null;
			$height  = $ratio['h'] ?? null;
			$base_id = $row['baseId'] ?? null;
			$version = $row['latestVersion'] ?? null;
			if ( ! is_string( $base_id ) || '' === $base_id || ! is_int( $version ) || $version < 1 || ! is_numeric( $width ) || ! is_numeric( $height ) || ! PrintSize::fits( (float) $width, (float) $height ) ) {
				continue;
			}
			$ratios[ (string) new TemplateId( $base_id, $version ) ] = array(
				'width'  => (float) $width,
				'height' => (float) $height,
			);
		}
		return $ratios;
	}

	/**
	 * The distinct print sizes the store sells a template in, in the order
	 * found: from the configuration that applies to each published product
	 * and enabled variation linked to it, when that personalises with the
	 * template. A configuration kept after unlinking, or a variation's own
	 * while it follows its product's, is not one the store sells.
	 *
	 * @param string $id The template's id, e.g. `mini-tee@2`.
	 * @return list<array{width_mm: float, height_mm: float, label: string}>
	 */
	private static function print_sizes( string $id ): array {
		$template = TemplateId::parse( $id );
		if ( null === $template ) {
			return array();
		}
		$sizes = array();
		foreach ( self::linked_products( $template->base_id ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$config = ProductConfig::config_for( $product );
			if ( ! $config->personalise ) {
				continue;
			}
			foreach ( $config->sides as $side ) {
				if ( $side->template_base_id !== $template->base_id ) {
					continue;
				}
				foreach ( $side->print_sizes as $size ) {
					$key             = $size->width_mm . 'x' . $size->height_mm;
					$sizes[ $key ] ??= array(
						'width_mm'  => $size->width_mm,
						'height_mm' => $size->height_mm,
						'label'     => '' !== $size->label ? $size->label : PrintSize::label_for( $size->width_mm, $size->height_mm ),
					);
				}
			}
		}
		return array_values( $sizes );
	}

	/**
	 * Every published product and variation linked to a template, read from
	 * the index a batch at a time.
	 *
	 * @param string $base_id The template's base id.
	 * @return \Generator<int, int>
	 */
	private static function linked_products( string $base_id ): \Generator {
		$offset = 0;
		do {
			$batch = ProductIndex::published_using_template( $base_id, self::PRODUCT_BATCH, $offset );
			yield from $batch;
			$offset += self::PRODUCT_BATCH;
			$full    = count( $batch ) === self::PRODUCT_BATCH;
		} while ( $full );
	}
}
