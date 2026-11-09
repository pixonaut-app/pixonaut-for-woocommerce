<?php
/**
 * Editing an order line's design after the customer asked for changes to
 * its proof.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Closure;
use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\EditRefused;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Catalogue\MovedAttributes;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\CorrectionMount;
use Pixonaut\WooCommerce\Storefront\HostedCorrection;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsUpdateRequestOption1;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Variation;

/**
 * A proof page whose customer asked for changes sends them back with the
 * `state` the store signed for the line; the store opens the line's product
 * page with that `state` as `?pixonaut_order_edit=`. While the line waits
 * for the customer and still holds the revision the proof showed, the side
 * mounts on a correction link, its variation and quantity locked, and the
 * button saves the change and approves it with consent `edit` (`save()`).
 * The same `state` opens the page again until the line holds another
 * revision ("this proof has changed") or the side is approved (editing
 * closed).
 *
 * A product on the Personalise button layout has no designer on its page:
 * the `state` opens Pixonaut's hosted correction page instead
 * (`HostedCorrection`), and its return saves the change the same way.
 */
final class OrderEdit {

	/**
	 * The query argument carrying the signed `state`.
	 */
	public const PARAM = 'pixonaut_order_edit';

	public const CONSENT_KIND = 'edit';

	private const DESIGN_APPROVED = 'approved';

	/**
	 * Prefix of a variation attribute's key, which the order line's meta key leaves out.
	 */
	private const ATTRIBUTE_PREFIX = 'attribute_';

	/**
	 * Tries to record the change when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	private readonly Closure $clock;

	/**
	 * Mount options by `state`, minted once a request.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $minted = array();

	/**
	 * @param ProofState      $state      Verifies the `state` a proof page carried back.
	 * @param Sdk             $pixonaut   Saves and approves the change.
	 * @param CorrectionMount $correction Opens the design for a correction.
	 * @param Settings        $settings   The default layout and approval mode.
	 * @param Labels          $labels     Customer-facing text.
	 * @param LoggerInterface $logger     Logs changes Pixonaut refused.
	 * @param Closure         $schedule   `fn(int $item_id): bool`, queues an approved line's fulfilment job.
	 * @param Closure|null    $clock      `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly ProofState $state,
		private readonly Sdk $pixonaut,
		private readonly CorrectionMount $correction,
		private readonly Settings $settings,
		private readonly Labels $labels,
		private readonly LoggerInterface $logger,
		private readonly Closure $schedule,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'button_text' ), 10, 2 );
	}

	/**
	 * The line a `state` names, with whether its design can be changed now;
	 * null when the `state` is not the store's or names no tracked line.
	 *
	 * @param string $state A `state` as the proof page carried it back.
	 */
	public function find( string $state ): ?OrderEditLine {
		$values = $this->state->verify( $state );
		$item   = null === $values ? null : WC_Order_Factory::get_order_item( $values['item_id'] );
		$order  = $item instanceof WC_Order_Item_Product ? wc_get_order( $item->get_order_id() ) : null;
		if ( null === $values || ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order || $order->get_id() !== $values['order_id'] || ! LineState::is_tracked( $item ) ) {
			return null;
		}
		$line = LineState::of( $item );
		$side = $line->side_of_design( $values['design_id'] );
		if ( null === $side ) {
			return null;
		}
		$revision  = $line->side_ref( $side, LineState::SIDE_REVISION );
		$edit_base = $line->side_ref( $side, LineState::SIDE_EDIT_BASE );
		return new OrderEditLine(
			$state,
			$order,
			$item,
			$side,
			$values['design_id'],
			$values['revision'],
			OrderEditLine::status(
				$line->state(),
				(string) $line->ref( LineState::REF_APPROVAL_MODE ),
				DesignApproved::stopped_for( $order, $line ),
				true === $line->side_ref( $side, LineState::SIDE_APPROVED ),
				is_int( $revision ) ? $revision : null,
				is_int( $edit_base ) ? $edit_base : null,
				$values['revision']
			)
		);
	}

