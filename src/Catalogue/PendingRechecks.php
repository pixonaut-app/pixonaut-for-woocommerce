<?php
/**
 * Which products already wait in a queued re-check.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use ActionScheduler;
use ActionScheduler_Store;
use InvalidArgumentException;
use Pixonaut\WooCommerce\Schema;

/**
 * Each product or variation queued for a re-check holds the id of the job
 * it was queued in. While that job waits to start and still names the
 * product, the product is not queued again, whatever batch a later event
 * would put it in: batches are cut from the products found at the time, so
 * a product added or moved between two events shifts every batch after it.
 *
 * Only a job that waits counts. One running may have read Pixonaut before
 * the change that asks for the next re-check, and one done, cancelled or
 * gone counts for nothing, so a mark it leaves behind is harmless; a
 * product copied with its meta is not named by its original's job.
 */
final class PendingRechecks {

	public const META = Schema::PRODUCT_RECHECK_JOB_META;

	/**
	 * The products of a list not already waiting in a re-check.
	 *
	 * @param int[] $product_ids Products and variations.
	 * @phpstan-param list<int> $product_ids
	 * @return list<int>
	 */
	public static function not_waiting( array $product_ids ): array {
		if ( array() === $product_ids ) {
			return array();
		}
		update_meta_cache( 'post', $product_ids );
		$waiting = array();
		return array_values(
			array_filter(
				$product_ids,
				static function ( int $product_id ) use ( &$waiting ): bool {
					$job = (int) get_post_meta( $product_id, self::META, true );
					if ( 0 === $job ) {
						return true;
					}
					$waiting[ $job ] ??= self::waiting_in( $job );
					return ! in_array( $product_id, $waiting[ $job ], true );
				}
			)
		);
	}

	/**
	 * Notes the job the products were queued in.
	 *
	 * @param int[] $product_ids Products and variations.
	 * @param int   $job         The job's id.
	 * @phpstan-param list<int> $product_ids
	 */
	public static function mark( array $product_ids, int $job ): void {
		foreach ( $product_ids as $product_id ) {
			update_post_meta( $product_id, self::META, $job );
		}
	}

	/**
	 * Forgets the job the products were queued in, once it has started.
	 *
	 * @param int[] $product_ids Products and variations.
	 * @phpstan-param list<int> $product_ids
	 */
	public static function forget( array $product_ids ): void {
		foreach ( self::not_waiting( $product_ids ) as $product_id ) {
			delete_post_meta( $product_id, self::META );
		}
	}

	/**
	 * The products a re-check job names while it waits to start.
	 *
	 * @param int $job The job's id.
	 * @return list<int> Empty when it is running, done, cancelled or gone.
	 */
	private static function waiting_in( int $job ): array {
		$store = ActionScheduler::store();
		try {
			if ( ActionScheduler_Store::STATUS_PENDING !== $store->get_status( (string) $job ) ) {
				return array();
			}
		} catch ( InvalidArgumentException $e ) {
			return array();
		}
		$action = $store->fetch_action( (string) $job );
		$args   = $action->get_args();
		if ( ProductRecheck::ACTION !== $action->get_hook() || ! is_array( $args['product_ids'] ?? null ) ) {
			return array();
		}
		return array_values( array_map( 'intval', $args['product_ids'] ) );
	}
}
