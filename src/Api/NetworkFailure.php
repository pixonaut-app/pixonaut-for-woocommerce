<?php
/**
 * A request to Pixonaut that got no response.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * DNS, connection, timeout, a blocked host or a URL WordPress refuses: the
 * PSR-18 failure `WpHttpTransport` reports, which the SDK maps to
 * `transport_failed`.
 */
final class NetworkFailure extends RuntimeException implements NetworkExceptionInterface {

	/**
	 * @param RequestInterface $request The request that got no response.
	 * @param string           $message Why, as the HTTP layer said.
	 */
	public function __construct( private readonly RequestInterface $request, string $message ) {
		parent::__construct( $message );
	}

	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
