<?php
/**
 * The pinned Pixonaut SDK.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * The SDK release the storefront loads from Pixonaut's CDN (`Hosts::cdn()`),
 * never bundled.
 * Mirrors `contracts/pixonaut/sdk.lock.json`, which `pnpm contracts:pin`
 * writes and a unit test holds these constants to.
 */
final class Sdk {

	public const VERSION = '0.30.0';

	/**
	 * The script's path on a CDN, with no query string: a query would split
	 * the browser's cache key, and the SDK resolves its wasm beside the
	 * script under the same query.
	 */
	public const SCRIPT_PATH = '/sdk/0.30.0/pixonaut.iife.js';

	/**
	 * The script on Pixonaut's own CDN.
	 */
	public const SCRIPT_URL = Hosts::CDN . self::SCRIPT_PATH;

	/**
	 * Subresource Integrity of the script. The script checks the wasm it
	 * boots against hashes of its own.
	 */
	public const INTEGRITY = 'sha384-jTiWOx+lE6tUGlR+8PyQPwBjSE4q8f/OvgtqmXwItYC6qp9bPnpZYMGz3JldZeZJ';

	/**
	 * The Pixonaut operations the SDK calls from the customer's browser as
	 * the storefront mounts it, with the key kinds it sends (the spec's
	 * security scheme names; none for an open route): a publishable key on
	 * a product page, a correction link (`shareLink`) on a page that edits a
	 * cart line's design. With the client's registry and the webhook event
	 * types, `pnpm contracts:consumed` writes
	 * `contracts/pixonaut/consumed.json` from it.
	 *
	 * @var array<string, list<string>>
	 */
	public const OPERATIONS = array(
		'DELETE /v1/sessions/{id}'                => array( 'publishableKey', 'shareLink' ),
		// Font files the template names.
		'GET /f/{file}'                           => array(),
		// The photos of the blank's views.
		'GET /i/{token}'                          => array(),
		'GET /v1/assets/{id}/content'             => array( 'publishableKey', 'shareLink' ),
		// A picture's cut-out, where its constraints offer one (`cutOut`), or Pixel asks it.
		'GET /v1/assets/{id}/cut-out'             => array( 'publishableKey', 'shareLink' ),
		// The blank's views, photos and variants, for a side on a blank, edited or not.
		'GET /v1/blanks/{id}/mockups'             => array( 'publishableKey', 'shareLink' ),
		'GET /v1/blanks/{id}/variants'            => array( 'publishableKey', 'shareLink' ),
		// The colour tables of a placement whose print goes through a profile the workspace uploaded.
		'GET /v1/blanks/{id}/placements/{placementId}/preview-tables' => array( 'publishableKey', 'shareLink' ),
		'GET /v1/designs/{id}/designer'           => array( 'shareLink' ),
		'GET /v1/templates/{id}'                  => array( 'publishableKey' ),
		// An edited side's box on its placement, read through its correction link.
		'GET /v1/templates/{id}/constraints'      => array( 'publishableKey', 'shareLink' ),
		'POST /v1/assets'                         => array( 'publishableKey', 'shareLink' ),
		'POST /v1/assets/{id}/cut-out'            => array( 'publishableKey', 'shareLink' ),
		'POST /v1/assets/{id}/improve-resolution' => array( 'publishableKey', 'shareLink' ),
		// A picture on an embroidered side, drawn in the machine's threads.
		'POST /v1/assets/{id}/threads'            => array( 'publishableKey', 'shareLink' ),
		'POST /v1/designs/{id}/correction-proofs' => array( 'shareLink' ),
		// The designer's Create with AI control, where the template read offers it (`pixel`); never on a correction link.
		'POST /v1/pixel/designer'                 => array( 'publishableKey' ),
		'POST /v1/proofs'                         => array( 'publishableKey' ),
		'POST /v1/templates/{id}/print-size'      => array( 'publishableKey' ),
	);

	/**
	 * The script the storefront loads, and its Subresource Integrity: the
	 * pinned release from the CDN in use, checked; or the developer tools'
	 * custom script, which is not the release the integrity names, unchecked.
	 *
	 * @param Settings $settings The plugin's settings.
	 *
	 * @return array{url: string, integrity: string} An empty integrity for a custom script.
	 */
	public static function script( Settings $settings ): array {
		$custom = (string) $settings->get( Settings::SDK_URL );
		return '' === $custom ? array(
			'url'       => self::pinned_url(),
			'integrity' => self::INTEGRITY,
		) : array(
			'url'       => $custom,
			'integrity' => '',
		);
	}

	/**
	 * The pinned release on the CDN in use.
	 */
	public static function pinned_url(): string {
		return untrailingslashit( Hosts::cdn() ) . self::SCRIPT_PATH;
	}
}
