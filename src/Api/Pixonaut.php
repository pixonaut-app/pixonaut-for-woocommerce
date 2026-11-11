<?php
/**
 * Builds the Pixonaut SDK for the plugin.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Closure;
use InvalidArgumentException;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Config;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\CredentialProvider;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Operations;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\ArrayCatalogue;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Catalogue;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Listener;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Operation;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Client\ClientExceptionInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The only place the plugin builds the SDK: one client per timeout it uses,
 * each over the WordPress HTTP API, with the store's credentials, the
 * plugin's hooks and its logger. The SDK may send only the operations listed
 * in `OPERATIONS`, which `contracts/pixonaut/consumed.json` is generated from.
 */
final class Pixonaut {

	/**
	 * The SDK operations the plugin calls, by operation id.
	 */
	public const OPERATIONS = array(
		'connectedStore.get',
		'connectedStore.delete',
		// Answers with the store's keys and webhook secret.
		'connectedStore.exchangeCode',
		'connectedStore.update',
		// Its body is the credential Pixonaut calls the store with.
		'connectedStore.holdAccess',
		'connectedStore.cutAccess',
		'webhookEndpoints.list',
		'webhookEndpoints.update',
		'webhookEndpoints.ping',
		'webhookEndpoints.deliveries.redeliverFailed',
		'templates.list',
		'templates.get',
		'templates.constraints.get',
		'templates.printSize.validate',
		// Take a publishable or secret key only, never the integration key.
		'templates.mockup.get',
		'templates.preview.get',
		'templates.designerLinks.create',
		'blanks.list',
		'blanks.get',
		'blanks.mockups.list',
		'blanks.variants.list',
		'blanks.placements.fittingTemplates.list',
		'printshopConnections.list',
		// Not in the pinned SDK yet: see `PENDING`.
		'printshopCatalogueProducts.list',
		// A repeat answers the blank imported before, so it takes no key.
		'printshopCatalogueProducts.importBlank',
		'designs.commit',
		// One design per store, version, blank, placement and size: a repeat answers it again, so it takes no key.
		'designs.findOrCreateStanding',
		'designs.get',
		'designs.proof.get',
		'designs.mockup.get',
		'designs.delete',
		'designs.externalRef.update',
		// A repeat answers 200 again, so it takes no key.
		'designs.approve',
		// A repeat of a redeemed proof is refused `revision_changed`, never applied twice.
		'designs.update',
		'designs.correctionLinks.create',
		// Pixonaut takes no key for it: a repeat makes another design, which the cart that asked for it deletes when it is left.
		'designs.duplicate',
		// A repeat answers 200 again, so it takes no key.
		'designs.reject',
		// A repeat answers the design unchanged, so it takes no key.
		'designs.erasePersonalData',
		'orders.create',
		'orders.list',
		'orders.get',
		'orders.update',
		// Takes no key: a lost answer is found again as `order_superseded`.
		'orders.retry',
		'orders.cancel',
		// A repeat answers the order unchanged, so it takes no key.
		'orders.erasePersonalData',
		'orders.delivery.update',
		'events.list',
		'shareLinks.create',
		'shareLinks.list',
		'shareLinks.update',
		// Revoking a revoked link changes nothing, so it takes no key.
		'shareLinks.delete',
	);

	/**
	 * Operations the plugin calls that the pinned SDK does not describe yet,
	 * each as Pixonaut agreed to add it (pixonaut-app/pixonaut#3492). One
	 * the SDK has is taken from it instead, so moving to an SDK that has
	 * them changes nothing here but this list, which then empties.
	 *
	 * @var array<string, array{method: string, path: string}>
	 */
	private const PENDING = array(
		'printshopCatalogueProducts.list'        => array(
			'method' => 'GET',
			'path'   => '/v1/printshop-catalogue-products',
		),
		'printshopCatalogueProducts.importBlank' => array(
			'method' => 'POST',
			'path'   => '/v1/printshop-catalogue-products/{productId}/blank',
		),
	);