	/**
	 * The page that edits the line's design: its product page for a product
	 * that personalises inline, Pixonaut's hosted correction page (through
	 * `HostedCorrection`) for one on the Personalise button layout; null
	 * when its product no longer personalises.
	 *
	 * @param OrderEditLine $line The line.
	 */
	public function page_url( OrderEditLine $line ): ?string {
		$product = $line->item->get_product();
		$config  = $this->config( $line );
		if ( ! $product instanceof WC_Product || null === $config ) {
			return null;
		}
		if ( Layout::BUTTON === Layout::resolve( $config, $this->settings ) ) {
			return HostedCorrection::order_url( $line->state );
		}
		// A variation's link selects the attributes bought, those it allows any value for included.
		$permalink = $product instanceof WC_Product_Variation ? $product->get_permalink( array( 'variation' => self::bought_attributes( $product, $line->item ) ) ) : $product->get_permalink();
		return add_query_arg( self::PARAM, rawurlencode( $line->state ), $permalink );
	}

	/**
	 * The attribute values an order line bought a variation with, keyed as
	 * a cart item's `variation` is: `attribute_<name>`.
	 *
	 * @param WC_Product_Variation  $variation The variation.
	 * @param WC_Order_Item_Product $item      The order line.
	 *
	 * @return array<string, string>
	 */
	private static function bought_attributes( WC_Product_Variation $variation, WC_Order_Item_Product $item ): array {
		$attributes = array();
		foreach ( $variation->get_variation_attributes() as $key => $value ) {
			// An "Any …" attribute is empty on the variation; the order line keeps the value bought.
			$name               = substr( $key, strlen( self::ATTRIBUTE_PREFIX ) );
			$bought             = '' === $value ? (string) $item->get_meta( $name ) : $value;
			$attributes[ $key ] = '' === $bought ? MovedAttributes::bought( $variation->get_parent_id(), $name, $item ) : $bought;
		}
		return $attributes;
	}

	/**
	 * The order line this request opens on the page of `$product`, or null
	 * when it opens none of that product's.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	public function line_for( WC_Product $product ): ?OrderEditLine {
		// The signed state is the check; the link changes nothing by itself.
		$state = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$line  = '' === $state ? null : $this->find( $state );
		return null !== $line && $line->item->get_product_id() === $product->get_id() ? $line : null;
	}

	/**
	 * How the customer approves on the page: the product's approval mode now.
	 *
	 * @param OrderEditLine $line The line.
	 */
	public function approval_mode( OrderEditLine $line ): string {
		$config = $this->config( $line );
		return ApprovalMode::resolve( $config?->approval_mode, $this->settings );
	}

	/**
	 * The side's correction mount options, minted once a request; empty
	 * when the design cannot be changed now.
	 *
	 * @param OrderEditLine $line    The line.
	 * @param WC_Product    $product The product the page shows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function mounts( OrderEditLine $line, WC_Product $product ): array {
		if ( ! $line->open() ) {
			return array();
		}
		if ( ! isset( $this->minted[ $line->state ] ) ) {
			$bought                       = $line->item->get_product();
			$options                      = $this->correction->options( $line->design_id, $product, $line->side, $this->config( $line ), $bought instanceof WC_Product ? $bought : null );
			$this->minted[ $line->state ] = null === $options ? array() : array( $line->side => $options );
		}
		return $this->minted[ $line->state ];
	}

	/**
	 * What the page says in place of the designer when the design cannot be
	 * changed, or null when it can.
	 *
	 * @param OrderEditLine $line The line.
	 */
	public function closed_message( OrderEditLine $line ): ?string {
		$context = array( 'item_id' => $line->item->get_id() );
		return match ( $line->status ) {
			OrderEditLine::CHANGED => $this->labels->get( Labels::PROOF_CHANGED, $context ),
			OrderEditLine::CLOSED  => $this->labels->get( Labels::EDIT_CLOSED, $context ),
			default                => null,
		};
	}

	/**
	 * The add-to-cart button saves and approves the change while the page
	 * edits an order line's design.
	 *
	 * @param mixed $text    The button's text.
	 * @param mixed $product The product.
	 */
	public function button_text( mixed $text, mixed $product = null ): mixed {
		return $product instanceof WC_Product && true === $this->line_for( $product )?->open()
			? $this->labels->get( Labels::APPROVE_CHANGES, array( 'product_id' => $product->get_id() ) )
			: $text;
	}

