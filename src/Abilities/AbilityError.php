<?php
/**
 * What an ability answers when Pixonaut could not do what it asked.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\ErrorCodes;
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
	 * @param ApiError $error Why the call failed.
	 */
	public static function from( ApiError $error ): WP_Error {
		return new WP_Error(
			self::PREFIX . $error->error_code,
			sprintf(
				/* translators: 1: why Pixonaut could not do it, a sentence; 2: Pixonaut's error code, e.g. rate_limited. */
				__( '%1$s (Pixonaut error code: %2$s)', 'pixonaut-for-woocommerce' ),
				self::cause( $error ),
				$error->error_code
			),
			array(
				'code'            => $error->error_code,
				'status'          => self::rest_status( $error ),
				'pixonaut_status' => $error->status,
				'retryable'       => $error->retryable,
				'retry_after'     => $error->retry_after,
			)
		);
	}

	/**
	 * The REST status of a failed call: Pixonaut, the gateway the store
	 * relies on, is unavailable for now, or answered with a failure.
	 *
	 * @param ApiError $error Why the call failed.
	 */
	private static function rest_status( ApiError $error ): int {
		$for_now = $error->retryable || in_array( $error->error_code, array( ErrorCodes::TRANSPORT_FAILED, ErrorCodes::NOT_CONNECTED ), true );
		return $for_now ? WP_Http::SERVICE_UNAVAILABLE : WP_Http::BAD_GATEWAY;
	}

	/**
	 * Why the call failed, in a sentence that says what to do.
	 *
	 * @param ApiError $error Why the call failed.
	 */
	private static function cause( ApiError $error ): string {
		return match ( $error->error_code ) {
			ErrorCodes::NOT_CONNECTED       => __( 'The store is not connected to Pixonaut, or its connection is paused. Connect it on the Pixonaut settings page.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::UNAUTHORIZED        => __( 'Pixonaut no longer accepts the store\'s key. Connect the store again on the Pixonaut settings page.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::FORBIDDEN,
			ErrorCodes::SECRET_KEY_REQUIRED,
			ErrorCodes::ORIGIN_FORBIDDEN    => __( 'Pixonaut does not let the store\'s key do this.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::WORKSPACE_SUSPENDED => __( 'The Pixonaut workspace is suspended.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::FEATURE_DISABLED    => __( 'The Pixonaut workspace\'s plan does not include this.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::RATE_LIMITED        => null !== $error->retry_after
				/* translators: %d: seconds to wait. */
				? sprintf( _n( 'The store sent Pixonaut too many requests. Try again in %d second.', 'The store sent Pixonaut too many requests. Try again in %d seconds.', $error->retry_after, 'pixonaut-for-woocommerce' ), $error->retry_after )
				: __( 'The store sent Pixonaut too many requests. Wait a moment, then try again.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::TRANSPORT_FAILED    => __( 'Pixonaut could not be reached. Try again in a moment.', 'pixonaut-for-woocommerce' ),
			ErrorCodes::UNEXPECTED_RESPONSE => __( 'Pixonaut answered in a way the store does not understand.', 'pixonaut-for-woocommerce' ),
			default                         => $error->status >= self::FIRST_SERVER_ERROR || $error->retryable
				? __( 'Pixonaut could not answer just now. Try again in a moment.', 'pixonaut-for-woocommerce' )
				: __( 'Pixonaut refused the request.', 'pixonaut-for-woocommerce' ),
		};
	}
}
