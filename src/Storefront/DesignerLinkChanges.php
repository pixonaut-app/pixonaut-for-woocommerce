<?php
/**
 * Follows Pixonaut withdrawing or regenerating a kept designer link.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Api\DesignerLinks;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;

/**
 * A member can withdraw or regenerate a store's designer link in the
 * studio. `share_link.revoked` forgets the link wherever it is kept, so the
 * product mints a new one when it is next shown; `share_link.regenerated`
 * keeps the address the link opens now, which the event does not carry and
 * `GET /v1/share-links` answers. A regenerated link no longer listed is
 * forgotten as a withdrawn one is; one whose address Pixonaut cannot tell
 * now is forgotten and withdrawn, so the product mints a link that works
 * rather than keep the address that stopped. An event about a link no
 * product keeps, such as one the store withdrew itself, changes nothing.
 */
final class DesignerLinkChanges {

	public const REVOKED = 'share_link.revoked';

	public const REGENERATED = 'share_link.regenerated';

	/**
	 * @param KeptDesignerLinks $links  The links kept for each product.
	 * @param DesignerLinks     $api    Lists the store's working designer links.
	 * @param LoggerInterface   $logger Logs a regenerated link whose address could not be read.
	 */
	public function __construct(
		private readonly KeptDesignerLinks $links,
		private readonly DesignerLinks $api,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The handlers of the share-link events, by event type.
	 *
	 * @return array<string, callable(Event): void>
	 */
	public function handlers(): array {
		return array_fill_keys( array( self::REVOKED, self::REGENERATED ), array( $this, 'handle' ) );
	}

	/**
	 * @param Event $event A `share_link.revoked` or `share_link.regenerated` event.
	 *
	 * @throws RuntimeException When another request is minting a product's links; the event is handled again later.
	 */
	public function handle( Event $event ): void {
		$id      = Payload::string( $event, 'linkId' );
		$subject = Payload::string( $event, 'subjectId' );
		if ( null === $id || null === $subject || DesignerLinks::KIND !== Payload::string( $event, 'kind' ) || array() === $this->links->holders( $id ) ) {
			return;
		}
		if ( self::REVOKED === $event->type ) {
			$this->links->forget( $id );
			return;
		}
		try {
			$url = $this->api->address( $subject, $id );
		} catch ( ApiException $error ) {
			$this->logger->warning( sprintf( 'Could not read the new address of designer link %s, so it is withdrawn and minted again: %s', $id, $error->getMessage() ), array( 'code' => $error->errorCode ) );
			$this->links->retire( $id );
			return;
		}
		if ( null === $url ) {
			$this->links->forget( $id );
			return;
		}
		$this->links->readdress( $id, $url );
	}
}