	/**
	 * Saves the customer's change: Pixonaut makes the correction's proof the
	 * design's next revision and the line takes it, then the store approves
	 * it with consent `edit`. Once every side of the line is approved, the
	 * line goes on to the printshop.
	 *
	 * @param string $state       The `state` that opened the page.
	 * @param int    $item_id     The order line the route names.
	 * @param string $side        The side changed.
	 * @param string $proof_token The correction's proof token.
	 * @param bool   $approved    Whether the approval box was ticked, for the checkbox approval mode.
	 * @param array  $fields      The design's field values now; none from the hosted designer.
	 * @phpstan-param list<FieldValue> $fields
	 *
	 * @return string The page the customer goes on to: their order.
	 *
	 * @throws EditRefused When the change is refused.
	 */
	public function save( string $state, int $item_id, string $side, string $proof_token, bool $approved, array $fields = array() ): string {
		$line = $this->find( $state );
		if ( null === $line || $line->item->get_id() !== $item_id || $line->side !== $side ) {
			throw new EditRefused( EditRefused::NOT_EDITABLE );
		}
		if ( OrderEditLine::CHANGED === $line->status ) {
			throw new EditRefused( EditRefused::PROOF_CHANGED );
		}
		if ( ! $line->open() ) {
			throw new EditRefused( EditRefused::EDIT_CLOSED );
		}
		if ( ApprovalMode::CHECKBOX === $this->approval_mode( $line ) && ! $approved ) {
			throw new EditRefused( EditRefused::APPROVAL_REQUIRED );
		}
		if ( ! $this->correction->available() ) {
			throw new EditRefused( EditRefused::UNAVAILABLE );
		}
		try {
			$corrected = $this->pixonaut->designs()->update( $line->design_id, new DesignsUpdateRequestOption1( proofToken: $proof_token ) );
		} catch ( ApiException $error ) {
			throw $this->refused( $line, $error );
		}
		$this->record( $line, $corrected->revision, $corrected->proofId, $fields );

		$consent = array(
			'kind' => self::CONSENT_KIND,
			'at'   => Milliseconds::of( ( $this->clock )() ),
		);
		$ref     = LineState::of( $line->item )->ref( LineState::REF_EXTERNAL_REF );
		try {
			$this->pixonaut->designs()->approve( $line->design_id, Designs::approval( is_string( $ref ) && '' !== $ref ? $consent + array( 'ref' => $ref ) : $consent, $corrected->revision ) );
		} catch ( ApiException $error ) {
			// Approved at this revision already, by a repeat of this request: it stands.
			if ( ErrorCode::NOT_COMMITTED !== $error->errorCode || ! $this->approved_at( $line->design_id, $corrected->revision ) ) {
				throw $this->refused( $line, $error );
			}
		}
		$this->approve( $line, $corrected->revision, $consent );
		return $line->order->get_checkout_order_received_url();
	}

	/**
	 * The configuration of the product the line bought, when it personalises.
	 *
	 * @param OrderEditLine $line The line.
	 */
	private function config( OrderEditLine $line ): ?ProductConfig {
		// The variation bought, whose configuration may override its product's.
		$product = $line->item->get_product();
		$config  = $product instanceof WC_Product ? ProductConfig::config_for( $product )->for_designer() : null;
		return null !== $config && $config->personalise ? $config : null;
	}

