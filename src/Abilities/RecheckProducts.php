<?php
/**
 * The ability that re-checks every linked product against Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Connection\Connection;
use WP_Error;

/**
 * Clears the cached catalogue answers, then queues the re-check of every
 * linked product, as Recheck products in the developer tools does: after
 * a template is published or a blank changed in Pixonaut, each product's
 * validity and template version are read again rather than from what the
 * store cached. Whoever may manage the store may do it.
 */
final class RecheckProducts implements Ability {

	/**
	 * @param Connection     $connection The store's connection.
	 * @param CatalogueCache $cache      The cached catalogue answers.
	 * @param ProductRecheck $recheck    Re-checks linked products.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly CatalogueCache $cache,
		private readonly ProductRecheck $recheck,
	) {
	}

	public function name(): string {
		return 'recheck-products';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Recheck products against Pixonaut', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that lists products. */
				__( 'Clears the store\'s cached templates and blanks, then re-checks every linked product against Pixonaut in the background: run it after publishing a template or changing a blank, so products take the new version and say whether they can still be sold. %s shows each product\'s state once its re-check ran.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( ListProducts::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'cache_cleared' => array( 'type' => 'boolean' ),
					'queued'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the re-check was queued; false when Action Scheduler could not queue it.', 'pixonaut-for-woocommerce' ),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ConnectionStatus::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				// A re-check moves products to new template versions and can stop their sale.
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @return array{cache_cleared: bool, queued: bool}|WP_Error
	 */
	public function execute(): array|WP_Error {
		if ( null === $this->connection->current() || null !== $this->connection->paused() ) {
			return new WP_Error( 'pixonaut_wc_ability_not_connected', __( 'The store is not connected to Pixonaut, or the connection is paused, so products cannot be re-checked. Reading the connection says why.', 'pixonaut-for-woocommerce' ) );
		}
		$this->cache->flush();
		return array(
			'cache_cleared' => true,
			'queued'        => $this->recheck->queue_recheck_all(),
		);
	}
}
