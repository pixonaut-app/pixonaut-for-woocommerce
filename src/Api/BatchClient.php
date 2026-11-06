<?php
/**
 * A PSR-18 client that also sends several requests at once.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Psr\Http\Client\ClientExceptionInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Client\ClientInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;

interface BatchClient extends ClientInterface {

	/**
	 * Sends several requests without a body at once and waits for every answer.
	 *
	 * @param RequestInterface[] $requests Requests without a body.
	 * @phpstan-param list<RequestInterface> $requests
	 *
	 * @return list<ResponseInterface|ClientExceptionInterface> Each request's response, or why none arrived, in the order of `$requests`.
	 */
	public function send_all( array $requests ): array;
}
