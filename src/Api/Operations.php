<?php
/**
 * The operations the client may call.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use InvalidArgumentException;

/**
 * The client calls only operations registered here. Every one of them must be
 * listed in `contracts/pixonaut/consumed.json`, which a unit test checks.
 */
final class Operations {

	public const GET_CONNECT = 'GET /v1/connect';

	public const DELETE_CONNECT = 'DELETE /v1/connect';

	public const EXCHANGE_CONNECT_CODE = 'POST /v1/connect/token';

	public const UPDATE_CONNECT = 'PATCH /v1/connect';

	public const SET_STORE_ACCESS = 'PUT /v1/connect/store-access';

	public const DELETE_STORE_ACCESS = 'DELETE /v1/connect/store-access';

	public const LIST_WEBHOOKS = 'GET /v1/webhooks';

	public const UPDATE_WEBHOOK = 'PATCH /v1/webhooks/{id}';

	public const PING_WEBHOOK = 'POST /v1/webhooks/{id}/ping';

	public const SEARCH_TEMPLATES = 'GET /v1/templates';

	public const SEARCH_BLANKS = 'GET /v1/blanks';

	public const GET_BLANK = 'GET /v1/blanks/{id}';

	public const BLANK_MOCKUPS = 'GET /v1/blanks/{id}/mockups';

	public const BLANK_VARIANTS = 'GET /v1/blanks/{id}/variants';

	public const FITTING_TEMPLATES = 'GET /v1/blanks/{id}/placements/{placementId}/fitting-templates';

	public const GET_TEMPLATE = 'GET /v1/templates/{id}';

	public const GET_TEMPLATE_CONSTRAINTS = 'GET /v1/templates/{id}/constraints';

	public const CHECK_PRINT_SIZES = 'POST /v1/templates/{id}/print-size';

	public const TEMPLATE_MOCKUP = 'GET /t/{id}/mockup.png';

	public const TEMPLATE_PREVIEW = 'GET /t/{id}/preview.png';

	public const LIST_PRINTSHOP_CONNECTIONS = 'GET /v1/printshop-connections';

	public const CREATE_DESIGNER_LINK = 'POST /v1/templates/{id}/designer-links';

	public const COMMIT_DESIGN = 'POST /v1/designs';

	public const FIND_OR_CREATE_STANDING_DESIGN = 'POST /v1/designs/standing';

	public const GET_DESIGN = 'GET /v1/designs/{id}';

	public const GET_DESIGN_PROOF = 'GET /v1/designs/{id}/proof';

	public const DESIGN_MOCKUP = 'GET /v1/designs/{id}/mockup.png';

	public const DELETE_DESIGN = 'DELETE /v1/designs/{id}';

	public const SET_DESIGN_EXTERNAL_REF = 'PUT /v1/designs/{id}/external-ref';

	public const APPROVE_DESIGN = 'POST /v1/designs/{id}/approve';

	public const DUPLICATE_DESIGN = 'POST /v1/designs/{id}/duplicate';

	public const CORRECT_DESIGN = 'PATCH /v1/designs/{id}';

	public const CREATE_CORRECTION_LINK = 'POST /v1/designs/{id}/correction-links';

	public const REJECT_DESIGN = 'POST /v1/designs/{id}/reject';

	public const ERASE_DESIGN = 'POST /v1/designs/{id}/erase-personal-data';

	public const CREATE_ORDER = 'POST /v1/orders';

	public const LIST_ORDERS = 'GET /v1/orders';

	public const GET_ORDER = 'GET /v1/orders/{id}';

	public const LIST_EVENTS = 'GET /v1/events';

	public const REDELIVER_FAILED_WEBHOOKS = 'POST /v1/webhooks/{id}/deliveries/redeliver-failed';
	public const CREATE_SHARE_LINK         = 'POST /v1/share-links';

	public const LIST_SHARE_LINKS = 'GET /v1/share-links';

	public const UPDATE_SHARE_LINK = 'PATCH /v1/share-links/{id}';

	public const DELETE_SHARE_LINK = 'DELETE /v1/share-links/{id}';

	public const UPDATE_ORDER = 'PATCH /v1/orders/{id}';

	public const RETRY_ORDER = 'POST /v1/orders/{id}/retry';

	public const CANCEL_ORDER = 'POST /v1/orders/{id}/cancel';

	public const ERASE_ORDER = 'POST /v1/orders/{id}/erase-personal-data';

	public const CHANGE_ORDER_DELIVERY = 'PATCH /v1/orders/{id}/delivery';

	/**
	 * @var array<string, Operation>
	 */
	private array $operations = array();

	/**
	 * @param Operation[] $operations Operations, each id once.
	 */
	public function __construct( array $operations ) {
		foreach ( $operations as $operation ) {
			$this->operations[ $operation->id() ] = $operation;
		}
	}