	/**
	 * The timeout of a call that must not hold up the merchant: telling
	 * Pixonaut the store disconnected or cut its access.
	 */
	public const SHORT_TIMEOUT_SECONDS = 5;

	/**
	 * The only method `all()` sends.
	 */
	private const READ = 'GET';

	/**
	 * Requests `all()` has out at once.
	 */
	public const MAX_CONCURRENT = 4;

	/**
	 * The SDK clients built so far, by timeout and origin.
	 *
	 * @var array<string, array{sdk: Sdk, http: BatchClient}>
	 */
	private array $clients = array();

	private readonly Closure $http;

	private readonly Closure $origin;

	/**
	 * Built once: it holds every operation of the spec the SDK was generated from.
	 *
	 * @var Catalogue|null
	 */
	private static ?Catalogue $catalogue = null;

	/**
	 * @param CredentialProvider $credentials The store's credentials, read at every send.
	 * @param LoggerInterface    $logger      Where the SDK logs unknown errors and deprecations.
	 * @param Listener           $listener    The plugin's hooks on every request.
	 * @param Closure|null       $http        `fn( int $timeout_seconds ): BatchClient`; a `WpHttpTransport` when null.
	 * @param string|null        $base_url    The API's address; `Hosts::server_api()` when null.
	 * @param Closure|null       $sleep       `fn( int $seconds ): void`, the wait before a resend; `sleep()` when null.
	 * @param Closure|null       $origin      `fn(): ?string`, the origin the publishable key is bound to; the site's when null.
	 */
	public function __construct(
		private readonly CredentialProvider $credentials,
		private readonly LoggerInterface $logger,
		private readonly Listener $listener,
		?Closure $http = null,
		private readonly ?string $base_url = null,
		private readonly ?Closure $sleep = null,
		?Closure $origin = null,
	) {
		$this->http   = $http ?? static fn ( int $timeout_seconds ): BatchClient => new WpHttpTransport( $timeout_seconds );
		$this->origin = $origin ?? static function (): ?string {
			$origin = Connection::origin( Connection::site_url() );
			return '' === $origin ? null : $origin;
		};
	}

	/**
	 * The SDK, giving up on each request after `$timeout_seconds`.
	 *
	 * @param int $timeout_seconds Give up on each send after this long.
	 */
	public function sdk( int $timeout_seconds = WpHttpTransport::TIMEOUT_SECONDS ): Sdk {
		return $this->client( $timeout_seconds )['sdk'];
	}

	/**
	 * Sends several reads at once, at most `MAX_CONCURRENT` at a time, each
	 * once, through the SDK's `toPsrRequest()` and `handle()`.
	 *
	 * @param PreparedRequest[] $requests From the SDK's `prepare()`; none may take an `Idempotency-Key`.
	 * @phpstan-param list<PreparedRequest> $requests
	 *
	 * @return list<Result|ApiException> Each request's result, or why Pixonaut refused it or could not be reached, in the order of `$requests`.
	 *
	 * @throws InvalidArgumentException When a request does not fit its operation, or is not a read (another method, a body, an `Idempotency-Key`); nothing is sent.
	 */
	public function all( array $requests ): array {
		foreach ( $requests as $request ) {
			// The batch sends no body: a write sent here would lose it.
			if ( null !== $request->idempotencyKey || null !== $request->body || self::READ !== self::catalogue()->operation( $request->operation )->method ) {
				throw new InvalidArgumentException( sprintf( '%s is not a read; send it on its own.', $request->operation ) );
			}
		}
		$client  = $this->client( WpHttpTransport::TIMEOUT_SECONDS );
		$sdk     = $client['sdk'];
		$results = array();
		foreach ( array_chunk( $requests, self::MAX_CONCURRENT, true ) as $chunk ) {
			$outgoing = array();
			foreach ( $chunk as $index => $request ) {
				try {
					$outgoing[ $index ] = $sdk->toPsrRequest( $request );
				} catch ( ApiException $error ) {
					$results[ $index ] = $error;
				}
			}
			$answers = array() === $outgoing ? array() : $client['http']->send_all( array_values( $outgoing ) );
			foreach ( array_keys( $outgoing ) as $position => $index ) {
				$results[ $index ] = $this->answered( $sdk, $requests[ $index ], $answers[ $position ] ?? null );
			}
		}
		ksort( $results );
		return array_values( $results );
	}

