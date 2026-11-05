<?php
/**
 * Where the client gets its credentials.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

interface CredentialProvider {

	/**
	 * The secret for a credential, or null while the store holds none (not
	 * connected, or paused).
	 *
	 * @param Credential $credential Never `Credential::None`.
	 */
	public function secret( Credential $credential ): ?string;
}