	/**
	 * The operations the plugin calls.
	 */
	public static function plugin(): self {
		return new self(
			array(
				new Operation( 'GET', '/v1/connect', Credential::IntegrationKey ),
				new Operation( 'DELETE', '/v1/connect', Credential::IntegrationKey ),
				// Answers with the store's keys and webhook secret.
				new Operation( 'POST', '/v1/connect/token', Credential::None, sensitive: true ),
				new Operation( 'PATCH', '/v1/connect', Credential::IntegrationKey ),
				// Its body is the credential Pixonaut calls the store with.
				new Operation( 'PUT', '/v1/connect/store-access', Credential::IntegrationKey, sensitive: true ),
				new Operation( 'DELETE', '/v1/connect/store-access', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/webhooks', Credential::IntegrationKey ),
				new Operation( 'PATCH', '/v1/webhooks/{id}', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/webhooks/{id}/ping', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/templates', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/blanks', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/blanks/{id}', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/blanks/{id}/mockups', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/blanks/{id}/variants', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/blanks/{id}/placements/{placementId}/fitting-templates', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/templates/{id}', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/templates/{id}/constraints', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/templates/{id}/print-size', Credential::IntegrationKey ),
				// Takes a publishable or secret key only, never the integration key.
				new Operation( 'GET', '/t/{id}/mockup.png', Credential::PublishableKey, false, false, Operation::PNG ),
				new Operation( 'GET', '/t/{id}/preview.png', Credential::PublishableKey, false, false, Operation::PNG ),
				new Operation( 'GET', '/v1/printshop-connections', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/templates/{id}/designer-links', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/designs', Credential::IntegrationKey, true ),
				// One design per store, version, blank, placement and size: a repeat answers it again, so it takes no key.
				new Operation( 'POST', '/v1/designs/standing', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/designs/{id}', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/designs/{id}/proof', Credential::IntegrationKey, false, false, Operation::JPEG ),
				new Operation( 'GET', '/v1/designs/{id}/mockup.png', Credential::IntegrationKey, false, false, Operation::PNG ),
				new Operation( 'DELETE', '/v1/designs/{id}', Credential::IntegrationKey ),
				new Operation( 'PUT', '/v1/designs/{id}/external-ref', Credential::IntegrationKey ),
				// A repeat answers 200 again, so it takes no key.
				new Operation( 'POST', '/v1/designs/{id}/approve', Credential::IntegrationKey ),
				// A repeat of a redeemed proof is refused `revision_changed`, never applied twice.
				new Operation( 'PATCH', '/v1/designs/{id}', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/designs/{id}/correction-links', Credential::IntegrationKey ),
				// Pixonaut takes no key for it: a repeat makes another design, which the cart that asked for it deletes when it is left.
				new Operation( 'POST', '/v1/designs/{id}/duplicate', Credential::IntegrationKey ),
				// A repeat answers 200 again, so it takes no key.
				new Operation( 'POST', '/v1/designs/{id}/reject', Credential::IntegrationKey ),
				// A repeat answers the design unchanged, so it takes no key.
				new Operation( 'POST', '/v1/designs/{id}/erase-personal-data', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/orders', Credential::IntegrationKey, true ),
				new Operation( 'GET', '/v1/orders', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/orders/{id}', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/events', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/webhooks', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/webhooks/{id}/deliveries/redeliver-failed', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/share-links', Credential::IntegrationKey ),
				new Operation( 'GET', '/v1/share-links', Credential::IntegrationKey ),
				new Operation( 'PATCH', '/v1/share-links/{id}', Credential::IntegrationKey ),
				// Revoking a revoked link changes nothing, so it takes no key.
				new Operation( 'DELETE', '/v1/share-links/{id}', Credential::IntegrationKey ),
				new Operation( 'PATCH', '/v1/orders/{id}', Credential::IntegrationKey ),
				// Takes no key: a lost answer is found again as `order_superseded`.
				new Operation( 'POST', '/v1/orders/{id}/retry', Credential::IntegrationKey ),
				new Operation( 'POST', '/v1/orders/{id}/cancel', Credential::IntegrationKey ),
				// A repeat answers the order unchanged, so it takes no key.
				new Operation( 'POST', '/v1/orders/{id}/erase-personal-data', Credential::IntegrationKey ),
				new Operation( 'PATCH', '/v1/orders/{id}/delivery', Credential::IntegrationKey, true ),
			)
		);
	}

	/**
	 * @param string $id Operation id, e.g. `GET /v1/connect`.
	 *
	 * @throws InvalidArgumentException When the operation is not registered.
	 */
	public function get( string $id ): Operation {
		if ( ! isset( $this->operations[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unregistered Pixonaut operation: %s', $id ) );
		}
		return $this->operations[ $id ];
	}

	/**
	 * @return array<string, Operation> Keyed by id.
	 */
	public function all(): array {
		return $this->operations;
	}
}
