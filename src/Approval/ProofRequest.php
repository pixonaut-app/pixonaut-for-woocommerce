<?php
/**
 * Asks the customer to approve their proof, when the line's approval mode is
 * proof by email.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ShareLinks;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use WC_Order;

/**
 * At payment, reads each of the line's designs. A line whose designs are all
 * approved at the line's revisions already, in the studio for one, goes on
 * as approved; a side approved already is marked so in the line either way.
 * Otherwise each design still waiting gets a proof page the
 * customer may decide on, stored in the line before the line moves to
 * `awaiting_customer`, which sends the customer the "proof ready" email. A
 * run that stops between two mints finds the stored pages and mints only the
 * missing ones.
 *
 * A design changed since it was bought or no longer waiting, and a mint
 * Pixonaut refuses, move the line to `needs_attention` with the code. A mint
 * that may succeed later is thrown, for the job to retry.
 */
final class ProofRequest {

	private const STATUS_APPROVED = 'approved';

	private const STATUS_COMMITTED = 'committed';

	/**
	 * @param Designs    $designs     Reads designs.
	 * @param ShareLinks $share_links Mints proof pages.
	 * @param ProofState $state       Signs what each page carries back.
	 * @param Closure    $deadline    `fn(): int` the approval deadline in days.
	 * @param Closure    $language    `fn( string $locale ): ?string` the proof page's language for a WordPress locale, null for the workspace's default; see `DesignerLanguage::resolve()`.
	 */
	public function __construct(
		private readonly Designs $designs,
		private readonly ShareLinks $share_links,
		private readonly ProofState $state,
		private readonly Closure $deadline,
		private readonly Closure $language,
	) {
	}

	/**
	 * Takes a `committed` proof-by-email line to `approved` when its designs
	 * are approved already, else to `awaiting_customer`.
	 *
	 * @param LineState $line  A `committed` line whose approval mode is proof by email, its refs stored.
	 * @param WC_Order  $order The order.
	 *
	 * @return bool True when the line is `approved` and fulfilment goes on.
	 *
	 * @throws ApiError When Pixonaut could not be reached, or refused a read; a mint it refused moves the line to `needs_attention` instead.
	 */
	public function request( LineState $line, WC_Order $order ): bool {
		$waiting = array();
		foreach ( $line->sides() as $side ) {
			$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
			$revision  = $line->side_ref( $side, LineState::SIDE_REVISION );
			if ( ! is_string( $design_id ) || ! is_int( $revision ) ) {
				return false;
			}
			$design = $this->designs->get( $design_id );
			$status = $design['status'] ?? null;
			if ( ( $design['revision'] ?? null ) !== $revision ) {
				return $this->attention(
					$line,
					$order,
					NeedsAttention::DESIGN_CHANGED,
					/* translators: %s: product name of the order line. */
					sprintf( __( 'Pixonaut: the proof of "%s" was not sent. Its design was changed in Pixonaut after it was bought.', 'pixonaut-for-woocommerce' ), $line->item()->get_name() )
				);
			}
			if ( self::STATUS_APPROVED === $status ) {
				// Saved with the line's next write, so the customer's approval of the other sides completes it.
				$line->set_side_ref( $side, LineState::SIDE_APPROVED, true );
				continue;
			}
			if ( self::STATUS_COMMITTED !== $status ) {
				return $this->attention(
					$line,
					$order,
					NeedsAttention::DESIGN_NOT_WAITING,
					/* translators: %s: product name of the order line. */
					sprintf( __( 'Pixonaut: the proof of "%s" was not sent. Its design is no longer waiting for approval in Pixonaut.', 'pixonaut-for-woocommerce' ), $line->item()->get_name() )
				);
			}
			$waiting[] = $side;
		}

		if ( array() === $waiting ) {
			// Approved before payment, in the studio for one: nothing for the customer to do.
			return $line->transition( LineState::APPROVED );
		}

		foreach ( $waiting as $side ) {
			if ( null !== $line->side_ref( $side, LineState::SIDE_PROOF_URL ) ) {
				continue;
			}
			try {
				$link = $this->mint( $line, $order, $side );
			} catch ( ApiError $error ) {
				if ( RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
					throw $error;
				}
				return $this->attention(
					$line,
					$order,
					$error->error_code,
					/* translators: 1: product name of the order line, 2: error code Pixonaut answered. */
					sprintf( __( 'Pixonaut: the proof of "%1$s" was not sent. Pixonaut refused its proof page: %2$s.', 'pixonaut-for-woocommerce' ), $line->item()->get_name(), $error->error_code )
				);
			}
			$line->set_side_ref( $side, LineState::SIDE_PROOF_URL, $link['url'] );
			if ( ! $line->save() ) {
				return false;
			}
		}

		if ( $line->transition( LineState::AWAITING_CUSTOMER ) ) {
			$order->add_order_note(
				/* translators: %s: product name of the order line. */
				sprintf( __( 'Pixonaut: "%s" is awaiting design approval. The customer was sent a proof to approve.', 'pixonaut-for-woocommerce' ), $line->item()->get_name() )
			);
		}
		return false;
	}

	/**
	 * Mints the proof page of one side's design.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order The order.
	 * @param string    $side  A side key.
	 *
	 * @return array{url: string, expiresAt: int|null}
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function mint( LineState $line, WC_Order $order, string $side ): array {
		$item      = $line->item();
		$design_id = (string) $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
		return $this->share_links->proof_page(
			$design_id,
			ProofLink::days( ( $this->deadline )(), $item ),
			ProofLink::return_url( $item->get_id() ),
			$this->state->sign( $order->get_id(), $item->get_id(), $design_id, (int) $line->side_ref( $side, LineState::SIDE_REVISION ) ),
			( $this->language )( ProofLink::locale( $line ) )
		);
	}

	/**
	 * Moves the line to `needs_attention`, noting why the proof was not sent.
	 *
	 * @param LineState $line  The line.
	 * @param WC_Order  $order The order.
	 * @param string    $code  What stopped it: Pixonaut's code, or one of NeedsAttention's.
	 * @param string    $note  The translated order note.
	 *
	 * @return false The line does not go on.
	 */
	private function attention( LineState $line, WC_Order $order, string $code, string $note ): bool {
		NeedsAttention::flag( $line, $order, $code, $note );
		return false;
	}
}
