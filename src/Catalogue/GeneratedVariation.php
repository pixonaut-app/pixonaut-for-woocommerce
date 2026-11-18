<?php
/**
 * What a product's plan did to one of its variations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;
use WP_Post;

/**
 * A variation the plan made or disabled says so in its
 * `_pixonaut_generated` meta, so generating again tells a variation the
 * plan disabled, which it publishes again once offered, from one the store
 * manager disabled, which it leaves. A variation the store manager
 * publishes or disables afterwards is theirs again: its mark goes.
 */
final class GeneratedVariation {

	public const META_KEY = Schema::VARIATION_GENERATED_META;

	/**
	 * The plan made the variation, or published it again.
	 */
	public const MADE = 'made';

	/**
	 * The plan disabled the variation.
	 */
	public const DISABLED = 'disabled';

	/**
	 * Arguments of `transition_post_status`.
	 */
	private const TRANSITION_ARGS = 3;

	/**
	 * Whether a run is writing variations now, so their status changes keep their marks.
	 *
	 * @var bool
	 */
	public static bool $writing = false;

	/**
	 * Forgets the mark of a variation whose status someone else changes.
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'transitioned' ), 10, self::TRANSITION_ARGS );
	}

	/**
	 * @param string  $new_status Its status now.
	 * @param string  $old_status Its status before.
	 * @param WP_Post $post       The post.
	 */
	public static function transitioned( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! self::$writing && $new_status !== $old_status && 'product_variation' === $post->post_type ) {
			delete_post_meta( $post->ID, self::META_KEY );
		}
	}

	/**
	 * Whether the plan disabled the variation.
	 *
	 * @param WC_Product $variation A variation.
	 */
	public static function disabled( WC_Product $variation ): bool {
		return self::DISABLED === $variation->get_meta( self::META_KEY, true, 'edit' );
	}

	/**
	 * Notes what the plan did; the variation is saved by the caller.
	 *
	 * @param WC_Product $variation A variation.
	 * @param string     $what      `MADE` or `DISABLED`.
	 */
	public static function mark( WC_Product $variation, string $what ): void {
		$variation->update_meta_data( self::META_KEY, $what );
	}
}
