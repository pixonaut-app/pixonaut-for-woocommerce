<?php
/**
 * What an ability answers when Pixonaut could not do what it asked.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WP_Error;
use WP_Http;

/**
 * A failed call to Pixonaut as an ability's error: its code is Pixonaut's
 * (or the client's own, such as `transport_failed`), and its message names
 * the cause, so an agent can tell a key Pixonaut no longer takes from a
 * quota or an outage and act on it. Its data's `status` is the REST
 * status the ability's run route answers with (503 when trying again later
 * may work, else 502), and `pixonaut_status` the HTTP status Pixonaut
 * answered with (0 when no answer came).
 */
final class AbilityError {

	/**
	 * Prefix of the error's code, followed by Pixonaut's.
	 */
	public const PREFIX = 'pixonaut_';

	/**
	 * Lowest HTTP status of a failure on Pixonaut's side.
	 */
	private const FIRST_SERVER_ERROR = 500;

	/**
	 * @param ApiException $error Why the call failed.
	 */
	public static function from( ApiException $error ): WP_Error {
		return new WP_Error(
			self::PREFIX . $error->errorCode,
			sprintf(
				/* translators: 1: why Pixonaut could not do it, a sentence; 2: Pixonaut's error code, e.g. rate_limited. */
				__( '%1$s (Pixonaut error code: %2$s)', 'pixonaut-for-woocommerce' ),
				self::cause( $error ),
				$error->errorCode
			),
			array(
				'code'            => $error->errorCode,
				'status'          => self::rest_status( $error ),
				'pixonaut_status' => $error->status,
				'retryable'       => $error->retryable,
				'retry_after'     => $error->retryAfter,
			)
		);
	}

	/**
	 * The REST status of a failed call: Pixonaut, the gateway the store
	 * relies on, is unavailable for now, or answered with a failure.
	 *
	 * @param ApiException $error Why the call failed.
	 */
	private static function rest_status( ApiException $error ): int {
		$for_now = $error->retryable || in_array( $error->errorCode, array( ApiException::TRANSPORT_FAILED, ApiException::NOT_CONNECTED ), true );
		return $for_now ? WP_Http::SERVICE_UNAVAILABLE : WP_Http::BAD_GATEWAY;
	}

	/**
	 * Why the call failed, in a sentence that says what to do.
	 *
	 * @param ApiException $error Why the call failed.
	 */
	private static function cause( ApiException $error ): string {
		return match ( $error->errorCode ) {
			ApiException::NOT_CONNECTED       => __( 'The store is not connected to Pixonaut, or its connection is paused. Connect it on the Pixonaut settings page.', 'pixonaut-for-woocommerce' ),
			ErrorCode::UNAUTHORIZED        => __( 'Pixonaut no longer accepts the store\'s key. Connect the store again on the Pixonaut settings page.', 'pixonaut-for-woocommerce' ),
			ErrorCode::FORBIDDEN,
			ErrorCode::SECRET_KEY_REQUIRED,
			ErrorCode::ORIGIN_FORBIDDEN    => __( 'Pixonaut does not let the store\'s key do this.', 'pixonaut-for-woocommerce' ),
			ErrorCode::WORKSPACE_SUSPENDED => __( 'The Pixonaut workspace is suspended.', 'pixonaut-for-woocommerce' ),
			ErrorCode::FEATURE_DISABLED    => __( 'The Pixonaut workspace\'s plan does not include this.', 'pixonaut-for-woocommerce' ),
			ErrorCode::RATE_LIMITED        => null !== $error->retryAfter
				/* translators: %d: seconds to wait. */
				? sprintf( _n( 'The store sent Pixonaut too many requests. Try again in %d second.', 'The store sent Pixonaut too many requests. Try again in %d seconds.', $error->retryAfter, 'pixonaut-for-woocommerce' ), $error->retryAfter )
				: __( 'The store sent Pixonaut too many requests. Wait a moment, then try again.', 'pixonaut-for-woocommerce' ),
			ApiException::TRANSPORT_FAILED    => __( 'Pixonaut could not be reached. Try again in a moment.', 'pixonaut-for-woocommerce' ),
			ApiException::UNEXPECTED_RESPONSE => __( 'Pixonaut answered in a way the store does not understand.', 'pixonaut-for-woocommerce' ),
			default                         => $error->status >= self::FIRST_SERVER_ERROR || $error->retryable
				? __( 'Pixonaut could not answer just now. Try again in a moment.', 'pixonaut-for-woocommerce' )
				: __( 'Pixonaut refused the request.', 'pixonaut-for-woocommerce' ),
		};
	}
}
