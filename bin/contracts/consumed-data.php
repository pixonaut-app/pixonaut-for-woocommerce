<?php
/**
 * Run by bin/contracts/consumed-generate.mjs in wp-env, without WordPress.
 * Prints, as JSON, what the plugin calls and handles: every operation with
 * the key kinds it is called with, from the client's registry and the SDK's,
 * and every webhook the store receives.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use Pixonaut\WooCommerce\Webhooks\Event;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$pixonaut_operations = array();
foreach ( Operations::plugin()->all() as $pixonaut_id => $pixonaut_operation ) {
	$pixonaut_operations[ $pixonaut_id ] = Credential::None === $pixonaut_operation->credential ? array() : array( $pixonaut_operation->credential->value );
}
foreach ( Sdk::OPERATIONS as $pixonaut_id => $pixonaut_kinds ) {
	$pixonaut_operations[ $pixonaut_id ] = array_merge( $pixonaut_operations[ $pixonaut_id ] ?? array(), $pixonaut_kinds );
}

echo json_encode(
	array(
		'operations' => (object) $pixonaut_operations,
		'webhooks'   => array_merge( Endpoint::EVENT_TYPES, array( Event::PING ) ),
	),
	JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
