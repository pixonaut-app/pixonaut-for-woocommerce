<?php
/**
 * Keeps products in the trash out of the notices.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use WC_Product;

/**
 * A product or variation in the trash is not checked and raises no notice:
 * nobody can buy it either way. Trashing one drops its notices and its
 * variations', and restoring it checks it again, so a restored product that
 * still cannot be sold says so once more.
 */
final class TrashedProducts {

	private const POST_TYPES = array( 'product', 'product_variation' );

	/**
	 * Picks the product's notices out of every notice's key: its id, then what the notice is about.
	 */
	private const NOTICE_KEY_PATTERN = '/^product_(\d+)_(invalid|variants)$/';

	/**
	 * @param Notices        $notices Holds the notices.
	 * @param ProductRecheck $recheck Checks a restored product again.
	 */
	public function __construct(
		private readonly Notices $notices,
		private readonly ProductRecheck $recheck,
	) {
	}

	public function register(): void {
		add_action( 'trashed_post', array( $this, 'trashed' ) );
		add_action( 'untrashed_post', array( $this, 'untrashed' ) );
	}

	/**
	 * Whether the product, or a variation's product, is in the trash.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function in_trash( WC_Product $product ): bool {
		return self::post_in_trash( $product->get_id() ) || ( $product->get_parent_id() > 0 && self::post_in_trash( $product->get_parent_id() ) );
	}

	/**
	 * Drops the notices of every product or variation that is in the trash,
	 * or is gone.
	 *
	 * @param Notices $notices Holds the notices.
	 */
	public static function forget_notices( Notices $notices ): void {
		foreach ( array_keys( $notices->all() ) as $key ) {
			if ( 1 !== preg_match( self::NOTICE_KEY_PATTERN, $key, $match ) ) {
				continue;
			}
			$post = get_post( (int) $match[1] );
			if ( null === $post || self::post_in_trash( $post->ID ) || ( $post->post_parent > 0 && self::post_in_trash( $post->post_parent ) ) ) {
				$notices->remove( $key );
			}
		}
	}

	/**
	 * Drops the notices of a product, and of its variations, put in the trash.
	 *
	 * @param int $post_id The trashed post.
	 */
	public function trashed( int $post_id ): void {
		$post = get_post( $post_id );
		if ( null === $post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		foreach ( array( $post_id, ...self::variations( $post_id ) ) as $id ) {
			$this->notices->remove( InvalidProductNotice::key( $id ) );
		}
		$this->notices->remove( VariantNotice::key( $post_id ) );
		if ( $post->post_parent > 0 ) {
			// Its product's notice may name it among the variations that cannot be bought.
			$this->recheck->queue( array( $post->post_parent ) );
		}
	}

	/**
	 * Checks a product restored from the trash, and its variations, again.
	 *
	 * @param int $post_id The restored post.
	 */
	public function untrashed( int $post_id ): void {
		$post = get_post( $post_id );
		if ( null === $post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		$this->recheck->queue( array( $post_id, ...self::variations( $post_id ) ) );
	}

	/**
	 * @param int $post_id A post.
	 */
	private static function post_in_trash( int $post_id ): bool {
		return 'trash' === get_post_status( $post_id );
	}

	/**
	 * A product's variations, whatever their status: WooCommerce trashes
	 * and restores them with their product.
	 *
	 * @param int $product_id A product.
	 * @return list<int>
	 */
	private static function variations( int $product_id ): array {
		$ids = get_posts(
			array(
				'post_parent' => $product_id,
				'post_type'   => 'product_variation',
				'post_status' => array_keys( get_post_stati() ),
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
