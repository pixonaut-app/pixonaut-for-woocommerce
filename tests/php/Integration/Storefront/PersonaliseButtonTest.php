<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\DesignerVisits;
use Pixonaut\WooCommerce\Storefront\HostedCorrection;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Storefront\PersonaliseButton;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Storefront\Storefront;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class PersonaliseButtonTest extends PersonalisedCartTestCase {

	private const LINK_URL = 'http://fake-pixonaut.test/designer/dl_1';

	private const BACK_TOKEN = 'eyJhdWQiOiJwcm9vZiJ9.YmFjaw';

	private const BACK_DESIGN_ID = '7a2d9b3c-4e5f-4a6b-9c7d-8e9f0a1b2c3d';

	private const QUANTITY = 2;

	private const COPY_ID = '5c1e8a2f-3b4d-4c6e-8f7a-9b0c1d2e3f40';

	/**
	 * A filter priority that runs after `AddToCart::validate()`, at 10.
	 */
	private const AFTER_THE_PLUGINS_VALIDATION = 20;

	/**
	 * A proof token this session's trip did not bring back.
	 */
	private const FOREIGN_TOKEN = 'pt_of_another_session';

	private PersonaliseButton $button;

	/**
	 * The state of the trip `start()` opened last.
	 *
	 * @var string
	 */
	private string $trip = '';

	public function tear_down(): void {
		unset( $_POST[ AddToCart::KEPT_FIELD ] );
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, false );
		$this->button->added();
		$this->plugin->add_to_cart()->discard_unadded();
		parent::tear_down();
	}

	public function set_up(): void {
		parent::set_up();
		$this->button = $this->plugin->personalise_button();
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
		// Every test returns the same proof token, which a session takes once.
		WC()->session->set( DesignerVisits::SESSION_KEY, null );
		WC()->session->set( DesignerVisits::RETURNS_SESSION_KEY, null );
		WC()->session->set( PersonalisedDesigns::SESSION_KEY, null );
	}

	/**
	 * The key of the design kept for a product and variation, if any.
	 *
	 * @param int $product_id   The product; this test's when 0.
	 * @param int $variation_id The variation, or 0.
	 */
	private function kept_key( int $product_id = 0, int $variation_id = 0 ): ?string {
		$product_id = 0 === $product_id ? $this->product_id : $product_id;
		foreach ( (array) WC()->session->get( PersonalisedDesigns::SESSION_KEY ) as $key => $item ) {
			if ( ( $item['product_id'] ?? null ) === $product_id && ( $item['variation_id'] ?? null ) === $variation_id ) {
				return (string) $key;
			}
		}
		return null;
	}

	/**
	 * The design kept for a product and variation.
	 *
	 * @param int $product_id   The product; this test's when 0.
	 * @param int $variation_id The variation, or 0.
	 */
	private function waiting( int $product_id = 0, int $variation_id = 0 ): ?CartLine {
		return $this->plugin->personalised_designs()->get( (string) $this->kept_key( $product_id, $variation_id ) )['line'] ?? null;
	}

	/**
	 * Opens the page a return landed on, with the design its address names.
	 *
	 * @param string|null $key The design's key; the kept one of this test's product when null.
	 */
	private function open_design( ?string $key = null ): void {
		$_GET[ PersonalisedDesigns::ARG ] = $key ?? $this->kept_key();
		try {
			$this->button->handle();
		} finally {
			unset( $_GET[ PersonalisedDesigns::ARG ] );
		}
	}

	/**
	 * Presses WooCommerce's Add to cart on the page showing the design.
	 *
	 * @param int $quantity How many.
	 */
	private function add_from_page( int $quantity = 1 ): string {
		$this->post_kept();
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, $quantity ) );
		$key = WC()->cart->add_to_cart( $this->product_id, $quantity );
		$this->assertIsString( $key );
		return $key;
	}

	/**
	 * Posts the form of the page showing the design.
	 *
	 * @param int $product_id   The product; this test's when 0.
	 * @param int $variation_id The variation, or 0.
	 */
	private function post_kept( int $product_id = 0, int $variation_id = 0 ): void {
		$_POST[ AddToCart::KEPT_FIELD ] = $this->kept_key( $product_id, $variation_id );
	}

	/**
	 * The arguments of the job deleting the fixture's design.
	 *
	 * @return array<string, mixed>
	 */
	private static function cleanup_args(): array {
		return array(
			'design_id' => DesignFixture::ID,
			'tenant_id' => self::TENANT,
			'failures'  => 0,
		);
	}

	private function notices_html(): string {
		$GLOBALS['product'] = $this->product();
		ob_start();
		$this->button->render_notices();
		unset( $GLOBALS['product'] );
		return (string) ob_get_clean();
	}

	/**
	 * Adds a design from the page, then opens that line's page as its link does.
	 *
	 * @return string The cart item's key.
	 */
	private function design_in_cart(): string {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$key = $this->add_from_page();

		$_GET[ PersonaliseButton::LINE_ARG ] = $key;
		try {
			$this->button->handle();
		} finally {
			unset( $_GET[ PersonaliseButton::LINE_ARG ] );
		}
		return $key;
	}

	/**
	 * The product page's add-to-cart area, as WooCommerce prints it.
	 */
	private function form_html(): string {
		$GLOBALS['product'] = $this->product();
		ob_start();
		woocommerce_template_single_add_to_cart();
		unset( $GLOBALS['product'] );
		return (string) ob_get_clean();
	}

	/**
	 * The form block's output, as the plugin filters it on the product's page.
	 */
	private function form_block_html(): string {
		$GLOBALS['post'] = get_post( $this->product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the page the block renders on.
		try {
			return $this->button->after_form_block( '<form></form>', array(), null );
		} finally {
			unset( $GLOBALS['post'] );
		}
	}

	private function label( string $key ): string {
		return $this->plugin->labels()->get( $key );
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * The product's configuration on no blank: a template alone.
	 */
	private static function no_blank_config(): ProductConfig {
		return new ProductConfig( personalise: true, sides: array( new Side( 'front', 'Front', null, null, self::config()->sides[0]->template_base_id, self::config()->sides[0]->template_version, null, null, self::config()->sides[0]->print_sizes ) ) );
	}

	private const OTHER_LINK_URL = 'http://fake-pixonaut.test/designer/dl_2';

	private function script_link( string $id = 'shl_1', string $url = self::LINK_URL ): void {
		$this->recorder->json(
			201,
			array(
				'id'             => $id,
				'token'          => basename( $url ),
				'url'            => $url,
				'expiresAt'      => null,
				'name'           => null,
				'brandId'        => null,
				'designLimit'    => null,
				'printAreaFrame' => false,
				'placementFrame' => false,
				'locale'         => null,
			)
		);
	}

	/**
	 * Sends the customer to the designer.
	 *
	 * @return array<string, mixed> The link's request body.
	 */
	private function start(): array {
		$this->script_link();
		$location = $this->button->start( $this->product_id, 0, self::QUANTITY );
		$this->assertSame( self::LINK_URL, self::link_of( $location ) );
		$this->trip = self::trip_of( $location );
		$sent       = end( $this->recorder->requests );
		$this->assertIsArray( $sent );
		return json_decode( (string) $sent['body'], true );
	}

	/**
	 * The designer link a trip's address opens, without the trip's state.
	 *
	 * @param string $location Where `start()` sent the customer.
	 */
	private static function link_of( string $location ): string {
		return remove_query_arg( PersonaliseButton::STATE_ARG, $location );
	}

	/**
	 * The state a trip's address carries to the hosted designer.
	 *
	 * @param string $location Where `start()` sent the customer.
	 */
	private static function trip_of( string $location ): string {
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$state = $query[ PersonaliseButton::STATE_ARG ] ?? '';
		return is_string( $state ) ? $state : '';
	}

	private function render(): string {
		$GLOBALS['product'] = $this->product();
		ob_start();
		$this->button->render();
		unset( $GLOBALS['product'] );
		return (string) ob_get_clean();
	}

	public function test_the_button_replaces_the_inline_designer_on_a_button_product(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'name="' . PersonaliseButton::START_FIELD . '" value="' . $this->product_id . '"', $html );
		$this->assertStringContainsString( $this->label( Labels::PERSONALISE ), $html );
		$this->assertNull( $this->plugin->inline_products()->for_product( $this->product() ) );
	}

	public function test_a_product_override_picks_its_layout_over_the_default(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::INLINE );
		$this->assertSame( '', $this->render() );

		self::config()->with_layout( Layout::BUTTON )->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );

		$this->assertStringContainsString( PersonaliseButton::START_FIELD, $this->render() );
		$this->assertNull( $this->plugin->inline_products()->for_product( $this->product() ) );
	}

	public function test_a_disconnected_store_shows_personalisation_unavailable(): void {
		$this->plugin->connection_store()->clear();

		$html = $this->render();

		$this->assertStringNotContainsString( PersonaliseButton::START_FIELD, $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::PERSONALISATION_UNAVAILABLE ) ), $html );
	}

	public function test_starting_mints_one_named_link_for_the_side_that_returns_to_the_product(): void {
		$filtered = array();
		add_filter(
			'pixonaut_wc_storefront_designer_link',
			static function ( array $body, WC_Product $product ) use ( &$filtered ): array {
				$filtered[] = $product->get_id();
				return $body;
			},
			10,
			2
		);

		$body = $this->start();

		$sent = $this->recorder->requests[0];
		$this->assertStringEndsWith( '/v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '/designer-links', $sent['url'] );
		$this->assertSame( DesignFixture::BLANK_ID, $body['blankId'] );
		$this->assertSame( DesignFixture::PLACEMENT_ID, $body['placementId'] );
		$this->assertSame( 0, $body['selectedPrintSizeIndex'] );
		$this->assertSame( KeptDesignerLinks::return_url( $this->product() ), $body['returnUrl'] );
		$this->assertStringContainsString( PersonaliseButton::PRODUCT_ARG . '=' . $this->product_id, $body['returnUrl'] );
		$this->assertSame( 'en', $body['locale'] );
		$this->assertSame( $this->product()->get_name() . ' (#' . $this->product_id . ')', $body['name'] );
		$this->assertArrayNotHasKey( 'state', $body );
		$this->assertArrayNotHasKey( 'expiresInDays', $body );
		$this->assertArrayNotHasKey( 'designLimit', $body );
		$this->assertSame( array( $this->product_id ), $filtered );
	}

	public function test_every_press_reuses_the_link_until_its_body_changes(): void {
		$this->start();

		$this->assertSame( self::LINK_URL, self::link_of( $this->button->start( $this->product_id, 0, 1 ) ) );
		$this->assertCount( 1, $this->recorder->requests );

		add_filter(
			'pixonaut_wc_storefront_designer_link',
			static function ( array $body ): array {
				$body['locale'] = 'sv';
				return $body;
			}
		);
		$this->recorder->json( 200, self::listed( 'shl_1', null ) );
		$this->script_link( 'shl_2', self::OTHER_LINK_URL );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->assertSame( self::OTHER_LINK_URL, self::link_of( $this->button->start( $this->product_id, 0, 1 ) ) );
		$this->assertSame(
			array( 'PATCH /v1/share-links/shl_1', 'POST /designer-links', 'DELETE /v1/share-links/shl_1' ),
			array_map( static fn ( array $sent ): string => $sent['method'] . ' ' . preg_replace( '#^.*?(/v1/share-links/\w+|/designer-links)$#', '$1', $sent['url'] ), array_slice( $this->recorder->requests, 1 ) )
		);
		$this->assertSame( array( 'name' => null ), json_decode( (string) $this->recorder->requests[1]['body'], true ) );
		$this->assertSame( self::OTHER_LINK_URL, self::link_of( $this->button->start( $this->product_id, 0, 1 ) ) );
		$this->assertCount( 4, $this->recorder->requests );
	}

	public function test_a_name_another_link_holds_mints_the_link_without_one(): void {
		$this->recorder->json(
			409,
			array(
				'error'    => 'share_link_name_taken',
				'holderId' => 'shl_other',
			)
		);
		$this->script_link();

		$this->assertSame( self::LINK_URL, self::link_of( $this->button->start( $this->product_id, 0, 1 ) ) );

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertArrayNotHasKey( 'name', json_decode( (string) $this->recorder->requests[1]['body'], true ) );
	}

	public function test_a_return_with_no_visit_in_the_session_waits_for_the_customer_to_use_it(): void {
		$this->assertSame( $this->product()->get_permalink(), $this->button->finish( $this->product_id, 0, self::TOKEN ) );

		$this->assertSame( array(), $this->recorder->requests, 'nothing sent to Pixonaut' );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( array(), self::errors() );
		$this->assertSame( array( $this->label( Labels::DESIGN_READY ) ), array_column( wc_get_notices( 'notice' ), 'notice' ) );
		$this->assertNull( $this->waiting() );
		$state = DesignerVisits::key( $this->product_id, 0 );
		$this->assertMatchesRegularExpression( '/<button type="submit"[^>]*name="' . PersonaliseButton::RETRY_FIELD . '" value="' . $state . '">/', $this->render() );

		$this->script_commit();
		$this->button->retry( $state );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( DesignFixture::ID, $this->waiting()?->sides[0]->design_id );
		$this->assertSame( 1, $this->plugin->personalised_designs()->get( (string) $this->kept_key() )['quantity'] ?? null );
	}

	public function test_the_use_my_design_button_shows_beside_personalise_and_never_beside_add_to_cart(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->requests = array();

		$this->button->finish( $this->product_id, 0, self::BACK_TOKEN );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( DesignFixture::ID, $this->waiting()?->sides[0]->design_id );
		$this->assertFalse( as_next_scheduled_action( CartCleanup::ACTION, self::cleanup_args(), CartCleanup::GROUP ) );
		$retry = '/<button type="%s"[^>]*name="' . PersonaliseButton::RETRY_FIELD . '" value="' . DesignerVisits::key( $this->product_id, 0 ) . '" hidden>|<button type="%s"[^>]*name="' . PersonaliseButton::RETRY_FIELD . '" value="' . DesignerVisits::key( $this->product_id, 0 ) . '">/';
		$this->assertMatchesRegularExpression( sprintf( $retry, 'submit', 'submit' ), $this->render() );

		$this->open_design();

		$this->assertMatchesRegularExpression( '/<button type="button"[^>]*name="' . PersonaliseButton::RETRY_FIELD . '"[^>]* hidden>/', $this->render() );
	}

	public function test_a_return_with_no_visit_and_no_token_is_refused(): void {
		$config = self::two_sided();
		$config->save_to( $this->product() );

		$this->button->finish( $this->product_id, 0, self::TOKEN );

		$this->assertSame( array( $this->label( Labels::DESIGN_REQUIRED ) ), self::errors() );
		$this->assertSame( array(), wc_get_notices( 'notice' ) );
		$this->assertStringNotContainsString( PersonaliseButton::RETRY_FIELD, $this->render() );
	}

	public function test_the_link_speaks_the_language_and_lays_its_form_out_as_the_store_chose(): void {
		$this->plugin->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::SWEDISH );
		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );

		$body = $this->start();

		$this->assertSame( 'sv', $body['locale'] );
		$this->assertSame( $this->product()->get_name() . ' (#' . $this->product_id . ') · sv', $body['name'], 'named for a language other than the site’s' );
		$this->assertSame( FormLayout::STEPS, $body['layout'] );
	}

	public function test_each_visitor_gets_a_link_in_the_language_they_browse_the_site_in(): void {
		$visitor = static fn (): string => 'de_DE';
		add_filter( 'determine_locale', $visitor );
		$german  = $this->start();
		remove_filter( 'determine_locale', $visitor );
		$visitor = static fn (): string => 'fr_CA';
		add_filter( 'determine_locale', $visitor );
		$french  = $this->start();
		remove_filter( 'determine_locale', $visitor );

		$this->assertSame( 'de', $german['locale'] );
		$this->assertSame( 'fr', $french['locale'] );
		$this->assertStringEndsWith( ' · fr', $french['name'] );
	}

	public function test_the_workspace_default_mints_the_link_without_a_language(): void {
		$this->plugin->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::WORKSPACE_DEFAULT );

		$body = $this->start();

		$this->assertArrayNotHasKey( 'locale', $body );
	}

	public function test_a_refused_link_sends_the_customer_back_to_the_product(): void {
		$this->recorder->json( 422, array( 'error' => 'unknown_placement' ) );

		$this->assertSame( $this->product()->get_permalink(), $this->button->start( $this->product_id, 0, 1 ) );

		$this->assertSame( array( $this->label( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
	}

	public function test_returning_leaves_the_cart_and_shows_the_design_on_the_product_page(): void {
		$this->start();
		$this->script_commit();

		$back = $this->button->finish( $this->product_id, 0, self::TOKEN );

		$key = (string) $this->kept_key();
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, $key, $this->product()->get_permalink() ), $back );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( array(), self::errors() );
		$this->assertSame( DesignFixture::ID, $this->waiting()?->sides[0]->design_id );

		// A plain visit is fresh.
		$this->assertDoesNotMatchRegularExpression( '/<span class="pixonaut-personalise"[^>]* hidden>/', $this->render() );
		$this->assertSame( '', $this->notices_html() );

		// The Personalise button gives way to WooCommerce's own Add to cart.
		$this->open_design();
		$this->assertMatchesRegularExpression( '/<span class="pixonaut-personalise"[^>]* hidden>/', $this->render() );
		$notice = $this->notices_html();
		$this->assertStringNotContainsString( 'woocommerce-info', $notice );
		$this->assertStringContainsString( esc_html( $this->label( Labels::PERSONALISED ) ), $notice );
		$this->assertStringContainsString( esc_url( HostedCorrection::product_url( $key, 'front' ) ), $notice );
		$this->assertStringContainsString( 'name="' . AddToCart::KEPT_FIELD . '" value="' . $key . '"', $notice );
		$this->assertStringNotContainsString( 'start-over', $notice );

		// The quantity chosen before leaving is the one the page offers.
		$this->go_to( (string) get_permalink( $this->product_id ) );
		$this->open_design();
		$this->assertSame( array( 'input_value' => self::QUANTITY ), $this->button->quantity_args( array( 'input_value' => 1 ), $this->product() ) );

		// The same return a second time changes nothing.
		$requests = count( $this->recorder->requests );
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->button->finish( $this->product_id, 0, self::TOKEN ) );
		$this->assertSame( array( $this->label( Labels::DESIGNER_VISIT_UNKNOWN ) ), self::errors() );
		$this->assertCount( $requests, $this->recorder->requests );
	}

	public function test_add_to_cart_adds_the_waiting_design_with_the_chosen_quantity(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->recorder->requests = array();

		$key = $this->add_from_page( self::QUANTITY );

		$item = WC()->cart->get_cart_item( $key );
		$this->assertSame( self::QUANTITY, $item['quantity'] );
		$line = CartLine::of( $item );
		$this->assertSame( DesignFixture::ID, $line?->sides[0]->design_id );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $line?->consent['kind'] ?? null );
		$this->assertSame( array( 'GET' ), array_column( $this->recorder->requests, 'method' ), 'committed already: only read back' );

		// Right after adding, with no redirect to the cart, the page is fresh.
		$this->assertDoesNotMatchRegularExpression( '/<span class="pixonaut-personalise"[^>]* hidden>/', $this->render() );
		$this->assertSame( '', $this->notices_html() );
	}

	public function test_an_address_held_by_another_session_shows_nothing(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$key = (string) $this->kept_key();
		WC()->session->set( PersonalisedDesigns::SESSION_KEY, null );
		$this->recorder->requests = array();

		$this->open_design( $key );

		$this->assertSame( '', $this->notices_html() );
		$this->assertStringNotContainsString( DesignFixture::ID, $this->button->after_gallery_block( '', array(), null ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_refused_add_keeps_the_design_on_show(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->post_kept();

		$this->button->handle();

		$this->assertStringContainsString( esc_html( $this->label( Labels::PERSONALISED ) ), $this->notices_html() );
	}

	public function test_each_trip_carries_a_state_of_its_own_on_the_shared_link(): void {
		$body  = $this->start();
		$first = $this->trip;
		$this->start();

		$this->assertArrayNotHasKey( 'state', $body, 'the link stays the same for every customer' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9._~-]{1,200}$/', $first );
		$this->assertNotSame( $first, $this->trip );
		$this->assertCount( 1, $this->recorder->requests, 'one link for both trips' );
	}

	public function test_the_setting_off_leaves_the_after_approve_out_of_the_link(): void {
		$this->assertArrayNotHasKey( 'afterApprove', $this->start() );
	}

	public function test_the_setting_off_adds_nothing_from_an_own_return(): void {
		$this->start();
		$this->script_commit();

		$back = $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
	}

	public function test_the_setting_adds_an_own_return_once_and_lands_on_the_lines_page(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->assertSame( 'add_to_cart', $this->start()['afterApprove'] ?? null );
		$this->script_commit();
		$this->recorder->json( 200, DesignFixture::design() );

		$back = $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );

		$key = (string) array_key_first( WC()->cart->get_cart() );
		$this->assertSame( add_query_arg( PersonaliseButton::LINE_ARG, $key, $this->product()->get_permalink() ), $back );
		$this->assertSame( self::QUANTITY, WC()->cart->get_cart_item( $key )['quantity'] );
		$this->assertSame( DesignFixture::ID, CartLine::of( WC()->cart->get_cart_item( $key ) )?->sides[0]->design_id );
		$this->assert_no_form_post_left();
		$this->assertCount( 1, wc_get_notices( 'success' ), 'WooCommerce says it was added, once' );

		// Reloading the return address adds nothing more.
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip ) );
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( self::QUANTITY, WC()->cart->get_cart_contents_count() );
	}

	public function test_the_setting_adds_nothing_from_a_foreign_state_in_a_session_with_an_open_trip(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->start();
		$this->script_commit( array( 'externalRef' => self::cart_ref( self::FOREIGN_TOKEN ) ) );

		// Another session's return carries the state of that session's trip.
		$back = $this->button->finish( $this->product_id, 0, self::FOREIGN_TOKEN, 'another-sessions-trip' );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
	}

	public function test_the_setting_adds_nothing_from_a_return_without_a_state(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->start();
		$this->script_commit();

		$back = $this->button->finish( $this->product_id, 0, self::TOKEN );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
	}

	public function test_the_setting_adds_nothing_from_a_state_already_used(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->start();
		// Cancelling takes the trip, and its state with it.
		$this->assertSame( $this->product()->get_permalink(), $this->button->finish( $this->product_id, 0, null, $this->trip ) );

		$back = $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( $this->product()->get_permalink(), $back );
		$this->assertSame( array(), self::errors() );
		$this->assertSame( array(), array_slice( $this->recorder->requests, 1 ), 'nothing committed until the customer uses it' );
	}

	public function test_the_setting_adds_nothing_from_a_state_kept_for_another_try(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->start();
		$state = DesignerVisits::key( $this->product_id, 0 );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );
		$this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );
		wc_clear_notices();

		$this->script_commit();
		$back = $this->button->retry( $state );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
	}

	public function test_the_setting_lands_on_the_designs_line_when_another_extension_adds_an_item_too(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$gift = new WC_Product_Simple();
		$gift->set_name( 'Gift' );
		$gift->set_regular_price( '0' );
		$gift->save();
		$add_gift = static function () use ( $gift ): void {
			static $adding = false;
			if ( ! $adding ) {
				$adding = true;
				WC()->cart->add_to_cart( $gift->get_id() );
			}
		};
		$this->start();
		$this->script_commit();
		$this->recorder->json( 200, DesignFixture::design() );
		add_action( 'woocommerce_add_to_cart', $add_gift, self::AFTER_THE_PLUGINS_VALIDATION );

		try {
			$back = $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );
		} finally {
			remove_action( 'woocommerce_add_to_cart', $add_gift, self::AFTER_THE_PLUGINS_VALIDATION );
		}

		$this->assertCount( 2, WC()->cart->get_cart() );
		$designs = array_filter( WC()->cart->get_cart(), static fn ( array $item ): bool => null !== CartLine::of( $item ) );
		$this->assertCount( 1, $designs );
		$this->assertSame( add_query_arg( PersonaliseButton::LINE_ARG, (string) array_key_first( $designs ), $this->product()->get_permalink() ), $back );
	}

	public function test_the_setting_answers_to_other_extensions_validation(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->start();
		$this->script_commit();
		$this->recorder->json( 200, DesignFixture::design() );
		add_filter( 'woocommerce_add_to_cart_validation', '__return_false', self::AFTER_THE_PLUGINS_VALIDATION );

		try {
			$back = $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );
		} finally {
			remove_filter( 'woocommerce_add_to_cart_validation', '__return_false', self::AFTER_THE_PLUGINS_VALIDATION );
		}

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
		$this->assert_no_form_post_left();
	}

	public function test_the_setting_stays_off_the_cart_page_on_a_store_set_to_go_there(): void {
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );
		$this->start();
		$this->script_commit();
		$this->recorder->json( 200, DesignFixture::design() );

		$back = $this->button->finish( $this->product_id, 0, self::TOKEN, $this->trip );

		$this->assertStringContainsString( PersonaliseButton::LINE_ARG, $back );
		$this->assertSame( 'yes', get_option( 'woocommerce_cart_redirect_after_add' ) );
	}

	public function test_the_setting_adds_a_variation_with_the_customers_choice(): void {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( "Kid's", 'Adult' ) );
		$attribute->set_variation( true );
		$parent->set_name( 'Personalised hoodie' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->with_layout( Layout::BUTTON )->save_to( $parent );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		// Any size.
		$variation->set_attributes( array( 'size' => '' ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		DesignFixture::sell_variant( $variation );
		$this->plugin->settings()->set( Settings::ADD_AFTER_APPROVE, true );
		$this->script_link();
		$location = $this->button->start( $parent->get_id(), $variation->get_id(), self::QUANTITY, array( 'attribute_size' => "Kid's" ) );
		$this->assertSame( self::LINK_URL, self::link_of( $location ) );
		$this->script_commit();
		$this->recorder->json( 200, DesignFixture::design() );

		$this->button->finish( $parent->get_id(), $variation->get_id(), self::TOKEN, self::trip_of( $location ) );

		$this->assertSame( array(), self::errors() );
		$item = WC()->cart->get_cart_item( (string) array_key_first( WC()->cart->get_cart() ) );
		$this->assertSame( $variation->get_id(), $item['variation_id'] );
		$this->assertSame( "Kid's", $item['variation']['attribute_size'] );
		$this->assertSame( DesignFixture::ID, CartLine::of( $item )?->sides[0]->design_id );
		$this->assert_no_form_post_left();
	}

	/**
	 * The add through WooCommerce's handler left no form post behind for its
	 * own handler to add again.
	 */
	private function assert_no_form_post_left(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- only looks whether they are set.
		$this->assertArrayNotHasKey( 'add-to-cart', $_REQUEST );
		$this->assertArrayNotHasKey( 'quantity', $_REQUEST );
		$this->assertArrayNotHasKey( 'variation_id', $_REQUEST );
		$this->assertArrayNotHasKey( AddToCart::KEPT_FIELD, $_POST );
		// phpcs:enable WordPress.Security.NonceVerification
	}

	public function test_a_foreign_return_in_a_session_with_an_open_trip_adds_nothing(): void {
		$this->start();
		$this->script_commit( array( 'externalRef' => self::cart_ref( self::FOREIGN_TOKEN ) ) );

		// Nothing in another session's return address tells it from this session's own.
		$back = $this->button->finish( $this->product_id, 0, self::FOREIGN_TOKEN );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
	}

	public function test_using_a_kept_return_shows_the_design_and_adds_nothing(): void {
		$this->assertSame( $this->product()->get_permalink(), $this->button->finish( $this->product_id, 0, self::TOKEN ) );
		$this->assertSame( array(), $this->recorder->requests );

		$this->script_commit();
		$back = $this->button->retry( DesignerVisits::key( $this->product_id, 0 ) );

		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( add_query_arg( PersonalisedDesigns::ARG, (string) $this->kept_key(), $this->product()->get_permalink() ), $back );
	}

	public function test_a_see_through_template_with_no_blank_stands_on_white(): void {
		self::no_blank_config()->save_to( $this->product() );
		$this->start();
		$this->script_commit(
			array(
				'blankId'     => null,
				'placementId' => null,
			)
		);
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->assertSame( array(), self::errors() );
		$this->go_to( (string) get_permalink( $this->product_id ) );
		$this->open_design();
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'backgroundSeeThrough' => true ) ), 'application/json' );

		$this->assertStringContainsString( 'pixonaut-design-gallery__paper', $this->button->after_gallery_block( '', array(), null ) );
	}

	public function test_adding_the_same_design_again_makes_a_second_line_with_a_copy(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->script_copy();

		$first  = $this->add_from_page( 1 );
		$second = $this->add_from_page( self::QUANTITY );

		$this->assertNotSame( $first, $second );
		$this->assertCount( 2, WC()->cart->get_cart() );
		$this->assertSame( 1, WC()->cart->get_cart_item( $first )['quantity'] );
		$this->assertSame( self::QUANTITY, WC()->cart->get_cart_item( $second )['quantity'] );
		$this->assertSame( DesignFixture::ID, CartLine::of( WC()->cart->get_cart_item( $first ) )?->sides[0]->design_id );
		$this->assertSame( self::COPY_ID, CartLine::of( WC()->cart->get_cart_item( $second ) )?->sides[0]->design_id );
	}

	public function test_a_copy_pixonaut_refuses_adds_nothing(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->add_from_page();
		$this->recorder->json( 200, DesignFixture::design() )->json( 500, array( 'error' => 'db_error' ) );

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1 ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_NOT_SAVED ) ), self::errors() );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_a_copy_that_cannot_be_read_is_left_to_the_cleanup_job(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->add_from_page();
		$this->recorder
			->json( 200, DesignFixture::design() )
			->json( 201, array( 'designId' => self::COPY_ID ) )
			->json( 500, array( 'error' => 'db_error' ) );

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1 ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_NOT_SAVED ) ), self::errors() );
		$this->assertNotFalse( as_next_scheduled_action( CartCleanup::ACTION, self::copy_cleanup_args(), CartCleanup::GROUP ) );
		$this->assertFalse( as_next_scheduled_action( CartCleanup::ACTION, self::cleanup_args(), CartCleanup::GROUP ) );
	}

	public function test_a_copy_whose_add_is_refused_afterwards_is_deleted(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->add_from_page();
		$this->script_copy();
		$this->post_kept();
		add_filter( 'woocommerce_add_to_cart_validation', '__return_false', self::AFTER_THE_PLUGINS_VALIDATION );

		try {
			$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1 ) );
		} finally {
			remove_filter( 'woocommerce_add_to_cart_validation', '__return_false', self::AFTER_THE_PLUGINS_VALIDATION );
		}
		$this->assertFalse( as_next_scheduled_action( CartCleanup::ACTION, self::copy_cleanup_args(), CartCleanup::GROUP ), 'not before the request ends' );

		$this->plugin->add_to_cart()->discard_unadded();

		$this->assertNotFalse( as_next_scheduled_action( CartCleanup::ACTION, self::copy_cleanup_args(), CartCleanup::GROUP ) );
	}

	public function test_a_copy_that_joined_the_cart_is_kept(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->add_from_page();
		$this->script_copy();
		$this->add_from_page();

		$this->plugin->add_to_cart()->discard_unadded();

		$this->assertFalse( as_next_scheduled_action( CartCleanup::ACTION, self::copy_cleanup_args(), CartCleanup::GROUP ) );
	}

	/**
	 * The arguments of the job deleting the copy.
	 *
	 * @return array<string, mixed>
	 */
	private static function copy_cleanup_args(): array {
		return array( 'design_id' => self::COPY_ID ) + self::cleanup_args();
	}

	/**
	 * Scripts Pixonaut's answers to adding a design a line holds: the read,
	 * the duplicate, the read of the copy, and its ref.
	 */
	private function script_copy(): void {
		$this->recorder
			->json( 200, DesignFixture::design() )
			->json( 201, array( 'designId' => self::COPY_ID ) )
			->json( 200, DesignFixture::design( array( 'id' => self::COPY_ID ) ) )
			->json(
				200,
				array(
					'designId'    => self::COPY_ID,
					'externalRef' => 'copy',
				)
			);
	}

	public function test_add_to_cart_without_a_waiting_design_is_refused(): void {
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1 ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_REQUIRED ) ), self::errors() );
	}

	public function test_a_design_that_no_longer_suits_the_product_leaves_the_page(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design( array( 'placementId' => 'back' ) ) );
		$this->post_kept();

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1 ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_MISMATCH ) ), self::errors() );
		$this->assertNull( $this->waiting() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	/**
	 * A design waiting on the product's page.
	 */
	private function return_with_a_design(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
	}

	/**
	 * Pressing Add to cart is refused with a label, and the design leaves the page.
	 *
	 * @param string $label The label.
	 */
	private function assert_design_outdated( string $label ): void {
		$this->post_kept();
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product_id, 1 ) );

		$this->assertSame( array( $this->label( $label ) ), self::errors() );
		$this->assertNull( $this->waiting() );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertNotFalse( as_next_scheduled_action( CartCleanup::ACTION, self::cleanup_args(), CartCleanup::GROUP ) );
	}

	public function test_a_waiting_design_of_a_variant_no_longer_sold_leaves_the_page(): void {
		$this->return_with_a_design();
		( new ProductVariant( DesignFixture::BLANK_ID, null, 'var_other', '', null, BlankFixture::VARIANT_VIEWS, ProductVariants::attributes( $this->product() ) ) )->save_to( $this->product() );

		$this->assert_design_outdated( Labels::DESIGN_OUTDATED );
	}

	public function test_a_waiting_design_of_a_side_the_product_no_longer_has_leaves_the_page(): void {
		self::two_sided()->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );
		$this->script_link();
		$this->assertSame( self::LINK_URL, self::link_of( $this->button->start( $this->product_id, 0, 1 ) ) );
		$ordered = array(
			'front' => self::TOKEN,
			'back'  => self::BACK_TOKEN,
		);
		$ref     = Refs::cart_ref( self::SITE, Refs::line_key( $ordered, (string) WC()->session->get_customer_id() ) );
		$this->recorder
			->json( 201, self::created( DesignFixture::ID ) )
			->json( 200, DesignFixture::design( array( 'externalRef' => $ref ) ) )
			->json( 201, self::created( self::BACK_DESIGN_ID ) )
			->json(
				200,
				DesignFixture::design(
					array(
						'id'          => self::BACK_DESIGN_ID,
						'placementId' => 'back',
						'externalRef' => $ref,
					)
				)
			);
		$this->button->finish( $this->product_id, 0, $ordered );
		$waiting = $this->waiting();
		$this->assertNotNull( $waiting );
		$this->assertCount( 2, $waiting->sides );
		self::config()->save_to( $this->product() );

		$this->assert_design_outdated( Labels::DESIGN_OUTDATED );
	}

	public function test_a_waiting_design_with_another_standing_design_leaves_the_page(): void {
		self::with_standing_back( 'des_standing_1' )->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );
		$this->return_with_a_design();
		$this->assertSame( 'des_standing_1', $this->waiting()?->standing[0]->design_id );
		self::with_standing_back( 'des_standing_2' )->save_to( $this->product() );

		$this->assert_design_outdated( Labels::DESIGN_OUTDATED );
	}

	public function test_a_waiting_design_takes_the_approval_mode_of_now(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->return_with_a_design();
		$this->assertSame( ApprovalMode::CHECKBOX, $this->waiting()?->approval_mode );
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::PROOF_EMAIL );
		$this->recorder->json( 200, DesignFixture::design() );

		$line = CartLine::of( WC()->cart->get_cart_item( $this->add_from_page() ) );

		$this->assertNotNull( $line );
		$this->assertSame( ApprovalMode::PROOF_EMAIL, $line->approval_mode );
		$this->assertNull( $line->consent );
	}

	public function test_a_line_dropped_as_the_session_loads_forgets_the_design(): void {
		$this->return_with_a_design();
		$this->recorder->json( 200, DesignFixture::design() );
		$item = WC()->cart->get_cart_item( $this->add_from_page() );

		do_action( 'woocommerce_remove_cart_item_from_session', $item['key'], $item );

		$this->assertNull( $this->waiting() );
	}

	public function test_removing_the_line_forgets_the_design(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$key = $this->add_from_page();

		WC()->cart->remove_cart_item( $key );

		$this->assertNull( $this->waiting() );
	}

	public function test_emptying_the_cart_forgets_its_designs(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$this->add_from_page();

		WC()->cart->empty_cart();

		$this->assertNull( $this->waiting() );
	}

	public function test_forgetting_deletes_a_design_no_line_holds(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );

		$this->plugin->personalised_designs()->forget( (string) $this->kept_key() );

		$this->assertNull( $this->waiting() );
		$this->assertStringContainsString( PersonaliseButton::START_FIELD, $this->render() );
		$this->assertNotFalse( as_next_scheduled_action( CartCleanup::ACTION, self::cleanup_args(), CartCleanup::GROUP ) );
	}

	public function test_forgetting_leaves_a_design_a_line_holds(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$key = $this->add_from_page();

		$this->plugin->personalised_designs()->forget( (string) $this->kept_key() );

		$this->assertNull( $this->waiting() );
		$this->assertSame( DesignFixture::ID, CartLine::of( WC()->cart->get_cart_item( $key ) )?->sides[0]->design_id );
		$this->assertFalse( as_next_scheduled_action( CartCleanup::ACTION, self::cleanup_args(), CartCleanup::GROUP ) );
	}

	public function test_a_cart_line_links_to_its_product_page_which_shows_its_design(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$key = $this->add_from_page();
		WC()->session->set( PersonalisedDesigns::SESSION_KEY, null );

		$link = apply_filters( 'woocommerce_cart_item_permalink', $this->product()->get_permalink(), WC()->cart->get_cart_item( $key ), $key );

		$this->assertSame( add_query_arg( PersonaliseButton::LINE_ARG, $key, $this->product()->get_permalink() ), $link );

		$_GET[ PersonaliseButton::LINE_ARG ] = $key;
		try {
			$this->button->handle();
		} finally {
			unset( $_GET[ PersonaliseButton::LINE_ARG ] );
		}
		$this->assertSame( DesignFixture::ID, $this->waiting()?->sides[0]->design_id );
		$this->assertStringContainsString( esc_html( $this->label( Labels::IN_CART ) ), $this->form_html() );
	}

	public function test_adding_lands_on_the_lines_page(): void {
		$key = $this->design_in_cart();

		$this->assertSame(
			add_query_arg( PersonaliseButton::LINE_ARG, $key, $this->product()->get_permalink() ),
			apply_filters( 'woocommerce_add_to_cart_redirect', '', $this->product() )
		);
	}

	public function test_adding_on_a_store_set_to_go_to_the_cart_leaves_the_address_to_woocommerce(): void {
		$this->design_in_cart();
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );

		$this->assertSame( '', apply_filters( 'woocommerce_add_to_cart_redirect', '', $this->product() ) );
	}

	public function test_an_items_page_shows_its_cart_state_in_place_of_the_form(): void {
		$key = $this->design_in_cart();
		WC()->cart->set_quantity( $key, self::QUANTITY );

		$html = $this->form_html();

		$this->assertStringContainsString( esc_html( $this->label( Labels::IN_CART ) ) . ' · ' . self::QUANTITY . ' pcs', $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::CHANGE_LINE_DESIGN ) ), $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::VIEW_CART ) ), $html );
		$this->assertStringContainsString( esc_url( wc_get_cart_url() ), $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::MAKE_ANOTHER ) ), $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->product()->get_permalink() ) . '"', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'name="quantity"', $html );
		$this->assertStringNotContainsString( AddToCart::KEPT_FIELD, $html );
		$this->assertStringNotContainsString( 'single_add_to_cart_button', $html );
	}

	public function test_the_address_of_a_return_shows_the_cart_state_once_the_design_is_added(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$kept = $this->kept_key();
		$this->recorder->json( 200, DesignFixture::design() );
		$this->add_from_page();

		$this->open_design( $kept );

		$this->assertStringContainsString( esc_html( $this->label( Labels::IN_CART ) ), $this->form_html() );
	}

	public function test_a_design_not_in_the_cart_keeps_the_form(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );

		$html = $this->form_html();

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( esc_html( $this->label( Labels::IN_CART ) ), $html );
	}

	public function test_a_plain_visit_keeps_the_form_while_the_design_is_in_the_cart(): void {
		$this->design_in_cart();
		$this->button->added();

		$this->assertStringContainsString( '<form', $this->form_html() );
	}

	public function test_another_product_keeps_its_form_after_the_cart_state_printed(): void {
		$this->design_in_cart();
		$this->form_html();

		$other = new WC_Product_Simple();
		$other->set_name( 'Mug' );
		$other->set_regular_price( '9' );
		$other->save();
		$GLOBALS['product'] = $other;
		ob_start();
		woocommerce_template_single_add_to_cart();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product'] );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::IN_CART ) ), $this->form_html(), 'the item keeps its cart state too' );
	}

	public function test_an_items_page_prints_its_own_variations_options_and_price_whatever_the_address_says(): void {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'M' ) );
		$attribute->set_variation( true );
		$parent->set_name( 'Personalised hoodie' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->with_layout( Layout::BUTTON )->save_to( $parent );
		$bought = null;
		foreach ( array(
			'S' => '30',
			'M' => '70',
		) as $size => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( $price );
			$variation->save();
			DesignFixture::sell_variant( $variation );
			$bought ??= $variation;
		}
		$this->script_link();
		$this->button->start( $parent->get_id(), $bought->get_id(), 1, array( 'attribute_size' => 'S' ) );
		$this->script_commit();
		$this->button->finish( $parent->get_id(), $bought->get_id(), self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );
		$attributes = array( 'attribute_size' => 'S' );
		$this->post_kept( $parent->get_id(), $bought->get_id() );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 1, $bought->get_id(), $attributes ) );
		$key = WC()->cart->add_to_cart( $parent->get_id(), 1, $bought->get_id(), $attributes );
		$this->assertIsString( $key );

		$this->go_to( add_query_arg( 'attribute_size', 'M', (string) get_permalink( $parent->get_id() ) ) );
		$_GET['attribute_size']              = 'M';
		$_GET[ PersonaliseButton::LINE_ARG ] = $key;
		try {
			$this->button->handle();
		} finally {
			unset( $_GET[ PersonaliseButton::LINE_ARG ], $_GET['attribute_size'] );
		}

		$page = wc_get_product( $parent->get_id() );
		$this->assertInstanceOf( WC_Product::class, $page );
		$this->assertStringContainsString( '30', $page->get_price_html() );
		$this->assertStringNotContainsString( '70', $page->get_price_html() );
		$this->assertStringContainsString( $this->label( Labels::IN_CART ) . ' · S · 1 pc', $this->button->after_form_block( '', array(), null ) );
	}

	public function test_the_form_block_gives_way_to_the_cart_state(): void {
		$this->design_in_cart();

		$html = $this->form_block_html();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::VIEW_CART ) ), $html );
	}

	public function test_the_form_block_stays_for_a_design_not_in_the_cart(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );

		$html = $this->form_block_html();

		$this->assertSame( '<form></form>', $html );
	}

	public function test_the_design_stands_after_the_gallery_in_woocommerces_classes(): void {
		$this->start();
		$this->script_commit();
		$this->button->finish( $this->product_id, 0, self::TOKEN );

		$this->go_to( (string) get_permalink( $this->product_id ) );
		$this->open_design();

		$html = $this->button->after_gallery_block( '<div>gallery</div>', array(), null );

		$this->assertStringStartsWith( '<div>gallery</div><div class="images pixonaut-design-gallery"', $html );
		$this->assertStringContainsString( 'woocommerce-product-gallery__image', $html );
		$this->assertStringContainsString( DesignFixture::ID, $html );
	}

	public function test_the_button_stands_in_woocommerces_own_classes(): void {
		$this->assertStringContainsString( 'single_add_to_cart_button button alt', $this->render() );
	}

	public function test_a_state_this_session_never_opened_adds_nothing(): void {
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->button->finish( 0, 0, self::TOKEN, 'state-of-another-session' ) );

		$this->assertSame( array( $this->label( Labels::DESIGNER_VISIT_UNKNOWN ) ), self::errors() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_cancelling_returns_to_the_product_with_nothing_added(): void {
		$this->start();

		$this->assertSame( $this->product()->get_permalink(), $this->button->finish( $this->product_id, 0, null ) );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( array(), self::errors() );
	}

	public function test_the_hosted_approval_is_the_consent_in_the_checkbox_mode(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->start();
		$this->script_commit();

		$this->button->finish( $this->product_id, 0, self::TOKEN );
		$this->recorder->json( 200, DesignFixture::design() );

		// No box to tick: the form posts none.
		$line = CartLine::of( WC()->cart->get_cart_item( $this->add_from_page() ) );
		$this->assertNotNull( $line );
		$this->assertSame( ApprovalMode::CHECKBOX, $line->consent['kind'] ?? null );
	}

	public function test_front_and_back_come_back_as_tokens_keyed_by_side_and_join_one_line(): void {
		$config = self::two_sided();
		$config->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );
		$body = $this->start();
		$this->assertSame( array( 'front', 'back' ), array_column( $body['sides'], 'key' ) );
		$this->assertSame( array( 'Front', 'Back' ), array_column( $body['sides'], 'label' ) );
		$this->assertArrayNotHasKey( 'blankId', $body );

		$tokens  = array(
			'back'  => self::BACK_TOKEN,
			'front' => self::TOKEN,
		);
		$ordered = array(
			'front' => self::TOKEN,
			'back'  => self::BACK_TOKEN,
		);
		$ref     = Refs::cart_ref( self::SITE, Refs::line_key( $ordered, (string) WC()->session->get_customer_id() ) );
		$this->recorder
			->json( 201, self::created( DesignFixture::ID ) )
			->json( 200, DesignFixture::design( array( 'externalRef' => $ref ) ) )
			->json( 201, self::created( self::BACK_DESIGN_ID ) )
			->json(
				200,
				DesignFixture::design(
					array(
						'id'          => self::BACK_DESIGN_ID,
						'placementId' => 'back',
						'externalRef' => $ref,
					)
				)
			);

		$this->button->finish( $this->product_id, 0, $tokens );

		$this->assertSame( array(), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
		$line = $this->waiting();
		$this->assertNotNull( $line );
		$this->assertSame( array( DesignFixture::ID, self::BACK_DESIGN_ID ), array_map( static fn ( $side ): string => $side->design_id, $line->sides ) );
		$this->open_design();
		$notice = $this->notices_html();
		$this->assertStringContainsString( esc_html( sprintf( $this->label( Labels::CHANGE_DESIGN_SIDE ), 'Front' ) ), $notice );
		$this->assertStringContainsString( esc_html( sprintf( $this->label( Labels::CHANGE_DESIGN_SIDE ), 'Back' ) ), $notice );
	}

	public function test_a_single_token_for_a_two_sided_product_is_refused(): void {
		self::two_sided()->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );
		$this->start();

		$this->assertSame( $this->product()->get_permalink(), $this->button->finish( $this->product_id, 0, self::TOKEN ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_REQUIRED ) ), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_a_failed_commit_keeps_the_design_to_try_again_once(): void {
		$this->start();
		$state = DesignerVisits::key( $this->product_id, 0 );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		$this->assertSame( $this->product()->get_permalink(), $this->button->finish( $this->product_id, 0, self::TOKEN ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_NOT_SAVED ) ), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
		$html = $this->render();
		$this->assertStringContainsString( 'name="' . PersonaliseButton::RETRY_FIELD . '" value="' . $state . '"', $html );
		$this->assertStringContainsString( esc_html( $this->label( Labels::ADD_DESIGN_AGAIN ) ), $html );

		wc_clear_notices();
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->button->finish( $this->product_id, 0, self::TOKEN ) );
		$this->assertStringContainsString( 'name="' . PersonaliseButton::RETRY_FIELD . '" value="' . $state . '"', $this->render() );

		wc_clear_notices();
		$this->script_commit();
		$this->button->retry( $state );

		$this->assertSame( array(), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( DesignFixture::ID, $this->waiting()?->sides[0]->design_id );
		$this->assertSame( self::QUANTITY, $this->plugin->personalised_designs()->get( (string) $this->kept_key() )['quantity'] ?? null );
		$this->assertStringNotContainsString( PersonaliseButton::RETRY_FIELD, $this->render() );
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->button->retry( $state ) );
	}

	public function test_a_design_that_does_not_match_is_not_kept(): void {
		$this->start();
		$this->script_commit( array( 'placementId' => 'back' ) );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->button->finish( $this->product_id, 0, self::TOKEN );

		$this->assertSame( array( $this->label( Labels::DESIGN_MISMATCH ) ), self::errors() );
		$this->assertStringNotContainsString( PersonaliseButton::RETRY_FIELD, $this->render() );
	}

	public function test_no_link_is_minted_for_a_product_customers_cannot_see(): void {
		$product = $this->product();
		$product->set_status( 'draft' );
		$product->save();

		$draft = $this->button->start( $this->product_id, 0, 1 );
		$product->set_status( 'publish' );
		$product->save();
		wp_update_post(
			array(
				'ID'            => $this->product_id,
				'post_password' => 'secret',
			)
		);
		$protected = $this->button->start( $this->product_id, 0, 1 );

		$this->assertSame( array( home_url( '/' ), home_url( '/' ) ), array( $draft, $protected ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_variation_keeps_the_customers_choice_for_an_any_attribute(): void {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'M' ) );
		$attribute->set_variation( true );
		$parent->set_name( 'Personalised hoodie' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->with_layout( Layout::BUTTON )->save_to( $parent );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		// Any size.
		$variation->set_attributes( array( 'size' => '' ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		DesignFixture::sell_variant( $variation );

		$this->assertSame( (string) get_permalink( $parent->get_id() ), $this->button->start( $parent->get_id(), 0, 1 ), 'no variation chosen: it asks for one' );
		wc_clear_notices();
		$this->assertSame( home_url( '/' ), $this->button->start( $this->product_id, $variation->get_id(), 1 ) );
		$this->assertSame( array(), $this->recorder->requests );

		$this->script_link();
		$this->assertSame( self::LINK_URL, self::link_of( $this->button->start( $parent->get_id(), $variation->get_id(), 1, array( 'attribute_size' => 'M' ) ) ) );
		$this->script_commit();
		$back = $this->button->finish( $parent->get_id(), $variation->get_id(), self::TOKEN );

		$this->assertSame( array(), self::errors() );
		$this->assertStringContainsString( 'attribute_size=M', $back, 'the page opens on the chosen options' );
		$this->assertNotNull( $this->waiting( $parent->get_id(), $variation->get_id() ) );
		$this->assertNull( $this->waiting( $parent->get_id() ), 'another variation keeps no design' );
		$this->recorder->json( 200, DesignFixture::design() );
		$attributes = array( 'attribute_size' => 'M' );
		$this->post_kept( $parent->get_id(), $variation->get_id() );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $parent->get_id(), 1, $variation->get_id(), $attributes ) );
		$key = WC()->cart->add_to_cart( $parent->get_id(), 1, $variation->get_id(), $attributes );
		$this->assertIsString( $key );
		$this->assertSame( $attributes, WC()->cart->get_cart_item( $key )['variation'] );
	}

	public function test_a_variable_products_button_follows_each_variations_configuration(): void {
		$variations = $this->variable(
			self::config()->with_layout( Layout::BUTTON ),
			array(
				'S' => null,
				'M' => ( new ProductConfig() )->with_override( true ),
				'L' => self::config()->with_layout( Layout::INLINE )->with_override( true ),
			)
		);

		$html = $this->render_for( $variations['S']->get_parent_id() );

		$this->assertSame(
			array(
				$variations['S']->get_id() => 'personalised',
				$variations['M']->get_id() => 'plain',
				$variations['L']->get_id() => 'unavailable',
			),
			self::button_states( $html )
		);
		$this->assertMatchesRegularExpression( '/<span class="pixonaut-personalise" data-pixonaut-variations="[^"]*"[^>]*(?<!hidden)>/', $html );
		$this->assertMatchesRegularExpression( '/<button type="submit" [^>]*pixonaut-personalise__button[^>]*(?<! hidden)>/', $html );
		$this->assertStringContainsString( 'name="' . PersonaliseButton::START_FIELD . '"', $html );
		$this->assertTrue( wp_script_is( Storefront::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_a_variation_with_no_layout_of_its_own_follows_its_products_button(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::INLINE );
		$variations = $this->variable(
			self::config()->with_layout( Layout::BUTTON ),
			array(
				'S' => self::config()->with_override( true ),
				'M' => self::config()->with_layout( Layout::INLINE )->with_override( true ),
			)
		);

		$this->assertSame(
			array(
				$variations['S']->get_id() => 'personalised',
				$variations['M']->get_id() => 'unavailable',
			),
			self::button_states( $this->render_for( $variations['S']->get_parent_id() ) )
		);
		$this->assertNotNull( $this->button->config_for( $variations['S'] ) );
		$this->assertNull( $this->button->config_for( $variations['M'] ), 'one that sets inline stays inline' );
	}

	public function test_a_variation_with_no_layout_on_a_product_with_none_follows_the_store(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
		$variations = $this->variable(
			self::config(),
			array( 'S' => self::config()->with_override( true ) )
		);

		$this->assertNotNull( $this->button->config_for( $variations['S'] ) );
	}

	public function test_pressing_the_button_with_no_variation_chosen_asks_for_the_options(): void {
		$variations = $this->variable(
			self::config()->with_layout( Layout::BUTTON ),
			array(
				'S' => null,
				'M' => null,
			)
		);
		$parent_id  = $variations['S']->get_parent_id();
		$permalink  = (string) get_permalink( $parent_id );
		$requests   = count( $this->recorder->requests );

		$this->assertSame( add_query_arg( 'attribute_size', '', $permalink ), $this->button->start( $parent_id, 0, self::QUANTITY, array( 'attribute_size' => '' ) ) );

		$this->assertSame( array( sprintf( $this->label( Labels::CHOOSE_OPTIONS ), 'size' ) ), self::errors() );
		$this->assertCount( $requests, $this->recorder->requests, 'no link was asked for' );
		$this->assertNull( WC()->session->get( DesignerVisits::SESSION_KEY ), 'and no visit opened' );
	}

	public function test_asking_for_the_options_keeps_those_the_customer_chose(): void {
		$variations = $this->variable( self::config()->with_layout( Layout::BUTTON ), array( 'S' => null ) );
		$parent_id  = $variations['S']->get_parent_id();

		$back = $this->button->start(
			$parent_id,
			0,
			1,
			array(
				'attribute_colour' => 'Dark blue',
				'attribute_size'   => '',
			)
		);

		$query = array();
		wp_parse_str( (string) wp_parse_url( $back, PHP_URL_QUERY ), $query );
		$this->assertSame( 'Dark blue', $query['attribute_colour'] );
		$this->assertSame( '', $query['attribute_size'], 'a cleared option stays cleared rather than opening on its default' );
	}

	public function test_pressing_the_button_on_a_variable_product_customers_cannot_see_says_nothing_of_it(): void {
		$variations = $this->variable( self::config()->with_layout( Layout::BUTTON ), array( 'S' => null ) );
		$parent     = wc_get_product( $variations['S']->get_parent_id() );
		$this->assertInstanceOf( WC_Product::class, $parent );
		$parent->set_status( 'draft' );
		$parent->save();

		$this->assertSame( home_url( '/' ), $this->button->start( $parent->get_id(), 0, 1 ) );
		$this->assertSame( array(), self::errors() );
	}

	public function test_a_product_only_its_variations_personalise_starts_with_the_button_hidden(): void {
		$variations = $this->variable(
			new ProductConfig(),
			array(
				'S' => self::config()->with_layout( Layout::BUTTON )->with_override( true ),
				'M' => null,
			)
		);

		$html = $this->render_for( $variations['S']->get_parent_id() );

		$this->assertSame(
			array(
				$variations['S']->get_id() => 'personalised',
				$variations['M']->get_id() => 'plain',
			),
			self::button_states( $html )
		);
		$this->assertMatchesRegularExpression( '/<span class="pixonaut-personalise" data-pixonaut-variations="[^"]*"[^>]* hidden>/', $html );
		$this->assertMatchesRegularExpression( '/<button type="button" [^>]*pixonaut-personalise__button[^>]* hidden>/', $html );
		$this->assertStringNotContainsString( '<button type="submit"', $html );
		$this->assertStringContainsString( '<button type="button"', $html );
	}

	public function test_a_variation_on_the_inline_layout_leaves_the_page_to_the_inline_designer(): void {
		$variations = $this->variable(
			new ProductConfig(),
			array(
				'S' => self::config()->with_layout( Layout::BUTTON )->with_override( true ),
				'M' => self::config()->with_layout( Layout::INLINE )->with_override( true ),
			)
		);

		$this->assertSame( '', $this->render_for( $variations['S']->get_parent_id() ) );
	}

	public function test_a_variation_that_is_not_personalised_is_added_to_the_cart_as_it_is(): void {
		$variations = $this->variable(
			self::config()->with_layout( Layout::BUTTON ),
			array(
				'S' => null,
				'M' => ( new ProductConfig() )->with_override( true ),
			)
		);
		$plain      = $variations['M'];
		$parent_id  = $plain->get_parent_id();

		$this->assertSame( $plain->get_permalink(), $this->button->start( $parent_id, $plain->get_id(), 1, array( 'attribute_size' => 'M' ) ) );
		$key = self::form_add( $parent_id, $plain->get_id(), 'M' );

		$this->assertIsString( $key );
		$this->assertSame( array(), self::errors() );
		$this->assertArrayNotHasKey( CartLine::KEY, WC()->cart->get_cart_item( $key ) );
		$this->assertSame( array(), $this->recorder->requests );

		// The personalised variation still needs its design.
		$this->assertFalse( self::form_add( $parent_id, $variations['S']->get_id(), 'S' ) );
		$this->assertSame( array( $this->label( Labels::DESIGN_REQUIRED ) ), self::errors() );
	}

	/**
	 * Adds a variation as WooCommerce's form handler does: validation, then the cart.
	 *
	 * @param int    $parent_id    The variable product.
	 * @param int    $variation_id The variation.
	 * @param string $size         Its size.
	 *
	 * @return string|bool The cart item key, or false when refused.
	 */
	private static function form_add( int $parent_id, int $variation_id, string $size ): string|bool {
		$attributes = array( 'attribute_size' => $size );
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, 1, $variation_id, $attributes ) ) {
			return false;
		}
		return WC()->cart->add_to_cart( $parent_id, 1, $variation_id, $attributes );
	}

	/**
	 * A variable product sized S, M and L, each variation priced and with its
	 * own configuration when one is given.
	 *
	 * @param ProductConfig                     $config    The product's configuration.
	 * @param array<string, ProductConfig|null> $overrides Each size's own configuration; null inherits.
	 * @return array<string, WC_Product_Variation> The variations by size.
	 */
	private function variable( ProductConfig $config, array $overrides ): array {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array_keys( $overrides ) );
		$attribute->set_variation( true );
		$parent->set_name( 'Personalised hoodie' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		$config->save_to( $parent );
		DesignFixture::sell_variant( $parent );
		$variations = array();
		foreach ( $overrides as $size => $own ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$own?->save_to( $variation );
			DesignFixture::sell_variant( $variation );
			$variations[ $size ] = $variation;
		}
		return $variations;
	}

	private function render_for( int $product_id ): string {
		$GLOBALS['product'] = wc_get_product( $product_id );
		ob_start();
		$this->button->render();
		unset( $GLOBALS['product'] );
		return (string) ob_get_clean();
	}

	/**
	 * The button's state by variation id, as the page prints it.
	 *
	 * @param string $html The rendered button.
	 * @return array<int, string>
	 */
	private static function button_states( string $html ): array {
		preg_match( '/data-pixonaut-variations="([^"]*)"/', $html, $matches );
		self::assertNotEmpty( $matches );
		$states = json_decode( html_entity_decode( $matches[1] ), true );
		return is_array( $states ) ? array_combine( array_map( 'intval', array_keys( $states ) ), $states ) : array();
	}

	private static function two_sided(): ProductConfig {
		$config = self::config();
		$front  = $config->sides[0];
		$back   = new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, null, $front->template_name, $front->print_sizes );
		return new ProductConfig( true, false, $config->blank_id, $config->blank_name, array( $front, $back ) );
	}

	/**
	 * The fixture's front to personalise and a back printed from a standing design.
	 *
	 * @param string $design_id The standing design.
	 */
	private static function with_standing_back( string $design_id ): ProductConfig {
		$config = self::config();
		$front  = $config->sides[0];
		return $config->with_sides( array( $front, new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, null, $front->template_name, $front->print_sizes, mode: Side::STANDING, standing_design_id: $design_id ) ) );
	}

	/**
	 * A designer link as `GET /v1/share-links` and `PATCH /v1/share-links/{id}` answer it.
	 *
	 * @param string      $id   The link.
	 * @param string|null $name Its name.
	 * @return array<string, mixed>
	 */
	public static function listed( string $id, ?string $name ): array {
		return array(
			'id'             => $id,
			'kind'           => 'designer',
			'subjectId'      => DesignFixture::TEMPLATE_BASE_ID,
			'mayDecide'      => false,
			'returnUrl'      => null,
			'name'           => $name,
			'brandId'        => null,
			'designLimit'    => null,
			'printAreaFrame' => false,
			'placementFrame' => false,
			'locale'         => null,
			'createdAt'      => 0,
			'expiresAt'      => null,
			'designs'        => 0,
			'createdBy'      => null,
			'createdByKey'   => null,
			'source'         => 'key',
			'lastOpenedAt'   => null,
			'subject'        => null,
			'url'            => self::LINK_URL,
		);
	}

	/**
	 * @param string $design_id The design a commit made.
	 * @return array<string, mixed>
	 */
	private static function created( string $design_id ): array {
		return array(
			'designId'      => $design_id,
			'printFloorDpi' => 150,
			'lowResolution' => array(),
		);
	}
}
