<?php
/**
 * A personalised order line as the abilities show it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Proofs\ProofUrls;

/**
 * A line's proofs, where it stands on its way to the printshop and, when
 * its printshop order was read for them, the actions it offers, as the
 * order screen's Pixonaut box shows them.
 */
final class LineView {

	/**
	 * @param ProofUrls $urls Signs the proof pictures' URLs.
	 */
	public function __construct( private readonly ProofUrls $urls ) {
	}

	/**
	 * The JSON schema of a line.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'item_id'           => array( 'type' => 'integer' ),
				'product_id'        => array( 'type' => 'integer' ),
				'name'              => array( 'type' => 'string' ),
				'quantity'          => array( 'type' => 'integer' ),
				'state'             => array(
					'type'        => 'string',
					'description' => __( 'Where the line stands.', 'pixonaut-for-woocommerce' ),
					'enum'        => LineState::STATES,
				),
				'state_label'       => array( 'type' => 'string' ),
				'reason'            => array(
					'type'        => 'string',
					'description' => __( 'Why the line waits, when it needs the merchant or Pixonaut holds its order; empty otherwise.', 'pixonaut-for-woocommerce' ),
				),
				'pixonaut_order_id' => array( 'type' => 'string' ),
				'production_status' => array(
					'type'        => 'string',
					'description' => __( 'The Pixonaut order\'s status as last reported, e.g. `submitted`, `printed` or `shipped`; empty before the line is ordered.', 'pixonaut-for-woocommerce' ),
				),
				'delivery'          => array(
					'type'        => 'string',
					'description' => __( 'Where the line\'s last change of delivery address stands; empty when its address never changed after it was ordered.', 'pixonaut-for-woocommerce' ),
				),
				'actions'           => array(
					'type'        => array( 'array', 'null' ),
					'description' => __( 'The actions `act-on-order-line` can run on the line now, as Pixonaut allows them; null when they were not read.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type' => 'string',
						'enum' => self::action_ids(),
					),
				),
				'sides'             => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'       => array( 'type' => 'string' ),
							'design_id' => array( 'type' => 'string' ),
							'proof_url' => array(
								'type'        => 'string',
								'description' => __( 'The proof picture on the store; empty when the side has none yet or its personal data was erased.', 'pixonaut-for-woocommerce' ),
							),
							'approved'  => array(
								'type'        => 'boolean',
								'description' => __( 'Whether the customer approved the side\'s proof.', 'pixonaut-for-woocommerce' ),
							),
							'erased'    => array(
								'type'        => 'boolean',
								'description' => __( 'Whether the design\'s personal data was erased in Pixonaut.', 'pixonaut-for-woocommerce' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Every action id a line may offer.
	 *
	 * @return list<string>
	 */
	public static function action_ids(): array {
		return array_merge(
			array( LineActions::RETRY, LineActions::REORDER, LineActions::SETTLE_SUBMITTED, LineActions::SETTLE_FAILED, LineActions::CANCEL ),
			array_map( static fn ( string $status ): string => LineActions::REHEARSE . $status, LineActions::REHEARSALS )
		);
	}

	/**
	 * @param LineState         $line    The line.
	 * @param list<string>|null $actions The actions it offers; null when they were not read.
	 * @return array<string, mixed>
	 */
	public function of( LineState $line, ?array $actions = null ): array {
		$item     = $line->item();
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		$status   = $line->ref( LineState::REF_ORDER_STATUS );
		return array(
			'item_id'           => $item->get_id(),
			'product_id'        => $item->get_variation_id() > 0 ? $item->get_variation_id() : $item->get_product_id(),
			'name'              => $item->get_name(),
			'quantity'          => $item->get_quantity(),
			'state'             => $line->state(),
			'state_label'       => OrderBox::state_label( $line->state(), is_string( $status ) ? $status : null ),
			'reason'            => OrderBox::reason( $line ),
			'pixonaut_order_id' => is_string( $order_id ) ? $order_id : '',
			'production_status' => is_string( $status ) ? $status : '',
			'delivery'          => OrderBox::delivery_label( $line ),
			'actions'           => $actions,
			'sides'             => $this->sides( $line ),
		);
	}

	/**
	 * @param LineState $line The line.
	 * @return list<array{key: string, design_id: string, proof_url: string, approved: bool, erased: bool}>
	 */
	private function sides( LineState $line ): array {
		$sides = array();
		foreach ( $line->sides() as $side ) {
			$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			$proof_id  = $line->side_ref( $side, LineState::SIDE_PROOF_ID );
			$design_id = is_string( $design_id ) ? $design_id : '';
			$erased    = $line->is_side_erased( $side );
			$has_proof = ! $erased && '' !== $design_id && is_string( $proof_id ) && '' !== $proof_id;
			$sides[]   = array(
				'key'       => $side,
				'design_id' => $design_id,
				'proof_url' => $has_proof ? $this->urls->url( $design_id, $proof_id, ProofUrls::LARGEST_WIDTH ) : '',
				'approved'  => true === $line->side_ref( $side, LineState::SIDE_APPROVED ),
				'erased'    => $erased,
			);
		}
		return $sides;
	}
}
