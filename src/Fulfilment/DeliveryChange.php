<?php
/**
 * Sends a changed shipping address to a line's printshop order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use ActionScheduler_Store;
use Closure;
use InvalidArgumentException;
use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Api\Answers\OrderDelivery;
use Pixonaut\WooCommerce\Api\StoredRequest;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersDeliveryUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use RuntimeException;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * Saving an order whose shipping or billing address changed schedules a job
 * for each of its lines that has a printshop order. The job maps the address
 * as the order was placed with it (OrderAddress::of()) and, when it differs
 * from the one last sent, sends it with `PATCH /v1/orders/{id}/delivery`
 * under a key of its own, keeping the request so a retry of the change sends
 * the same bytes once. What the printshop made of it is noted on the order
 * and kept on the line, for the order screen, until `order.delivery_changed`
 * (OrderDeliveryChanged) says more. A refusal is noted, and the same address
 * is not sent again. Saving the order on its screen sends again a change
 * whose answer never came, and an address the store could not send.
 *
 * A line not yet ordered needs no job: its order is placed with the address
 * the order has then. The line keeps only a fingerprint of each address,
 * never the address, besides the request while it is being sent.
 */
final class DeliveryChange {

	/**
	 * Action Scheduler hook of the job, with the order item id as its one argument.
	 */
	public const ACTION = 'pixonaut_change_delivery';

	// What the printshop made of a change, as Pixonaut's `delivery.state` says it.
	public const SENDING  = 'sending';
	public const ACCEPTED = 'accepted';
	public const REFUSED  = 'refused';
	public const CARRIED  = 'carried';

	// Fields of the line's `delivery` ref.
	// Fingerprint of the address the printshop order has, as far as the store knows.
	public const FIELD_ADDRESS = 'address';
	// Changes the line made, numbering their keys.
	private const FIELD_CHANGE = 'change';
	// Fingerprint of the address being sent, its key and the request as sent, while the change is unanswered.
	public const FIELD_SENDING = 'sending';
	public const FIELD_KEY     = 'key';
	public const FIELD_REQUEST = 'request';
	// Transient failures in a row while the change was sent.
	private const FIELD_FAILURES = 'failures';
	// Fingerprint of an address Pixonaut took but is still telling the printshop, until `order.delivery_changed` says what came of it.
	public const FIELD_AWAITING = 'awaiting';
	// Fingerprint of the last address Pixonaut refused, so it is not sent again.
	public const FIELD_REFUSED = 'refused';
	// What came of the last change: Pixonaut's `delivery.state` and the printshop's `words`, or the `code` Pixonaut refused it with, and when.
	public const FIELD_STATE = 'state';
	public const FIELD_WORDS = 'words';
	public const FIELD_CODE  = 'code';
	public const FIELD_AT    = 'at';

	/**
	 * The order address groups whose change may change where an order ships:
	 * the shipping address, and the billing one it falls back to and takes
	 * the email and phone from.
	 */
	private const ADDRESS_GROUPS = array( 'shipping', 'billing' );

	/**
	 * Tries to write the line when another request wrote it meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Milliseconds per second, for the time a change was answered.
	 */
	private const MS_PER_SECOND = 1000;

	/**
	 * Priority on `woocommerce_process_shop_order_meta`: after WooCommerce
	 * saved the order's items (priorities 10 to 50), so the lines read are
	 * the ones it saved.
	 */
	private const RESUME_PRIORITY = 100;

	/**
	 * Why the job is tried again when the line changed while it ran, for the log.
	 */
	private const LINE_CHANGED = 'line_changed';

	// Why the store does not act on a line in Pixonaut, for the log.
	private const NOT_CONNECTED   = 'not_connected';
	private const OTHER_WORKSPACE = 'other_workspace';

	/**
	 * Ids of the orders being saved with a changed address.
	 *
	 * @var array<int, true>
	 */
	private array $changed = array();

