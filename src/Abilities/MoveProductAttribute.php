<?php
/**
 * The ability that moves products from a custom attribute to a global one.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Catalogue\AttributeMove;
use Pixonaut\WooCommerce\Catalogue\AttributeMoveRefused;
use Throwable;
use WC_Product;
use WP_Error;
use WP_Http;

/**
 * Moves the store's products, a page at a time or those named, from a custom
 * attribute to a global one, each product as one write (`AttributeMove`). A
 * product it cannot move stays as it was, and the answer says why; the
 * others go ahead. Moved already, a product answers so, so a call can be run
 * again. Whoever may edit products may run it; a product they may not edit
 * is refused on its own.
 */
final class MoveProductAttribute implements Ability {

	/** A product moved. */
	public const MOVED = 'moved';

	/** A product a dry run would move. */
	public const WOULD_MOVE = 'would_move';

	/** A product already on the global attribute. */
	public const ALREADY_MOVED = 'already_moved';

	/** A product named that has neither attribute. */
	public const NOT_ON_PRODUCT = 'not_on_product';

	/** A product left as it was, for the reason given. */
	public const FAILED = 'failed';

	private const CAPABILITY = 'edit_products';

	/** The one product's capability checked. */
	private const PRODUCT_CAPABILITY = 'edit_product';

	private const TAXONOMY_PREFIX = 'pa_';

	private const PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * The statuses walked: every product but those in the trash.
	 */
	private const STATUSES = array( 'draft', 'pending', 'private', 'future', 'publish' );

	private const DEFAULT_PER_PAGE = 20;

	/**
	 * Most products looked at in one call: each is a transaction of its own.
	 */
	private const MAX_PER_PAGE = 50;

	/**
	 * Why a product named cannot be moved.
	 */
	private const REASON_NOT_ALLOWED = 'not_allowed';

	private const REASON_NOT_A_PRODUCT = 'not_a_product';

	private const REASON_SAVE_FAILED = 'save_failed';

	/**
	 * @param LoggerInterface $logger Where an unexpected failure is told.
	 */
	public function __construct( private readonly LoggerInterface $logger ) {
	}

