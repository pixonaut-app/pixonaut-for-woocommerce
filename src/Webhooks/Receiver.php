<?php
/**
 * The webhook endpoint.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use Closure;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\EventType;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use UnexpectedValueException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/pixonaut/v1/webhook`, where Pixonaut delivers the store's
 * events. It verifies the signature with the connection's secret, hands the
 * event to the intake, which keeps it once and queues a job to handle it, and
 * answers at once, so Pixonaut never waits on the work. A retry or
 * redelivery of an event already kept is acknowledged and dropped. While the
 * site is not the one that connected, e.g. a copy of the store, every
 * delivery is answered with a retry, so the copy never keeps the store's
 * events.
 */
final class Receiver {

	public const ROUTE = '/webhook';

	private const OK = 200;

	private const BAD_REQUEST = 400;

	private const UNAUTHORIZED = 401;

	/**
	 * Pixonaut retries any answer that is not 2xx.
	 */
	private const RETRY_LATER = 503;

	private readonly Closure $clock;

	/**
	 * @param Connection      $connection Holds the signing secret.
	 * @param Intake          $intake     Keeps each new event and queues its job.
	 * @param LoggerInterface $logger     Logs refused deliveries; never secrets or bodies.
	 * @param Closure|null    $clock      `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Intake $intake,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				// Pixonaut is not a WordPress user: the signature is the authentication, checked in receive().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The delivery.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function receive( WP_REST_Request $request ): WP_REST_Response {
		if ( Connection::PAUSED_SITE_CHANGED === $this->connection->paused() ) {
			$this->logger->warning( 'Webhook refused for now: this site is not the one that connected, so Pixonaut delivers again later.' );
			return self::answer( self::RETRY_LATER, Connection::PAUSED_SITE_CHANGED );
		}
		$connection = $this->connection->current();
		$body       = $request->get_body();
		$verified   = null !== $connection && Signature::verify(
			$connection->webhook_secret,
			(string) $request->get_header( Signature::TIMESTAMP_HEADER ),
			(string) $request->get_header( Signature::SIGNATURE_HEADER ),
			$body,
			( $this->clock )()
		);
		if ( ! $verified ) {
			$this->logger->warning( null === $connection ? 'Webhook refused: the store is not connected.' : 'Webhook refused: the signature does not verify.' );
			return self::answer( self::UNAUTHORIZED, 'invalid_signature' );
		}
		try {
			$event = Event::fromJson( $body );
		} catch ( UnexpectedValueException $e ) {
			$this->logger->warning( 'Webhook refused: ' . $e->getMessage() );
			return self::answer( self::BAD_REQUEST, 'invalid_event' );
		}
		if ( EventType::WEBHOOK_PING === $event->type ) {
			return self::answer( self::OK, 'received' );
		}
		$taken = $this->intake->take( $event, $body );
		return match ( $taken ) {
			Intake::TAKEN => self::answer( self::OK, 'received' ),
			Intake::DUPLICATE => self::answer( self::OK, 'duplicate' ),
			Intake::TOO_LONG => self::answer( self::BAD_REQUEST, 'invalid_event' ),
			// Pixonaut retries; a new event's job refused was forgotten, so the retry is taken as new.
			Intake::NOT_KEPT, Intake::NOT_QUEUED => self::answer( self::RETRY_LATER, $taken ),
		};
	}

	/**
	 * @param int    $status HTTP status.
	 * @param string $result What happened to the delivery.
	 */
	private static function answer( int $status, string $result ): WP_REST_Response {
		return new WP_REST_Response( array( 'result' => $result ), $status );
	}
}
