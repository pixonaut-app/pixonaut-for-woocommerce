<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\VariantCosts;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * Products sold on a blank's variants, priced against a recorder that
 * answers their costs as Pixonaut does.
 */
abstract class PricingTestCase extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	protected const BASE_URL = 'http://127.0.0.1:9';

	protected const BLANK_ID = 'blank_poster';

	protected const AXIS = 'Size';

	/**
	 * The time the rule sets a price at.
	 */
	protected const NOW = 1794000000;

	protected HttpRecorder $recorder;

	protected RecordingLogger $logger;

	protected Plugin $plugin;

	protected ProductPricing $pricing;

	public function set_up(): void {
		parent::set_up();
		$this->logger   = new RecordingLogger();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client         = ( new Pixonaut( ScriptedClient::credentials(), new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$this->pricing  = new ProductPricing( new VariantCosts( $client ), $this->plugin->settings(), $this->logger, static fn (): int => self::NOW );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Sets the store's rule: a target margin of 50 %, a tolerance of 10
	 * points, every ten ending in nine, and the parts given over them.
	 *
	 * @param array<string, string> $parts Parts of the rule, by part.
	 */
	protected function store_rule( array $parts = array() ): void {
		$parts += array(
			PriceRule::MARGIN    => '50',
			PriceRule::TOLERANCE => '10',
			PriceRule::STEP      => '10',
			PriceRule::ENDING    => '9',
		);
		foreach ( $parts as $part => $written ) {
			$this->plugin->settings()->set( Settings::PRICE_RULE[ $part ], $written );
		}
	}

	/**
	 * A variable product on the blank with one variation per variant ref,
	 * each at a price.
	 *
	 * @param array<string, string> $prices Regular prices by variant ref; empty for none.
	 * @return array<string, int> The variations' ids, by variant ref.
	 */
	protected function variable_product( array $prices ): array {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::AXIS );
		$attribute->set_options( array_keys( $prices ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_name( 'Poster' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::on_blank( $product );
		$ids = array();
		foreach ( $prices as $ref => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( sanitize_title( self::AXIS ) => (string) $ref ) );
			$variation->set_regular_price( $price );
			$variation->save();
			( new ProductVariant( self::BLANK_ID, (string) $ref, (string) $ref, self::AXIS . ': ' . $ref ) )->save_to( $variation );
			$ids[ (string) $ref ] = $variation->get_id();
		}
		return $ids;
	}

	/**
	 * A simple product on the blank, selling one of its variants.
	 *
	 * @param string $ref   The variant.
	 * @param string $price Its regular price; empty for none.
	 */
	protected function simple_product( string $ref, string $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->set_regular_price( $price );
		$product->save();
		self::on_blank( $product );
		( new ProductVariant( self::BLANK_ID, $ref, $ref, $ref ) )->save_to( $product );
		return $product->get_id();
	}

	/**
	 * Scripts the answer of one read of the blank's costs.
	 *
	 * @param array<string, string|null> $products Each variant's product price by ref; null for one the printshop publishes none for.
	 * @param string|null                $shipping Every variant's shipping; null for none.
	 * @param string|null                $currency The costs' currency; the store's when null.
	 */
	protected function script_costs( array $products, ?string $shipping = null, ?string $currency = null ): void {
		$currency ??= get_woocommerce_currency();
		$money      = static fn ( ?string $amount ): ?array => null === $amount ? null : array(
			'amount'   => $amount,
			'currency' => $currency,
		);
		$costs      = array();
		foreach ( $products as $ref => $product ) {
			$costs[] = array(
				'variant'   => (string) $ref,
				'product'   => $money( $product ),
				'shipping'  => null === $product ? null : $money( $shipping ),
				'region'    => 'SE',
				'readAt'    => '2026-11-14T06:00:00.000Z',
				'changedAt' => null,
			);
		}
		$this->recorder->json( 200, array( 'costs' => $costs ) );
	}

	/**
	 * A product's or variation's regular price as stored, read again.
	 *
	 * @param int $id The product or variation.
	 */
	protected function price( int $id ): string {
		return $this->product( $id )->get_regular_price( 'edit' );
	}

	/**
	 * @param int $id A product or variation.
	 */
	protected function product( int $id ): WC_Product {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * @param int $id A variation.
	 */
	protected function parent_of( int $id ): WC_Product {
		return $this->product( $this->product( $id )->get_parent_id() );
	}

	/**
	 * The store manager changes a price on the product form.
	 *
	 * @param int    $id    The product or variation.
	 * @param string $price Its new regular price.
	 */
	protected function edit_price( int $id, string $price ): void {
		$product = $this->product( $id );
		$product->set_regular_price( $price );
		$product->save();
	}

	/**
	 * Thousandths of an amount as written.
	 *
	 * @param string $written A decimal amount.
	 */
	protected static function amount( string $written ): int {
		return (int) Amount::parse( $written );
	}

	private static function on_blank( WC_Product $product ): void {
		( new ProductConfig( personalise: true, blank_id: self::BLANK_ID ) )->save_to( $product );
	}
}