	/**
	 * The line takes the corrected design's revision and proof, and keeps
	 * the revision the change started from until it is approved, so the page
	 * that made it stays open when the approval fails.
	 *
	 * @param OrderEditLine $line     The line.
	 * @param int           $revision The design's revision now.
	 * @param string|null   $proof_id Its proof now.
	 * @param array         $fields   Its field values now; values it did not report are dropped, as they may no longer be the design's.
	 * @phpstan-param list<FieldValue> $fields
	 *
	 * @throws EditRefused When the line holds a newer revision already, or kept changing.
	 */
	private function record( OrderEditLine $line, int $revision, ?string $proof_id, array $fields ): void {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$current = LineState::of( $line->item );
			if ( (int) $current->side_ref( $line->side, LineState::SIDE_REVISION ) > $revision ) {
				// Changed again elsewhere since this correction: never step the line back.
				throw new EditRefused( EditRefused::REVISION_CHANGED );
			}
			$current->set_side_ref( $line->side, LineState::SIDE_REVISION, $revision );
			$current->set_side_ref( $line->side, LineState::SIDE_EDIT_BASE, $line->revision );
			if ( null !== $proof_id ) {
				$current->set_side_ref( $line->side, LineState::SIDE_PROOF_ID, $proof_id );
			}
			$current->set_side_ref( $line->side, LineState::SIDE_FIELDS, array() === $fields ? null : FieldValue::list_to_array( $fields ) );
			if ( $current->save() ) {
				return;
			}
		}
		throw new EditRefused( EditRefused::NOT_SAVED );
	}

	/**
	 * Records the side as approved at `$revision` with the customer's
	 * consent; once every side is, the line is approved and its fulfilment
	 * job queued. Nothing is recorded for a side that holds another revision
	 * by now, or an order stopped meanwhile.
	 *
	 * @param OrderEditLine                $line     The line.
	 * @param int                          $revision The revision Pixonaut approved.
	 * @param array{kind: string, at: int} $consent  The customer's consent.
	 *
	 * @throws EditRefused When the side holds another revision, the order was stopped, or the line kept changing.
	 */
	private function approve( OrderEditLine $line, int $revision, array $consent ): void {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$current = LineState::of( $line->item );
			if ( LineState::AWAITING_CUSTOMER !== $current->state() ) {
				// Approved meanwhile, by Pixonaut's event about this approval.
				return;
			}
			if ( $current->side_ref( $line->side, LineState::SIDE_REVISION ) !== $revision ) {
				throw new EditRefused( EditRefused::REVISION_CHANGED );
			}
			$order = wc_get_order( $line->item->get_order_id() );
			if ( ! $order instanceof WC_Order || DesignApproved::stopped_for( $order, $current ) ) {
				$line->order->add_order_note(
					/* translators: %s: product name of the order line. */
					sprintf( __( 'Pixonaut: the customer approved a change to the design of "%s" after the order failed or the item was cancelled, so it was not sent to the printshop.', 'pixonaut-for-woocommerce' ), $line->item->get_name() )
				);
				throw new EditRefused( EditRefused::EDIT_CLOSED );
			}
			$current->set_side_ref( $line->side, LineState::SIDE_APPROVED, true );
			$current->set_side_ref( $line->side, LineState::SIDE_EDIT_BASE, null );
			$current->set_ref( LineState::REF_CONSENT, $consent );
			$waiting = array_filter( $current->sides(), static fn ( string $key ): bool => true !== $current->side_ref( $key, LineState::SIDE_APPROVED ) );
			if ( array() !== $waiting ) {
				if ( $current->save() ) {
					$this->note( $line );
					return;
				}
				continue;
			}
			if ( $current->transition( LineState::APPROVED ) ) {
				$this->note( $line );
				if ( ! ( $this->schedule )( $line->item->get_id() ) ) {
					// The line is approved; Pixonaut's `design.approved` queues the job again.
					$this->logger->error( 'The fulfilment job of a line approved after its changes could not be queued.', array( 'item' => $line->item->get_id() ) );
				}
				return;
			}
		}
		throw new EditRefused( EditRefused::NOT_SAVED );
	}

	/**
	 * @param OrderEditLine $line The line.
	 */
	private function note( OrderEditLine $line ): void {
		$line->order->add_order_note(
			/* translators: %s: product name of the order line. */
			sprintf( __( 'Pixonaut: the customer changed the design of "%s" and approved it.', 'pixonaut-for-woocommerce' ), $line->item->get_name() )
		);
	}

	/**
	 * Whether a design is approved at a revision.
	 *
	 * @param string $design_id The design.
	 * @param int    $revision  The revision.
	 *
	 * @throws EditRefused When Pixonaut could not be reached.
	 */
	private function approved_at( string $design_id, int $revision ): bool {
		try {
			$design = Design::get( $this->pixonaut, $design_id );
		} catch ( ApiException ) {
			throw new EditRefused( EditRefused::NOT_SAVED );
		}
		return self::DESIGN_APPROVED === $design->status && $design->revision === $revision;
	}

	/**
	 * The refusal the customer sees for an error Pixonaut answered.
	 *
	 * @param OrderEditLine $line  The line.
	 * @param ApiException  $error The error.
	 */
	private function refused( OrderEditLine $line, ApiException $error ): EditRefused {
		$refusal = EditRefused::for_error( $error->errorCode );
		if ( EditRefused::NOT_SAVED === $refusal->reason ) {
			$this->logger->warning( sprintf( 'Could not save the change of design %s: %s', $line->design_id, $error->getMessage() ), array( 'code' => $error->errorCode ) );
		}
		return $refusal;
	}
}
