<?php
/**
 * Hides, on the product page, the attributes that do not apply to the shopper's choices.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use Pixonaut\WooCommerce\Plugin;
use WC_Product;

/**
 * On the page of a variable product with an attribute only some of its
 * variations set (`ApplicableAttributes`), loads a script of its own that
 * hides each such attribute's field while no variation matching the
 * shopper's choices sets it, in WooCommerce's classic form and its block
 * one. The page prints what the rule reads: the product's attribute fields
 * in order, and the values of each variation its form offers, since the
 * form itself holds no variations above WooCommerce's threshold for
 * loading them on demand. Any other product's page loads nothing.
 */
final class ApplicableAttributeForm {

	public const SCRIPT_HANDLE = 'pixonaut-applicable-attributes';

	/**
	 * The global the script reads the product's attributes from.
	 */
	public const SCRIPT_GLOBAL = 'pixonautApplicableAttributes';

	/**
	 * @param string $plugin_file The plugin's main file.
	 */
	public function __construct( private readonly string $plugin_file ) {
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$product = is_product() ? wc_get_product( get_queried_object_id() ) : null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$fields     = ApplicableAttributes::fields( $product );
		$variations = array() === $fields ? array() : ApplicableAttributes::variations( $product );
		if ( array() === ApplicableAttributes::dependent( $fields, $variations ) ) {
			return;
		}
		$file  = dirname( $this->plugin_file ) . '/assets/build/applicable-attributes.asset.php';
		$asset = is_readable( $file ) ? require $file : array();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/applicable-attributes.js', $this->plugin_file ),
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf(
				'window.%s = %s;',
				self::SCRIPT_GLOBAL,
				wp_json_encode(
					array(
						'productId'  => $product->get_id(),
						'fields'     => $fields,
						// Many variations hold the same values of these fields; each distinct set is printed once.
						'variations' => array_values( array_unique( $variations, SORT_REGULAR ) ),
					)
				)
			),
			'before'
		);
	}
}
