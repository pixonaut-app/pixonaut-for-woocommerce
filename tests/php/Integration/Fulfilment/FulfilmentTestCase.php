<?php
/**
 * A paid order with one Pixonaut line, for fulfilment tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\Fulfiller;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * A paid order's Pixonaut line through the plugin's real hooks and Action
 * Scheduler, against Pixonaut answered from a script.
 */
abstract class FulfilmentTestCase extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	protected const BASE_URL = 'http://127.0.0.1:9';

	protected const SITE = '8e1f3c2a-5b4d-4e6f-9a0b-1c2d3e4f5a6b';

	/**
	 * Prefix of each side's design id, followed by the side key.
	 */
	protected const DESIGN_PREFIX = 'd_';

	protected const DESIGN = self::DESIGN_PREFIX . 'front';

	protected const REVISION = 1;

	/**
	 * When the customer agreed, epoch milliseconds.
	 */
	private const CONSENT_AT_MS = 1_800_000_000_000;

	protected const QUANTITY = 2;

	protected HttpRecorder $recorder;

	protected ConnectionStore $store;

	protected WC_Order $order;

	protected WC_Order_Item_Product $item;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$this->store->save( self::connection() );
		ConnectionAnswers::keep_tenant_slug();
		$client = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$store  = $this->store;
		$active = static fn (): ?StoredConnection => $store->load();
		$jobs   = new FulfilmentJobs( new Fulfiller( $client, $active, Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->proof_request() ), $active, new WcLogger() );
		// The plugin's own scheduling stays; its job runs against the recorder.
		remove_all_actions( FulfilmentJobs::ACTION );
		add_action( FulfilmentJobs::ACTION, array( $jobs, 'run' ) );

		$this->order = $this->paid_order();
		$item        = current( $this->order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->item = $item;
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		parent::tear_down();
	}

	/**
	 * A paid order of one line whose sides' designs moved to its order line
	 * ref at checkout; side `<key>` holds design `d_<key>`.
	 *
	 * @param string[] $sides Side keys, in the product's order.
	 * @phpstan-param list<string> $sides
	 */
	protected function paid_order( array $sides = array( 'front' ) ): WC_Order {
		$order = $this->new_order();
		$order->add_product( $this->product(), self::QUANTITY );
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'company'    => 'Analytical Engines',
				'address_1'  => 'Storgatan 1',
				'city'       => 'Stockholm',
				'postcode'   => '111 22',
				'country'    => 'SE',
			),
			'shipping'
		);
		$order->set_billing_email( 'ada@example.org' );
		$order->save();

		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::start( $item, $sides );
		$line->set_ref( LineState::REF_TENANT_ID, FulfilmentAnswers::TENANT_ID );
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::ADD_TO_CART );
		$line->set_ref(
			LineState::REF_CONSENT,
			array(
				'kind' => ApprovalMode::ADD_TO_CART,
				'at'   => self::CONSENT_AT_MS,
			)
		);
		foreach ( $sides as $side ) {
			$line->set_side_ref( $side, LineState::SIDE_DESIGN_ID, self::DESIGN_PREFIX . $side );
			$line->set_side_ref( $side, LineState::SIDE_REVISION, self::REVISION );
		}
		// Checkout moved the design to the order line ref.
		$line->set_ref( LineState::REF_EXTERNAL_REF, Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() ) );
		$line->set_ref( LineState::REF_EXTERNAL_REF_MOVED, true );
		$this->assertTrue( $line->save() );
		return $order;
	}

	protected function new_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	protected function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '20' );
		$product->save();
		return $product;
	}

	protected function line(): LineState {
		$item = new WC_Order_Item_Product( $this->item->get_id() );
		return LineState::of( $item );
	}

	protected function count_sent( string $method, string $path ): int {
		return count(
			array_filter(
				$this->recorder->requests,
				static fn ( array $request ): bool => $method === $request['method'] && self::BASE_URL . $path === $request['url']
			)
		);
	}

	protected static function connection( string $environment = StoredConnection::LIVE ): StoredConnection {
		return new StoredConnection(
			self::SITE,
			untrailingslashit( home_url() ),
			time(),
			FulfilmentAnswers::TENANT_ID,
			'Acme',
			$environment,
			'sc_1',
			ScriptedClient::INTEGRATION_KEY,
			'ik_id',
			'WooCommerce',
			'pk_test',
			'pk_id',
			array(),
			'wh_1',
			home_url( '/wp-json/pixonaut/v1/webhook' ),
			'whsec_test'
		);
	}
}
