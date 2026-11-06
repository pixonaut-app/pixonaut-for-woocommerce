<?php
/**
 * The ability that acts on a personalised order line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Fulfilment\LineActionResult;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use WP_Error;
use WP_Http;

/**
 * Runs one of the actions the order screen's Pixonaut box offers for a
 * line (retry, order again anyway, settle an unknown outcome, cancel, or a
 * sandbox rehearsal) through the same code (`LineActions::run()`), so the
 * line's bookkeeping and order notes are the same. Pixonaut decides what
 * a line's order allows; an action it no longer offers is not sent.
 * Whoever may act on a line on the order screen may run it.
 */
final class ActOnOrderLine implements Ability {

	/**
	 * Results of an action that did something.
	 */
	private const SUCCEEDED = array( LineActions::DONE, LineActions::RETRY_STILL_PENDING );

	/**
	 * Prefix of an error's code, followed by the action's result code.
	 */
	private const ERROR_PREFIX = 'pixonaut_wc_line_';

	/**
	 * @param LineActions $actions The actions a line offers.
	 * @param LineView    $view    Shows a line.
	 */
	public function __construct(
		private readonly LineActions $actions,
		private readonly LineView $view,
	) {
	}

	public function name(): string {
		return 'act-on-order-line';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Act on a personalised order line', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Runs one of the actions the order screen offers for a personalised order line: `retry` places its printshop order again, `reorder` orders it although its design already has a printshop order, `settle_submitted` or `settle_failed` says whether the printshop has an order whose outcome is unknown (with the `reason`, what the printshop said), `cancel` asks the printshop to stop it, and `rehearse_<status>` moves a sandbox order on. `list-personalised-orders` with the `order_id` gives the actions each line offers now.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'order_id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'item_id'       => array(
						'type'        => 'integer',
						'description' => __( 'The line\'s `item_id`.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'action'        => array(
						'type' => 'string',
						'enum' => LineView::action_ids(),
					),
					'reason'        => array(
						'type'        => 'string',
						'description' => __( 'What the printshop said; required to settle, kept with the order in Pixonaut.', 'pixonaut-for-woocommerce' ),
						'default'     => '',
						'maxLength'   => LineActions::REASON_MAX_LENGTH,
					),
					'printshop_ref' => array(
						'type'        => 'string',
						'description' => __( 'The printshop\'s own reference for the order, when it has it; for `settle_submitted` only.', 'pixonaut-for-woocommerce' ),
						'default'     => '',
						'maxLength'   => LineActions::ORDER_REF_MAX_LENGTH,
					),
				),
				'required'             => array( 'order_id', 'item_id', 'action' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'result'  => array(
						'type'        => 'string',
						'description' => __( '`done`, or `retry_still_pending` when the order was placed again but is not on its way yet.', 'pixonaut-for-woocommerce' ),
						'enum'        => self::SUCCEEDED,
					),
					'message' => array( 'type' => 'string' ),
					'line'    => LineView::schema(),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => static fn (): bool => current_user_can( OrderBox::CAPABILITY ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
		);
	}

	/**
	 * @param mixed $input `order_id`, `item_id`, `action`, `reason` and `printshop_ref`.
	 * @return array{result: string, message: string, line: array<string, mixed>}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$item_id = Abilities::integer( $input, 'item_id', 0 );
		$line    = OrderBox::line( Abilities::integer( $input, 'order_id', 0 ), $item_id );
		if ( null === $line ) {
			return new WP_Error( self::ERROR_PREFIX . 'not_found', __( 'The order has no personalised line with that item id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		$result = $this->actions->run(
			$line,
			is_string( $input['action'] ?? null ) ? $input['action'] : '',
			is_string( $input['reason'] ?? null ) ? $input['reason'] : '',
			is_string( $input['printshop_ref'] ?? null ) ? $input['printshop_ref'] : ''
		);
		if ( ! in_array( $result->code, self::SUCCEEDED, true ) ) {
			return self::failed( $result );
		}
		$line    = LineState::of( $line->item() );
		$offered = null;
		try {
			list( $line, $offered ) = $this->actions->offered( $line );
		} catch ( ApiException $e ) {
			// The action ran; what the line offers next is read on the next listing.
			$offered = null;
		}
		return array(
			'result'  => $result->code,
			'message' => LineActions::RETRY_STILL_PENDING === $result->code
				/* translators: %s: the new printshop order's status in Pixonaut, e.g. queued. */
				? sprintf( __( 'The order was placed again, but its status in Pixonaut is "%s".', 'pixonaut-for-woocommerce' ), $result->detail )
				: __( 'Done.', 'pixonaut-for-woocommerce' ),
			'line'    => $this->view->of( $line, $offered ),
		);
	}

	/**
	 * What an action that did nothing, or may not have, answers. A refusal
	 * carries Pixonaut's code as its own, as a failed call does
	 * (AbilityError).
	 *
	 * @param LineActionResult $result What came of the action.
	 */
	private static function failed( LineActionResult $result ): WP_Error {
		$refused = LineActions::REFUSED === $result->code && '' !== $result->detail;
		return new WP_Error(
			$refused ? AbilityError::PREFIX . $result->detail : self::ERROR_PREFIX . $result->code,
			self::message( $result ),
			array(
				'result' => $result->code,
				'code'   => $refused ? $result->detail : $result->code,
				'status' => self::status( $result->code ),
			)
		);
	}

	/**
	 * The REST status of an action that did nothing, or may not have.
	 *
	 * @param string $code The action's result code.
	 */
	private static function status( string $code ): int {
		return match ( $code ) {
			LineActions::NOT_AVAILABLE,
			LineActions::STATUS_NOT_ALLOWED,
			LineActions::REFERENCE_TAKEN,
			LineActions::LINE_CHANGED   => WP_Http::CONFLICT,
			LineActions::REASON_MISSING => WP_Http::BAD_REQUEST,
			LineActions::UNREACHABLE    => WP_Http::GATEWAY_TIMEOUT,
			LineActions::NOT_SCHEDULED  => WP_Http::INTERNAL_SERVER_ERROR,
			default                     => WP_Http::BAD_GATEWAY,
		};
	}

	/**
	 * @param LineActionResult $result What came of the action.
	 */
	private static function message( LineActionResult $result ): string {
		if ( '' !== $result->message ) {
			return $result->message;
		}
		return match ( $result->code ) {
			LineActions::NOT_AVAILABLE      => __( 'The line does not offer that action now. List the order with its order_id to see the actions it offers.', 'pixonaut-for-woocommerce' ),
			LineActions::REASON_MISSING     => __( 'Settling needs a reason: what the printshop said about the order.', 'pixonaut-for-woocommerce' ),
			LineActions::STATUS_NOT_ALLOWED => __( 'The order changed in Pixonaut meanwhile, so nothing was changed. List the order again to see the actions it offers now.', 'pixonaut-for-woocommerce' ),
			LineActions::REFERENCE_TAKEN    => __( 'Another order in Pixonaut has that printshop reference. Nothing was changed; check the reference.', 'pixonaut-for-woocommerce' ),
			LineActions::LINE_CHANGED       => __( 'The line changed while the action ran. List the order again to see where it stands.', 'pixonaut-for-woocommerce' ),
			LineActions::UNREACHABLE        => __( 'Pixonaut could not be reached, so it is not known whether it took the action. List the order again to see where the line stands.', 'pixonaut-for-woocommerce' ),
			LineActions::NOT_SCHEDULED      => __( 'The line could not be scheduled to go again, so it still needs attention. Check WooCommerce > Status > Scheduled Actions, then retry.', 'pixonaut-for-woocommerce' ),
			/* translators: %s: the error code Pixonaut answered, e.g. order_at_printshop. */
			default                         => sprintf( __( 'Pixonaut refused it with "%s". Nothing was changed.', 'pixonaut-for-woocommerce' ), $result->detail ),
		};
	}
}