	public function name(): string {
		return 'move-product-attribute';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Move products from a custom attribute to a global one', 'pixonaut-for-woocommerce' ),
			/* translators: %d: the most variations a product may have to be moved. */
			'description'         => sprintf( __( 'Moves products from a custom product attribute to a global one: the product\'s attribute with its position, visibility and use for variations, its default choice, every variation\'s value, and the Pixonaut form arrangements naming the attribute. Each product is written as one, so customers never see it part-way, and each variation keeps its id, price, image and Pixonaut setup. Links and cart lines naming the old attribute go on selecting the same value. Create the global attribute and its terms first; a value matches a term by name or slug, or by `term_map`. Each product\'s `before` records its state before the move, including any stored setup it renames; run it with `dry_run` first and keep the answer. It is a record, not an undo: no ability restores it, and going back means `update-product`, `update-product-variation`, and `set-product-setup` with the sides\' `form_groups`. A product with more than %d variations is refused. Without `product_ids` it walks the store\'s products a page at a time; call again with the next page while `more` is true.', 'pixonaut-for-woocommerce' ), AttributeMove::MAX_VARIATIONS ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'from'        => array(
						'type'        => 'string',
						'description' => __( 'The custom attribute, by its name or slug, e.g. `Size`.', 'pixonaut-for-woocommerce' ),
						'minLength'   => 1,
					),
					'to'          => array(
						'type'        => 'string',
						'description' => __( 'The global attribute, by its slug with or without `pa_`, e.g. `pa_size`.', 'pixonaut-for-woocommerce' ),
						'minLength'   => 1,
					),
					'term_map'    => array(
						'type'                 => 'object',
						'description'          => __( 'For a value not named as its term: the term\'s name or slug, by the value.', 'pixonaut-for-woocommerce' ),
						'additionalProperties' => array( 'type' => 'string' ),
					),
					'dry_run'     => array(
						'type'        => 'boolean',
						'description' => __( 'Say what would happen, with a record of each product\'s state in `before`, and change nothing.', 'pixonaut-for-woocommerce' ),
						'default'     => false,
					),
					'product_ids' => array(
						'type'        => 'array',
						'description' => __( 'Only these products; left out to walk them all.', 'pixonaut-for-woocommerce' ),
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems'    => self::MAX_PER_PAGE,
					),
					'page'        => array(
						'type'        => 'integer',
						'description' => __( 'The page of the store\'s products, from 1.', 'pixonaut-for-woocommerce' ),
						'default'     => 1,
						'minimum'     => 1,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'default' => self::DEFAULT_PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					),
				),
				'required'             => array( 'from', 'to' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'dry_run'  => array( 'type' => 'boolean' ),
					'to'       => array( 'type' => 'string' ),
					'products' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'product_id' => array( 'type' => 'integer' ),
								'status'     => array(
									'type' => 'string',
									'enum' => array( self::MOVED, self::WOULD_MOVE, self::ALREADY_MOVED, self::NOT_ON_PRODUCT, self::FAILED ),
								),
								'variations' => array( 'type' => 'integer' ),
								'reason'     => array( 'type' => 'string' ),
								'message'    => array( 'type' => 'string' ),
								'before'     => array( 'type' => 'object' ),
							),
							'required'   => array( 'product_id', 'status' ),
						),
					),
					'more'     => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'dry_run', 'to', 'products', 'more' ),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => static fn (): bool => current_user_can( self::CAPABILITY ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input As the input schema.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input    = (array) $input;
		$from     = is_string( $input['from'] ?? null ) ? sanitize_text_field( $input['from'] ) : '';
		$taxonomy = self::taxonomy( is_string( $input['to'] ?? null ) ? $input['to'] : '' );
		if ( null === $taxonomy ) {
			return new WP_Error(
				'pixonaut_wc_ability_unknown_attribute',
				__( 'No global attribute has this slug. Create it with create-product-attribute first.', 'pixonaut-for-woocommerce' ),
				array( 'status' => WP_Http::BAD_REQUEST )
			);
		}
		$term_map = array_filter( is_array( $input['term_map'] ?? null ) ? $input['term_map'] : array(), 'is_string' );
		$dry_run  = true === ( $input['dry_run'] ?? false );
		$named    = is_array( $input['product_ids'] ?? null );
		$more     = false;
		if ( $named ) {
			$ids = array_map( 'intval', $input['product_ids'] );
		} else {
			$page = wc_get_products(
				array(
					'type'     => self::PRODUCT_TYPES,
					'status'   => self::STATUSES,
					'limit'    => Abilities::integer( $input, 'per_page', self::DEFAULT_PER_PAGE ),
					'page'     => Abilities::integer( $input, 'page', 1 ),
					'orderby'  => 'ID',
					'order'    => 'ASC',
					'return'   => 'ids',
					'paginate' => true,
				)
			);
			if ( ! is_object( $page ) ) {
				return new WP_Error( 'pixonaut_wc_ability_no_products', __( 'The store\'s products could not be read.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::INTERNAL_SERVER_ERROR ) );
			}
			$ids  = array_map( 'intval', (array) ( $page->products ?? array() ) );
			$more = Abilities::integer( $input, 'page', 1 ) < (int) ( $page->max_num_pages ?? 0 );
		}
		$rows = array();
		foreach ( $ids as $id ) {
			$row = $this->move_one( $id, wc_get_product( $id ), $from, $taxonomy, $term_map, $dry_run );
			if ( $named || self::NOT_ON_PRODUCT !== $row['status'] ) {
				$rows[] = $row;
			}
		}
		return array(
			'dry_run'  => $dry_run,
			'to'       => $taxonomy,
			'products' => $rows,
			'more'     => $more,
		);
	}

	/**
	 * Moves one product, or says why not.
	 *
	 * @param int                   $id       The product's id.
	 * @param mixed                 $product  What `wc_get_product()` found.
	 * @param string                $from     The custom attribute.
	 * @param string                $taxonomy The global attribute's taxonomy.
	 * @param array<string, string> $term_map A term for values not named as one.
	 * @param bool                  $dry_run  Whether to change nothing.
	 * @return array<string, mixed> One row of the answer.
	 */
	private function move_one( int $id, mixed $product, string $from, string $taxonomy, array $term_map, bool $dry_run ): array {
		if ( ! $product instanceof WC_Product || ! $product->is_type( self::PRODUCT_TYPES ) ) {
			return self::failed( $id, self::REASON_NOT_A_PRODUCT, __( 'No simple or variable product has this id.', 'pixonaut-for-woocommerce' ) );
		}
		if ( ! current_user_can( self::PRODUCT_CAPABILITY, $id ) ) {
			return self::failed( $id, self::REASON_NOT_ALLOWED, __( 'You may not edit this product.', 'pixonaut-for-woocommerce' ) );
		}
		try {
			$move = AttributeMove::plan( $product, $from, $taxonomy, $term_map );
			if ( null === $move ) {
				return array(
					'product_id' => $id,
					'status'     => isset( $product->get_attributes()[ $taxonomy ] ) ? self::ALREADY_MOVED : self::NOT_ON_PRODUCT,
				);
			}
			$before = $move->before();
			if ( ! $dry_run ) {
				$move->apply();
			}
		} catch ( AttributeMoveRefused $refused ) {
			return self::failed( $id, $refused->reason, $refused->getMessage() );
		} catch ( Throwable $failure ) {
			$this->logger->error(
				sprintf( 'Moving product %d from attribute %s to %s failed: %s: %s', $id, $from, $taxonomy, $failure::class, $failure->getMessage() )
			);
			return self::failed( $id, self::REASON_SAVE_FAILED, __( 'The product could not be saved. Nothing was changed.', 'pixonaut-for-woocommerce' ) );
		}
		return array(
			'product_id' => $id,
			'status'     => $dry_run ? self::WOULD_MOVE : self::MOVED,
			'variations' => count( $move->variation_ids() ),
			'before'     => $before,
		);
	}

	/**
	 * A product left as it was.
	 *
	 * @param int    $id      The product's id.
	 * @param string $reason  A code.
	 * @param string $message What to do about it.
	 * @return array<string, mixed> One row of the answer.
	 */
	private static function failed( int $id, string $reason, string $message ): array {
		return array(
			'product_id' => $id,
			'status'     => self::FAILED,
			'reason'     => $reason,
			'message'    => $message,
		);
	}

	/**
	 * The taxonomy of a global attribute named by its slug, with or without
	 * the prefix; null when there is none.
	 *
	 * @param string $slug The slug.
	 */
	private static function taxonomy( string $slug ): ?string {
		$slug     = sanitize_title( $slug );
		$taxonomy = str_starts_with( $slug, self::TAXONOMY_PREFIX ) ? $slug : wc_attribute_taxonomy_name( $slug );
		return wc_attribute_taxonomy_id_by_name( $taxonomy ) > 0 && taxonomy_exists( $taxonomy ) ? $taxonomy : null;
	}
}
