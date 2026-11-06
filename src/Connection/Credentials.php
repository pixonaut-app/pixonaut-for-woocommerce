<?php
/**
 * The credentials the SDK sends.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\CredentialProvider;

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
