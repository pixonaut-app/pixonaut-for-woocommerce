<?php
/**
 * Fixed credentials for tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\CredentialProvider;

final class StaticCredentials implements CredentialProvider {

	public const INTEGRATION_KEY = 'ik_test_0000000000000000';

	public const PUBLISHABLE_KEY = 'pk_test_0000000000000000';

	/**
	 * @param string|null $integration_key The store's key; null while not connected.
	 */
	public function __construct(
		private readonly ?string $integration_key = self::INTEGRATION_KEY,
	) {
	}

	public function secret( Credential $credential ): ?string {
		return match ( $credential ) {
			Credential::IntegrationKey => $this->integration_key,
			Credential::PublishableKey => null === $this->integration_key ? null : self::PUBLISHABLE_KEY,
			Credential::None           => null,
		};
	}
}
