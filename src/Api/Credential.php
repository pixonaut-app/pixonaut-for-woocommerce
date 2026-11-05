<?php
/**
 * Credentials an operation is sent with.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * Values are the spec's security scheme names, as `consumed.json` lists them
 * under `keys`.
 */
enum Credential: string {

	/** The store's integration key (`ik_`), kept on the server. */
	case IntegrationKey = 'integrationKey';

	/**
	 * The store's publishable key (`pk_`), sent from the server with the
	 * site's origin, which the key is bound to.
	 */
	case PublishableKey = 'publishableKey';

	/** Sent without a credential. */
	case None = 'none';
}
