<?php
/**
 * The plugin's hooks on the SDK's requests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Closure;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Deprecation;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Listener;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Log\NullLogger;

/**
 * Fires the `pixonaut_wc_api_*` hooks around every request the SDK sends,
 * and keeps each deprecation notice for the admin notice. None of them sees
 * a credential.
 */
final class HookListener extends Listener {

	private readonly Closure $clock;

	private readonly LoggerInterface $logger;

	/**
	 * @param Deprecations         $deprecations Records deprecations for the admin notice.
	 * @param Closure|null         $clock        `fn(): int` Unix time; `time()` when null.
	 * @param LoggerInterface|null $logger       Logs a deprecation once, when it is new or changed; nowhere when null.
	 */
	public function __construct( private readonly Deprecations $deprecations, ?Closure $clock = null, ?LoggerInterface $logger = null ) {
		$this->clock  = $clock ?? static fn (): int => time();
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * @param string                  $operation SDK operation id.
	 * @param array<array-key, mixed> $body      Request body.
	 *
	 * @return array<array-key, mixed>
	 */
	public function body( string $operation, array $body ): array {
		/**
		 * Filters the body of a request to Pixonaut before it is encoded.
		 *
		 * Runs once per request, when it is prepared: a request sent
		 * again from its snapshot is not filtered again.
		 *
		 * Since 2.0.0 `$operation` is the SDK's operation id, e.g.
		 * `designs.commit`, where it was the method and path before.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $body      Request body.
		 * @param string               $operation SDK operation id, e.g. `designs.commit`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_api_request_body', function ( $body, $operation ) {
		 *     if ( 'designs.commit' === $operation ) {
		 *         $body['externalRef'] = 'shop-a:' . $body['externalRef'];
		 *     }
		 *     return $body;
		 * }, 10, 2 );
		 */
		return (array) apply_filters( 'pixonaut_wc_api_request_body', $body, $operation );
	}

	/**
	 * @param PreparedRequest $request The request about to be sent.
	 */
	public function before( PreparedRequest $request ): void {
		/**
		 * Fires before every request to Pixonaut, including each resend.
		 *
		 * Since 2.0.0 `$request` is the SDK's
		 * `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest`:
		 * `operation` is its operation id, e.g. `designs.commit`, and the key
		 * is `idempotencyKey`.
		 *
		 * @since 0.1.0
		 *
		 * @param PreparedRequest $request The request; it holds no credential.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_api_before_request', function ( $request ) {
		 *     error_log( 'Pixonaut call: ' . $request->operation );
		 * } );
		 */
		do_action( 'pixonaut_wc_api_before_request', $request );
	}

	/**
	 * @param PreparedRequest   $request The request.
	 * @param Result|null       $result  The successful answer, or null.
	 * @param ApiException|null $error   Why it did not succeed, or null.
	 */
	public function after( PreparedRequest $request, ?Result $result, ?ApiException $error ): void {
		/**
		 * Fires after every request to Pixonaut, including each resend.
		 *
		 * Since 2.0.0 it receives the SDK's `PreparedRequest`, `Result` and
		 * `ApiException`, under `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\`.
		 * An answer that is not a success comes as the error, with its
		 * `status`, where it came as the response before.
		 *
		 * @since 0.1.0
		 *
		 * @param PreparedRequest   $request The request; it holds no credential.
		 * @param Result|null       $result  The successful answer, or null.
		 * @param ApiException|null $error   Why it did not succeed (Pixonaut refused it, or it got no answer), or null.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_api_after_request', function ( $request, $result, $error ) {
		 *     if ( $error && $error->status >= 500 ) {
		 *         error_log( 'Pixonaut failed: ' . $request->operation );
		 *     }
		 * }, 10, 3 );
		 */
		do_action( 'pixonaut_wc_api_after_request', $request, $result, $error );
	}

	/**
	 * @param string       $operation SDK operation id.
	 * @param ApiException $error     The refusal.
	 */
	public function unauthorized( string $operation, ApiException $error ): void {
		/**
		 * Fires when Pixonaut refused the store's credential: the
		 * connection was removed in Pixonaut, or the key revoked.
		 *
		 * Since 2.0.0 `$operation` is the SDK's operation id and `$error`
		 * the SDK's `ApiException`, under
		 * `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\`.
		 *
		 * @since 0.1.0
		 *
		 * @param string       $operation SDK operation id, e.g. `connectedStore.get`.
		 * @param ApiException $error     The refusal.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_api_unauthorized', function ( $operation ) {
		 *     wp_mail( get_option( 'admin_email' ), 'Pixonaut disconnected', $operation );
		 * } );
		 */
		do_action( 'pixonaut_wc_api_unauthorized', $operation, $error );
	}

	/**
	 * @param string      $operation   SDK operation id.
	 * @param Deprecation $deprecation The notice.
	 */
	public function deprecated( string $operation, Deprecation $deprecation ): void {
		if ( ! $this->deprecations->record( $operation, $deprecation->header, $deprecation->sunsetHeader, ( $this->clock )() ) ) {
			return;
		}
		$this->logger->warning(
			sprintf( 'Pixonaut deprecated something %s uses (Deprecation: %s, Sunset: %s). Update the plugin before the sunset.', $operation, $deprecation->header, $deprecation->sunsetHeader ?? 'none' ),
			array(
				'operation'   => $operation,
				'deprecation' => $deprecation->header,
				'sunset'      => $deprecation->sunsetHeader,
			)
		);
	}
}
