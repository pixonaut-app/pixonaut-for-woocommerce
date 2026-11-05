<?php
/**
 * The credentials the API client sends.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\CredentialProvider;

/**
 * The store's keys from its connection.
 */
final class Credentials implements CredentialProvider {

	/**
	 * @param ConnectionStore $store The store's connection.
	 */
	public function __construct(
		private readonly ConnectionStore $store,
	) {
	}

	public function secret( Credential $credential ): ?string {
		return $this->store->secret( $credential );
	}
}