	/**
	 * @param Sdk             $pixonaut   Sends the change.
	 * @param Closure         $connection `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param LoggerInterface $logger     Logs the changes Pixonaut refused or the store could not schedule.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly Closure $connection,
		private readonly LoggerInterface $logger,
	) {
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ) );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'before_save' ) );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'after_save' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'resume' ), self::RESUME_PRIORITY );
	}

	/**
	 * Remembers an order whose address is about to be saved changed.
	 *
	 * @param mixed $order The order being saved.
	 */
	public function before_save( mixed $order ): void {
		if ( ! $order instanceof WC_Order || 0 === $order->get_id() ) {
			return;
		}
		if ( array() !== array_intersect_key( $order->get_changes(), array_flip( self::ADDRESS_GROUPS ) ) ) {
			$this->changed[ $order->get_id() ] = true;
		}
	}

	/**
	 * Schedules the job now for each line with a printshop order of an order
	 * saved with a changed address, in place of any it had pending: a retry
	 * of an earlier change waiting on the backoff would hold the new address
	 * back. Only the job writes the line: an order saved on its screen saves
	 * its items after this, from what the screen loaded.
	 *
	 * @param mixed $order The order saved.
	 */
	public function after_save( mixed $order ): void {
		if ( ! $order instanceof WC_Order || ! isset( $this->changed[ $order->get_id() ] ) ) {
			return;
		}
		unset( $this->changed[ $order->get_id() ] );
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) || ! self::sendable( LineState::of( $item ) ) ) {
				continue;
			}
			$args = array( 'item_id' => $item->get_id() );
			as_unschedule_all_actions( self::ACTION, $args, FulfilmentJobs::GROUP );
			if ( 0 === as_enqueue_async_action( self::ACTION, $args, FulfilmentJobs::GROUP ) ) {
				$this->logger->error( 'Delivery address change could not be scheduled.', array( 'item' => $item->get_id() ) );
			}
		}
	}

	/**
	 * The order was saved on its screen: each line with a change whose answer
	 * never came, or with an address the store could not send (it was not
	 * connected), gets its job again, the backoff counted from the start.
	 *
	 * @param int|string $order_id The order.
	 */
	public function resume( int|string $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
				continue;
			}
			$line = LineState::of( $item );
			if ( ! self::sendable( $line ) ) {
				continue;
			}
			$delivery = self::of( $line );
			if ( isset( $delivery[ self::FIELD_SENDING ] ) ) {
				try {
					$this->update( $item, static fn ( array $now ): array => array( self::FIELD_FAILURES => 0 ) + $now );
				} catch ( RuntimeException $e ) {
					// The job goes on from the count it finds.
					$this->logger->warning( 'Delivery address change backoff not reset: ' . $e->getMessage(), array( 'item' => $item->get_id() ) );
				}
			} elseif ( self::settled( $delivery, OrderAddress::fingerprint( OrderAddress::of( $order, $item ) ) ) ) {
				continue;
			}
			if ( ! self::schedule( $item->get_id() ) ) {
				$this->logger->error( 'Delivery address change could not be scheduled.', array( 'item' => $item->get_id() ) );
			}
		}
	}

	/**
	 * Schedules the line's job unless one is pending. A running job does not
	 * count: it may have read the address before the change was saved.
	 *
	 * @param int $item_id The order item.
	 *
	 * @return bool False when Action Scheduler would not store the job.
	 */
	public static function schedule( int $item_id ): bool {
		return self::pending( $item_id ) || 0 !== as_enqueue_async_action( self::ACTION, array( 'item_id' => $item_id ), FulfilmentJobs::GROUP );
	}

	/**
	 * Whether the line's job is pending (a running one does not count).
	 *
	 * @param int $item_id The order item.
	 */
	public static function pending( int $item_id ): bool {
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'args'     => array( 'item_id' => $item_id ),
				'group'    => FulfilmentJobs::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		return array() !== $pending;
	}

	/**
	 * Whether the line still has an address to send: a change is on its way,
	 * or the address is not one the printshop order has, Pixonaut is telling
	 * the printshop or refused already.
	 *
	 * @param LineState $line        The line.
	 * @param string    $fingerprint The order's address for the line (`OrderAddress::fingerprint()`).
	 */
	public static function unsent( LineState $line, string $fingerprint ): bool {
		$delivery = self::of( $line );
		return isset( $delivery[ self::FIELD_SENDING ] ) || ! self::settled( $delivery, $fingerprint );
	}

	/**
	 * Whether the line's printshop order may take a new address: it has one,
	 * the line is not final or being cancelled, and the order's personal data
	 * was not erased.
	 *
	 * @param LineState $line The line.
	 */
	public static function sendable( LineState $line ): bool {
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		return is_string( $order_id ) && '' !== $order_id
			&& ! in_array( $line->state(), LineState::FINAL, true )
			&& null === $line->ref( LineState::REF_CANCEL )
			&& null === $line->ref( LineState::REF_ORDER_ERASED_AT );
	}

	/**
	 * The fields of the line's `delivery` ref.
	 *
	 * @param LineState $line The line.
	 *
	 * @return array<string, mixed>
	 */
	public static function of( LineState $line ): array {
		$delivery = $line->ref( LineState::REF_DELIVERY );
		return is_array( $delivery ) ? $delivery : array();
	}

	/**
	 * Whether an address needs no sending while no change is on its way: the
	 * printshop order has it, Pixonaut is telling the printshop it, or
	 * Pixonaut refused it already.
	 *
	 * @param array<string, mixed> $delivery    The line's `delivery` ref.
	 * @param string               $fingerprint The address.
	 */
	private static function settled( array $delivery, string $fingerprint ): bool {
		foreach ( array( self::FIELD_ADDRESS, self::FIELD_AWAITING, self::FIELD_REFUSED ) as $field ) {
			if ( ( $delivery[ $field ] ?? null ) === $fingerprint ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The job: sends the order's address when it is not the one the
	 * printshop order has.
	 *
	 * @param int|string $item_id The order item.
	 */
	public function run( int|string $item_id ): void {
		$item = WC_Order_Factory::get_order_item( (int) $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			$this->send( $item, $order );
		} catch ( RuntimeException $e ) {
			// Another request wrote the line meanwhile: start over from where it stands.
			if ( ! $this->again( $item, self::LINE_CHANGED, null ) ) {
				$this->logger->error( 'Delivery address change given up: ' . $e->getMessage(), array( 'item' => $item->get_id() ) );
			}
		}
	}

	/**
	 * @param WC_Order_Item_Product $item  The line.
	 * @param WC_Order              $order Its order.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function send( WC_Order_Item_Product $item, WC_Order $order ): void {
		$line = LineState::of( $item );
		if ( ! self::sendable( $line ) ) {
			return;
		}
		$mapped      = OrderAddress::of( $order, $item );
		$fingerprint = OrderAddress::fingerprint( $mapped );
		$delivery    = self::of( $line );
		$sending     = $delivery[ self::FIELD_SENDING ] ?? null;
		// A change on its way may have arrived, so going back is sent too.
		if ( null === $sending && self::settled( $delivery, $fingerprint ) ) {
			return;
		}
		$unacted = $this->unacted( $line );
		if ( null !== $unacted ) {
			$this->logger->warning(
				'Delivery address change not sent: the store does not act for the workspace of the line.',
				array(
					'item'   => $item->get_id(),
					'reason' => $unacted,
				)
			);
			$this->note(
				$item,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: the new address of "%s" was not sent: the store is not connected to the Pixonaut workspace the order was placed under. Update the order once it is connected to send it.', 'pixonaut-for-woocommerce' )
			);
			return;
		}
		if ( ! isset( $mapped['recipient'], $mapped['shippingAddress'] ) ) {
			$noted = $this->settle(
				$item,
				is_string( $sending ) ? $sending : null,
				null,
				array(
					self::FIELD_CODE    => ErrorCode::BAD_REQUEST,
					self::FIELD_AT      => time() * self::MS_PER_SECOND,
					self::FIELD_REFUSED => $fingerprint,
				)
			);
			if ( $noted ) {
				$this->note(
					$item,
					/* translators: %s: product name of the order line. */
					__( 'Pixonaut: the new address of "%s" was not sent to the printshop: it needs a name, a street, a city and a country.', 'pixonaut-for-woocommerce' )
				);
			}
			return;
		}
		$request = $sending === $fingerprint ? self::stored_request( $delivery ) : null;
		if ( null === $request ) {
			$request = $this->start(
				$item,
				$fingerprint,
				(string) $line->ref( LineState::REF_ORDER_ID ),
				array(
					'recipient'       => $mapped['recipient'],
					'shippingAddress' => $mapped['shippingAddress'],
				)
			);
			if ( null === $request ) {
				return;
			}
		}
		try {
			$answer = Order::update_delivery( $this->pixonaut, $request );
		} catch ( ApiException $e ) {
			$this->refused( $item, $fingerprint, $e );
			return;
		}
		$this->answered( $item, $fingerprint, $answer->delivery );
		// The address changed again while this one was sent.
		$saved = wc_get_order( $order->get_id() );
		if ( $saved instanceof WC_Order && OrderAddress::fingerprint( OrderAddress::of( $saved, $item ) ) !== $fingerprint ) {
			self::schedule( $item->get_id() );
		}
	}

	/**
	 * Stores a new change, numbered after the line's last, with its key and
	 * its request.
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $fingerprint The address to send.
	 * @param string                $order_id    The printshop order.
	 * @param array<string, mixed>  $body        `recipient` and `shippingAddress`.
	 *
	 * @return PreparedRequest|null The request to send; null when the order's personal data was erased meanwhile.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function start( WC_Order_Item_Product $item, string $fingerprint, string $order_id, array $body ): ?PreparedRequest {
		$delivery = $this->update(
			$item,
			function ( array $delivery ) use ( $item, $fingerprint, $order_id, $body ): array {
				$change = ( is_int( $delivery[ self::FIELD_CHANGE ] ?? null ) ? $delivery[ self::FIELD_CHANGE ] : 0 ) + 1;
				$site   = ( $this->connection )();
				$key    = Refs::delivery_key( $site instanceof StoredConnection ? $site->site : '', $item->get_id(), $change );
				// A code is what came of an earlier change, and with one on its way it says its answer never came.
				unset( $delivery[ self::FIELD_CODE ] );
				return array(
					self::FIELD_CHANGE   => $change,
					self::FIELD_SENDING  => $fingerprint,
					self::FIELD_KEY      => $key,
					self::FIELD_REQUEST  => $this->pixonaut->orders()->prepareUpdateDelivery( $order_id, OrdersDeliveryUpdateRequest::fromArray( $body ), $key )->toArray(),
					self::FIELD_FAILURES => 0,
				) + $delivery;
			}
		);
		return null === $delivery ? null : self::stored_request( $delivery );
	}

	/**
	 * The request of the change being sent, as it was first sent.
	 *
	 * @param array<string, mixed> $delivery The line's `delivery` ref.
	 */
	private static function stored_request( array $delivery ): ?PreparedRequest {
		$stored = $delivery[ self::FIELD_REQUEST ] ?? null;
		if ( ! is_array( $stored ) ) {
			return null;
		}
		try {
			return StoredRequest::restore( $stored );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Pixonaut took the change: its `delivery` says what the printshop made
	 * of it. One Pixonaut is still telling the printshop leaves the address
	 * as it was until `order.delivery_changed` says what came of it.
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $fingerprint The address sent.
	 * @param OrderDelivery|null    $delivery    The order's `delivery` as Pixonaut answered it.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function answered( WC_Order_Item_Product $item, string $fingerprint, ?OrderDelivery $delivery ): void {
		$outcome = self::outcome( $delivery, $fingerprint );
		$state   = $outcome[ self::FIELD_STATE ];
		$written = $this->settle(
			$item,
			$fingerprint,
			// A refused change leaves the printshop order with the address it had.
			in_array( $state, array( self::REFUSED, self::SENDING ), true ) ? null : $fingerprint,
			$outcome
		);
		if ( $written ) {
			$this->add_note( $item, self::explain( $state, $outcome[ self::FIELD_WORDS ], $item->get_name() ) );
		}
	}

	/**
	 * What the line's `delivery` ref keeps of Pixonaut's answer to a change:
	 * the state, the printshop's words and when, and the address as refused
	 * or awaited. An answer without a `delivery` is still being sent.
	 *
	 * @param OrderDelivery|null $delivery    The order's `delivery` as Pixonaut answered it.
	 * @param string             $fingerprint The address sent.
	 *
	 * @return array{state: string, words: string|null, at: int, refused: string|null, awaiting: string|null}
	 */
	public static function outcome( ?OrderDelivery $delivery, string $fingerprint ): array {
		$state = $delivery->state ?? self::SENDING;
		return array(
			self::FIELD_STATE    => $state,
			self::FIELD_WORDS    => null === $delivery?->words ? null : sanitize_text_field( $delivery->words ),
			self::FIELD_AT       => null === $delivery ? time() * self::MS_PER_SECOND : (int) $delivery->at,
			self::FIELD_REFUSED  => self::REFUSED === $state ? $fingerprint : null,
			self::FIELD_AWAITING => self::SENDING === $state ? $fingerprint : null,
		);
	}

	/**
	 * What a refused or failed change comes to: one that asking again may
	 * cure is sent again on the fulfilment backoff, and when the backoff is
	 * used up its answer is not known, so it stays the change on its way;
	 * any other is noted, and the address is not sent again.
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $fingerprint The address sent.
	 * @param ApiException          $error       What Pixonaut answered, or why no answer came.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function refused( WC_Order_Item_Product $item, string $fingerprint, ApiException $error ): void {
		// `address_change_unavailable` says by `retryable` whether asking again may work.
		if ( RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
			if ( ! $this->again( $item, $error->errorCode, $error->retryAfter ) ) {
				$this->unknown( $item, $fingerprint, $error );
			}
			return;
		}
		$this->logger->warning(
			'Delivery address change refused by Pixonaut.',
			array(
				'item'      => $item->get_id(),
				'operation' => $error->operation,
				'status'    => $error->status,
				'code'      => $error->errorCode,
			)
		);
		$written = $this->settle(
			$item,
			$fingerprint,
			null,
			array(
				self::FIELD_CODE    => $error->errorCode,
				self::FIELD_AT      => time() * self::MS_PER_SECOND,
				self::FIELD_REFUSED => $fingerprint,
			)
		);
		if ( $written ) {
			$this->add_note( $item, self::explain_refusal( $error, $item->get_name() ) );
		}
	}

	/**
	 * The change was tried as often as the backoff allows, and whether it
	 * reached the printshop is not known: it stays the change on its way,
	 * with its key and request, and the code of the last failure, so saving
	 * the order sends it again (resume()).
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $fingerprint The address sent.
	 * @param ApiException          $error       The last failure.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function unknown( WC_Order_Item_Product $item, string $fingerprint, ApiException $error ): void {
		$marked = false;
		$this->update(
			$item,
			static function ( array $delivery ) use ( $fingerprint, $error, &$marked ): array {
				$marked = ( $delivery[ self::FIELD_SENDING ] ?? null ) === $fingerprint;
				if ( $marked ) {
					$delivery[ self::FIELD_CODE ] = $error->errorCode;
				}
				return $delivery;
			}
		);
		if ( $marked ) {
			$this->add_note(
				$item,
				sprintf(
					/* translators: 1: product name of the order line, 2: the error code Pixonaut answered. */
					__( 'Pixonaut: it is not known whether the new address of "%1$s" reached the printshop: Pixonaut answered "%2$s". Update the order to send it again.', 'pixonaut-for-woocommerce' ),
					$item->get_name(),
					$error->errorCode
				)
			);
		}
	}

	/**
	 * Ends a change: keeps what came of it, and the address the printshop
	 * order has now, unless the line holds a later outcome.
	 *
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string|null           $settles The fingerprint of the change this ends; it is no longer on its way unless a later one started meanwhile. Null when none was on its way.
	 * @param string|null           $address The fingerprint of the address the printshop order has now; null when it did not change.
	 * @param array<string, mixed>  $outcome FIELD_STATE, FIELD_WORDS, FIELD_CODE, FIELD_AT, FIELD_REFUSED and FIELD_AWAITING as they are now; an absent one is cleared.
	 *
	 * @return bool False when the order's personal data was erased meanwhile, and nothing was written.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function settle( WC_Order_Item_Product $item, ?string $settles, ?string $address, array $outcome ): bool {
		return null !== $this->update(
			$item,
			static function ( array $delivery ) use ( $settles, $address, $outcome ): array {
				if ( null !== $settles && ( $delivery[ self::FIELD_SENDING ] ?? null ) === $settles ) {
					unset( $delivery[ self::FIELD_SENDING ], $delivery[ self::FIELD_KEY ], $delivery[ self::FIELD_REQUEST ], $delivery[ self::FIELD_FAILURES ] );
				}
				// `order.delivery_changed` brought a later outcome while the answer was on its way.
				if ( is_int( $delivery[ self::FIELD_AT ] ?? null ) && is_int( $outcome[ self::FIELD_AT ] ?? null ) && $delivery[ self::FIELD_AT ] > $outcome[ self::FIELD_AT ] ) {
					return $delivery;
				}
				if ( null !== $address ) {
					$delivery[ self::FIELD_ADDRESS ] = $address;
				}
				unset( $delivery[ self::FIELD_STATE ], $delivery[ self::FIELD_WORDS ], $delivery[ self::FIELD_CODE ], $delivery[ self::FIELD_AT ], $delivery[ self::FIELD_REFUSED ], $delivery[ self::FIELD_AWAITING ] );
				return array_filter( $outcome + $delivery, static fn ( mixed $value ): bool => null !== $value );
			}
		);
	}

	/**
	 * Writes the line's `delivery` ref, reading the line again when another
	 * request wrote it meanwhile. A line whose order's personal data was
	 * erased is left as it is: the erasure dropped the ref.
	 *
	 * @param WC_Order_Item_Product $item   The line.
	 * @param Closure               $change `fn(array $delivery): array`, the ref as it is to be.
	 *
	 * @return array<string, mixed>|null The ref as stored; null when the order's personal data was erased.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function update( WC_Order_Item_Product $item, Closure $change ): ?array {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( null !== $line->ref( LineState::REF_ORDER_ERASED_AT ) ) {
				return null;
			}
			$delivery = $change( self::of( $line ) );
			$line->set_ref( LineState::REF_DELIVERY, $delivery );
			if ( $line->save() ) {
				return $delivery;
			}
		}
		throw new RuntimeException( sprintf( 'Delivery address change of order item %d not stored: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Schedules the job again on the fulfilment backoff.
	 *
	 * @param WC_Order_Item_Product $item        The line.
	 * @param string                $code        Why it is tried again.
	 * @param int|null              $retry_after Seconds Pixonaut asked to wait, when it did.
	 *
	 * @return bool False when the backoff is used up or the job could not be scheduled; the caller gives up. True as well when the order's personal data was erased, as nothing is left to send.
	 */
	private function again( WC_Order_Item_Product $item, string $code, ?int $retry_after ): bool {
		$line = LineState::of( $item );
		if ( null !== $line->ref( LineState::REF_ORDER_ERASED_AT ) ) {
			return true;
		}
		$delivery = self::of( $line );
		$failures = is_int( $delivery[ self::FIELD_FAILURES ] ?? null ) ? $delivery[ self::FIELD_FAILURES ] : 0;
		$delay    = RetryPolicy::delay( $failures, $retry_after );
		if ( null === $delay ) {
			$this->logger->warning(
				'Delivery address change failed as often as the backoff allows; whether it arrived is not known.',
				array(
					'item'     => $item->get_id(),
					'code'     => $code,
					'failures' => $failures,
				)
			);
			return false;
		}
		$delivery[ self::FIELD_FAILURES ] = $failures + 1;
		// The change is being tried again: its answer is still to come.
		unset( $delivery[ self::FIELD_CODE ] );
		$line->set_ref( LineState::REF_DELIVERY, $delivery );
		// A line changed meanwhile keeps its own count; the job is scheduled all the same.
		$line->save();
		if ( 0 === as_schedule_single_action( time() + $delay, self::ACTION, array( 'item_id' => $item->get_id() ), FulfilmentJobs::GROUP ) ) {
			$this->logger->error( 'Delivery address change retry could not be scheduled.', array( 'item' => $item->get_id() ) );
			return false;
		}
		$this->logger->warning(
			'Delivery address change failed; retrying later.',
			array(
				'item'     => $item->get_id(),
				'code'     => $code,
				'failures' => $failures + 1,
				'delay'    => $delay,
			)
		);
		return true;
	}

	/**
	 * Why the store may not act on the line in Pixonaut; null when it may:
	 * it is connected, to the workspace the line was made under.
	 *
	 * @param LineState $line The line.
	 */
	private function unacted( LineState $line ): ?string {
		$connection = ( $this->connection )();
		if ( ! $connection instanceof StoredConnection ) {
			return self::NOT_CONNECTED;
		}
		return $line->ref( LineState::REF_TENANT_ID ) === $connection->tenant_id ? null : self::OTHER_WORKSPACE;
	}

	/**
	 * What the printshop made of a change, as an order note.
	 *
	 * @param string      $state Pixonaut's `delivery.state`.
	 * @param string|null $words The printshop's own answer to a refused change.
	 * @param string      $name  Product name of the order line.
	 */
	public static function explain( string $state, ?string $words, string $name ): string {
		if ( self::REFUSED === $state && null !== $words && '' !== $words ) {
			/* translators: 1: product name of the order line, 2: the printshop's answer. */
			return sprintf( __( 'Pixonaut: the printshop refused the new address of "%1$s": "%2$s". It ships to the address it had.', 'pixonaut-for-woocommerce' ), $name, $words );
		}
		$message = match ( $state ) {
			/* translators: %s: product name of the order line. */
			self::ACCEPTED => __( 'Pixonaut: the printshop took the new address of "%s".', 'pixonaut-for-woocommerce' ),
			/* translators: %s: product name of the order line. */
			self::CARRIED  => __( 'Pixonaut: the new address of "%s" goes to the printshop with the order, which it had not been sent yet.', 'pixonaut-for-woocommerce' ),
			/* translators: %s: product name of the order line. */
			self::REFUSED  => __( 'Pixonaut: the printshop refused the new address of "%s". It ships to the address it had.', 'pixonaut-for-woocommerce' ),
			/* translators: %s: product name of the order line. */
			default        => __( 'Pixonaut is telling the printshop the new address of "%s".', 'pixonaut-for-woocommerce' ),
		};
		return sprintf( $message, $name );
	}

	/**
	 * Why Pixonaut refused a change, as an order note.
	 *
	 * @param ApiException $error The refusal.
	 * @param string       $name  Product name of the order line.
	 */
	private static function explain_refusal( ApiException $error, string $name ): string {
		$detail = static fn ( string $field ): string => is_string( $error->details[ $field ] ?? null ) ? sanitize_text_field( $error->details[ $field ] ) : '';
		return match ( $error->errorCode ) {
			ErrorCode::ORDER_PAST_ADDRESS_CHANGE => sprintf(
				/* translators: 1: product name of the order line, 2: the printshop order's status in Pixonaut. */
				__( 'Pixonaut: the new address of "%1$s" was not sent: its printshop order is past changing its address ("%2$s" in Pixonaut). It ships to the address it had; ask the printshop.', 'pixonaut-for-woocommerce' ),
				$name,
				$detail( 'status' )
			),
			ErrorCode::ADDRESS_CHANGE_NOT_ACCEPTED => sprintf(
				/* translators: 1: product name of the order line, 2: the printshop's name. */
				__( 'Pixonaut: the new address of "%1$s" was not sent: its printshop, %2$s, takes no address changes through Pixonaut. It ships to the address it had; ask the printshop.', 'pixonaut-for-woocommerce' ),
				$name,
				/* translators: Fallback for a printshop name that is not known, inside an order note: "its printshop, the printshop, takes no address changes". Lower case. */
				'' === $detail( 'printshop' ) ? __( 'the printshop', 'pixonaut-for-woocommerce' ) : $detail( 'printshop' )
			),
			ErrorCode::POSTAL_CODE_REQUIRED => sprintf(
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: the new address of "%s" was not sent: its printshop needs a postcode. Add one and update the order.', 'pixonaut-for-woocommerce' ),
				$name
			),
			default => sprintf(
				/* translators: 1: product name of the order line, 2: the error code Pixonaut answered. */
				__( 'Pixonaut: the new address of "%1$s" was not sent: Pixonaut answered "%2$s". It ships to the address it had.', 'pixonaut-for-woocommerce' ),
				$name,
				$error->errorCode
			),
		};
	}

	/**
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $message A translated message with `%s` for the product name.
	 */
	private function note( WC_Order_Item_Product $item, string $message ): void {
		$this->add_note( $item, sprintf( $message, $item->get_name() ) );
	}

	/**
	 * @param WC_Order_Item_Product $item The line.
	 * @param string                $note The note, as it reads.
	 */
	private function add_note( WC_Order_Item_Product $item, string $note ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( $note );
		}
	}
}