	/**
	 * The operations the plugin may call, with the error codes and statuses
	 * the spec documents.
	 *
	 * @throws InvalidArgumentException When the SDK has no operation the plugin lists.
	 */
	public static function catalogue(): Catalogue {
		if ( null !== self::$catalogue ) {
			return self::$catalogue;
		}
		$operations = array();
		foreach ( self::PENDING as $id => $pending ) {
			$operations[ $id ] = new Operation( $id, $pending['method'], $pending['path'], array( Credential::IntegrationKey ) );
		}
		foreach ( Operations::all() as $operation ) {
			$operations[ $operation->id ] = $operation;
		}
		self::$catalogue = new ArrayCatalogue(
			array_map( static fn ( string $id ): Operation => $operations[ $id ] ?? throw new InvalidArgumentException( sprintf( 'The SDK has no operation %s.', $id ) ), self::OPERATIONS ),
			array_keys( ErrorCode::STATUSES ),
			Operations::ERROR_STATUSES
		);
		return self::$catalogue;
	}

	/**
	 * The client for a timeout, built for the origin the site has now: on a
	 * network a request may run for another site than the one the plugin
	 * started for.
	 *
	 * @param int $timeout_seconds Give up on each send after this long.
	 *
	 * @return array{sdk: Sdk, http: BatchClient}
	 */
	private function client( int $timeout_seconds ): array {
		$origin = ( $this->origin )();
		$key    = $timeout_seconds . ' ' . $origin;
		if ( ! isset( $this->clients[ $key ] ) ) {
			$http    = ( $this->http )( $timeout_seconds );
			$factory = new Psr17Factory();

			$this->clients[ $key ] = array(
				'sdk'  => new Sdk(
					new Config(
						baseUrl: $this->base_url ?? Hosts::server_api(),
						product: 'pixonaut-for-woocommerce/' . Plugin::VERSION,
						// A publishable key is refused from any origin it is not bound to, and it was bound to the site's.
						origin: $origin,
						sleep: $this->sleep,
					),
					$http,
					$factory,
					$factory,
					$this->credentials,
					self::catalogue(),
					// Deprecations are logged once, by the listener.
					new SdkLogger( $this->logger ),
					$this->listener
				),
				'http' => $http,
			);
		}
		return $this->clients[ $key ];
	}

	/**
	 * @param Sdk                                             $sdk     The SDK the request was built with.
	 * @param PreparedRequest                                 $request The request.
	 * @param ResponseInterface|ClientExceptionInterface|null $answer  Its response, or why none arrived.
	 */
	private function answered( Sdk $sdk, PreparedRequest $request, ResponseInterface|ClientExceptionInterface|null $answer ): Result|ApiException {
		if ( ! $answer instanceof ResponseInterface ) {
			// `handle()` takes a response only, so a batched request that got
			// none is mapped here as `send()` maps it, and the listener told.
			$error = new ApiException( $request->operation, 0, ApiException::TRANSPORT_FAILED, true, null, null, array( 'message' => null === $answer ? 'No response.' : $answer->getMessage() ) );
			$this->listener->after( $request, null, $error );
			return $error;
		}
		try {
			return $sdk->handle( $request, $answer );
		} catch ( ApiException $error ) {
			return $error;
		}
	}
}
