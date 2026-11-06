<?php
/**
 * Run by bin/contracts/consumed-generate.mjs in wp-env, without WordPress.
 * Prints, as JSON, what the plugin calls and handles: every operation with
 * the key kinds it is called with, from the operation ids the plugin lets the
 * SDK send and the browser SDK's, and every webhook the store receives.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\EventType;
use Pixonaut\WooCommerce\Webhooks\Endpoint;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// The keys the store holds, in the order the SDK prefers them.
$pixonaut_held       = array( Credential::IntegrationKey, Credential::PublishableKey );
$pixonaut_catalogue  = Pixonaut::catalogue();
$pixonaut_operations = array();
foreach ( Pixonaut::OPERATIONS as $pixonaut_id ) {
	$pixonaut_operation = $pixonaut_catalogue->operation( $pixonaut_id );
	$pixonaut_kinds     = array();
	if ( ! $pixonaut_operation->anonymous ) {
		foreach ( $pixonaut_held as $pixonaut_credential ) {
			if ( $pixonaut_operation->accepts( $pixonaut_credential ) ) {
				$pixonaut_kinds = array( $pixonaut_credential->value );
				break;
			}
		}
	}
	$pixonaut_operations[ $pixonaut_operation->method . ' ' . $pixonaut_operation->path ] = $pixonaut_kinds;
}
foreach ( Sdk::OPERATIONS as $pixonaut_id => $pixonaut_kinds ) {
	$pixonaut_operations[ $pixonaut_id ] = array_merge( $pixonaut_operations[ $pixonaut_id ] ?? array(), $pixonaut_kinds );
}

echo json_encode(
	array(
		'operations' => (object) $pixonaut_operations,
		'webhooks'   => array_merge( Endpoint::EVENT_TYPES, array( EventType::WEBHOOK_PING ) ),
	),
	JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
