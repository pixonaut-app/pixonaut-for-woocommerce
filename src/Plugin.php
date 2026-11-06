<?php
/**
 * Plugin bootstrap.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Closure;
use LogicException;
use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\ActOnOrderLine;
use Pixonaut\WooCommerce\Abilities\ChangeDeliveryAddress;
use Pixonaut\WooCommerce\Abilities\ConnectionAction;
use Pixonaut\WooCommerce\Abilities\ConnectionStatus;
use Pixonaut\WooCommerce\Bridge\Discovery;
use Pixonaut\WooCommerce\Bridge\Status;
use Pixonaut\WooCommerce\Abilities\LinkProduct;
use Pixonaut\WooCommerce\Abilities\ListProducts;
use Pixonaut\WooCommerce\Abilities\LineView;
use Pixonaut\WooCommerce\Abilities\ListTemplates;
use Pixonaut\WooCommerce\Abilities\MoveProductAttribute;
use Pixonaut\WooCommerce\Abilities\MoveProductTemplate;
use Pixonaut\WooCommerce\Abilities\PersonalisedOrders;
use Pixonaut\WooCommerce\Abilities\ProductLinks;
use Pixonaut\WooCommerce\Abilities\ProductSetup;
use Pixonaut\WooCommerce\Abilities\ReadPluginSettings;
use Pixonaut\WooCommerce\Abilities\RecheckProducts;
use Pixonaut\WooCommerce\Abilities\RegenerateProductPictures;
use Pixonaut\WooCommerce\Abilities\ResetStandingLayout;
use Pixonaut\WooCommerce\Abilities\SetProductSetup;
use Pixonaut\WooCommerce\Abilities\SetProductShowcase;
use Pixonaut\WooCommerce\Abilities\SetupFields;
use Pixonaut\WooCommerce\Abilities\Store\StoreResources;
use Pixonaut\WooCommerce\Abilities\UnlinkProduct;
use Pixonaut\WooCommerce\Abilities\UpdatePluginSettings;
use Pixonaut\WooCommerce\Abilities\UseProductPicture;
use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\ConnectNotice;
use Pixonaut\WooCommerce\Admin\PluginLinks;
use Pixonaut\WooCommerce\Admin\DeveloperTools;
use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\PictureFields;
use Pixonaut\WooCommerce\Admin\RegenerateNow;
use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use Pixonaut\WooCommerce\Admin\ShowcaseField;
use Pixonaut\WooCommerce\Admin\LayoutAdjustments;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\StatusReport;
use Pixonaut\WooCommerce\Admin\VariantField;
use Pixonaut\WooCommerce\Admin\TrashedProducts;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Approval\ChangesRequested;
use Pixonaut\WooCommerce\Approval\DesignApproved;
use Pixonaut\WooCommerce\Approval\DesignRejected;
use Pixonaut\WooCommerce\Approval\LineRefund;
use Pixonaut\WooCommerce\Approval\OrderEdit;
use Pixonaut\WooCommerce\Approval\OrderEditRoute;
use Pixonaut\WooCommerce\Approval\ProofDeadline;
use Pixonaut\WooCommerce\Approval\ProofDenial;
use Pixonaut\WooCommerce\Approval\ProofRequest;
use Pixonaut\WooCommerce\Approval\ProofReturn;
use Pixonaut\WooCommerce\Approval\ProofState;
use Pixonaut\WooCommerce\Approval\ProofTimers;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\DesignerLinks;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\RequestLog;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductLinker;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\PictureRunner;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\Purchasability;
use Pixonaut\WooCommerce\Catalogue\StandingRows;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CheckoutCheck;
use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Cart\CartEdit;
use Pixonaut\WooCommerce\Cart\CommitFailures;
use Pixonaut\WooCommerce\Cart\CartEditRoute;
use Pixonaut\WooCommerce\Cart\CartProofs;
use Pixonaut\WooCommerce\Cart\LinePicture;
use Pixonaut\WooCommerce\Cart\DesignBasis;
use Pixonaut\WooCommerce\Cart\ErasedFieldValues;
use Pixonaut\WooCommerce\Cart\CorrectionTrips;
use Pixonaut\WooCommerce\Cart\OrderAgain;
use Pixonaut\WooCommerce\Cart\OrderLines;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\VariantWatch;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\Credentials;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Emails\Emails;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\CancelTriggers;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\DesignChanged;
use Pixonaut\WooCommerce\Fulfilment\Fulfiller;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentSweep;
use Pixonaut\WooCommerce\Fulfilment\LineDisplay;
use Pixonaut\WooCommerce\Fulfilment\LinePictures;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineReader;
use Pixonaut\WooCommerce\Fulfilment\LineCancellation;
use Pixonaut\WooCommerce\Fulfilment\OrderCancelled;
use Pixonaut\WooCommerce\Fulfilment\OrderDeliveryChanged;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Fulfilment\OrderFailed;
use Pixonaut\WooCommerce\Fulfilment\OrderHeld;
use Pixonaut\WooCommerce\Fulfilment\OrderReleased;
use Pixonaut\WooCommerce\Fulfilment\OrderStatusChanged;
use Pixonaut\WooCommerce\Fulfilment\OrderSubmitted;
use Pixonaut\WooCommerce\Fulfilment\OrderTrackingChanged;
use Pixonaut\WooCommerce\Fulfilment\Shipments;
use Pixonaut\WooCommerce\Migrations\MigrationRunner;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Privacy\LineErasure;
use Pixonaut\WooCommerce\Privacy\PersonalDataEraser;
use Pixonaut\WooCommerce\Privacy\PersonalDataExporter;
use Pixonaut\WooCommerce\Privacy\PolicyText;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofRoute;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\ShopTools\ShopToolRoute;
use Pixonaut\WooCommerce\ShopTools\ShopTools;
use Pixonaut\WooCommerce\Storefront\AddAfterApprove;
use Pixonaut\WooCommerce\Storefront\DesignerLinkChanges;
use Pixonaut\WooCommerce\Storefront\DesignerLinkWithdrawals;
use Pixonaut\WooCommerce\Storefront\DesignerVisits;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Storefront\CorrectionMount;
use Pixonaut\WooCommerce\Storefront\HostedCorrection;
use Pixonaut\WooCommerce\Storefront\InlineProducts;
use Pixonaut\WooCommerce\Storefront\MovedAttributeLinks;
use Pixonaut\WooCommerce\Storefront\PersonaliseButton;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Storefront\ButtonProducts;
use Pixonaut\WooCommerce\Storefront\OpeningPrice;
use Pixonaut\WooCommerce\Storefront\PrintedSwatches;
use Pixonaut\WooCommerce\Storefront\ServerReads;
use Pixonaut\WooCommerce\Storefront\Storefront;
use Pixonaut\WooCommerce\Storefront\UnavailableReports;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Updates\Updates;
use Pixonaut\WooCommerce\Updates\UpgradeNotice;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use Pixonaut\WooCommerce\Webhooks\EventsPull;
use Pixonaut\WooCommerce\Webhooks\EventStore;
use Pixonaut\WooCommerce\Webhooks\Intake;
use Pixonaut\WooCommerce\Webhooks\Processor;
use Pixonaut\WooCommerce\Webhooks\Receiver;

/**
 * Wires the plugin into WordPress. Modules are constructed here by hand.
 */
final class Plugin {

	public const VERSION = '1.0.0'; // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- x-release-please-version

	/**
	 * The plugin's slug: its directory and main file on a store, and its text domain.
	 */
	public const SLUG = 'pixonaut-for-woocommerce';

	public const TEXT_DOMAIN = self::SLUG;

	/**
	 * WooCommerce features the plugin declares itself compatible with.
	 */
	public const COMPATIBLE_FEATURES = array( 'custom_order_tables', 'cart_checkout_blocks' );

	/**
	 * Namespace of the plugin's REST routes, under `/wp-json/`.
	 */
	public const REST_NAMESPACE = 'pixonaut/v1';

	private static ?self $instance = null;

	private ?Settings $settings = null;

	private ?Labels $labels = null;

	private ?Templates $templates = null;

	private ?Notices $notices = null;

	private ?ConnectionStore $connection_store = null;

	private ?DeliveryChange $delivery_change = null;

	private ?Pixonaut $pixonaut = null;

	private ?Connection $connection = null;

	private ?StoreAccess $store_access = null;

	private ?WorkspaceSettings $workspace_settings = null;

	private ?Updates $updates = null;

	private ?Catalogue $catalogue = null;

	private ?CatalogueCache $catalogue_cache = null;

	private ?CatalogueSearch $catalogue_search = null;

	private ?PictureQueue $picture_queue = null;

	private ?ProductPictures $product_pictures = null;

	private ?PictureRunner $picture_runner = null;


	private ?ProductRecheck $product_recheck = null;

	private ?ProductVariants $product_variants = null;

	private ?Processor $webhook_processor = null;

	private ?Endpoint $webhook_endpoint = null;

	private ?Intake $intake = null;

	private ?EventsPull $events_pull = null;

	private ?RequestLog $request_log = null;

	private ?FulfilmentJobs $fulfilment_jobs = null;

	private ?OrderStatusChanged $order_status_changed = null;

	private ?DesignChanged $design_changed = null;

	private ?InlineProducts $inline_products = null;

	private ?CartEdit $cart_edit = null;

	private ?OrderEdit $order_edit = null;

	private ?Storefront $storefront = null;

	private ?OpeningPrice $opening_price = null;

	private ?ProofUrls $proof_urls = null;

	private ?ProofCache $proof_cache = null;

	private ?AddToCart $add_to_cart = null;

	private ?OrderAgain $order_again = null;

	private ?CartCleanup $cart_cleanup = null;

	private ?ServerReads $server_reads = null;

	private ?PersonaliseButton $personalise_button = null;

	private ?PersonalisedDesigns $personalised_designs = null;

	private ?KeptDesignerLinks $designer_links = null;

	private ?DesignerLinkWithdrawals $designer_link_withdrawals = null;

	/**
	 * @param string          $file                Absolute path of the main plugin file.
	 * @param MigrationRunner $migrations          Runs pending schema migrations.
	 * @param string|null     $woocommerce_version The WooCommerce version to check; null reads the one that loaded.
	 */
	public function __construct(
		private readonly string $file,
		private readonly MigrationRunner $migrations,
		private readonly ?string $woocommerce_version = null,
	) {
	}

	/**
	 * Creates the plugin once and registers its hooks.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file, new MigrationRunner( Migrations::all() ) );
			self::$instance->register();
		}
		return self::$instance;
	}

	/**
	 * The plugin, once booted.
	 *
	 * @throws LogicException Before `boot()`.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			throw new LogicException( 'Pixonaut for WooCommerce has not booted.' );
		}
		return self::$instance;
	}

	public function register(): void {
		( new DeprecatedHooks() )->register();
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
	}

	public function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}
		foreach ( self::COMPATIBLE_FEATURES as $feature ) {
			FeaturesUtil::declare_compatibility( $feature, $this->file, true );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}

	public function on_plugins_loaded(): void {
		// Updates come first: a store without WooCommerce still gets the release that may fix that.
		$this->updates()->register();
		if ( ! Requirements::woocommerce_active() ) {
			add_action( 'admin_notices', array( Requirements::class, 'woocommerce_notice' ) );
			return;
		}
		if ( ! Requirements::woocommerce_supported( $this->woocommerce_version ?? Requirements::woocommerce_version() ) ) {
			add_action( 'admin_notices', array( Requirements::class, 'woocommerce_version_notice' ) );
			return;
		}
		require_once __DIR__ . '/functions.php';
		$this->migrations->run();
		$this->request_log()->register();
		$this->notices()->register();
		$this->connection()->register();
		$this->store_access()->register();
		( new ShopToolRoute( $this->store_access(), new ShopTools( fn (): ProductLinks => $this->product_links() ), new WcLogger() ) )->register();
		$this->workspace_settings()->register();
		$this->webhook_processor()->register();
		( new Receiver( $this->connection(), $this->intake(), new WcLogger() ) )->register();
		$this->events_pull()->register();
		$this->webhook_endpoint()->register();
		$this->fulfilment_jobs()->register();
		( new FulfilmentSweep( FulfilmentJobs::schedule( ... ), $this->active_connection(), new WcLogger() ) )->register();
		$this->line_cancellation()->register();
		( new CancelTriggers( $this->line_cancellation() ) )->register();
		$this->delivery_change()->register();
		$erasure = $this->line_erasure();
		$erasure->register();
		( new PersonalDataEraser( $erasure ) )->register();
		( new PersonalDataExporter( $this->labels() ) )->register();
		( new PolicyText() )->register();
		( new LineReader( $this->sdk(), $this->order_status_changed(), $this->design_changed(), new WcLogger() ) )->register();
		( new AttentionAlerts( $this->notices() ) )->register();
		( new ProofReturn( $this->order_edit() ) )->register();
		( new ProofTimers( $this->settings(), new WcLogger() ) )->register();
		( new ProofDeadline( $this->sdk(), $this->active_connection(), $this->settings(), $this->proof_denial(), new WcLogger(), static fn (): int => time() ) )->register();
		$this->order_edit()->register();
		( new OrderEditRoute( $this->order_edit(), $this->labels() ) )->register();
		( new Emails( $this->templates(), $this->labels() ) )->register();
		( new LineDisplay( $this->labels(), $this->settings(), $this->templates() ) )->register();
		( new LinePictures( $this->proof_urls(), new LinePicture( $this->settings() ), $this->templates(), $this->labels() ) )->register();
		( new ConnectionScreen( $this->connection(), $this->sdk(), $this->webhook_endpoint(), new Deprecations(), $this->templates(), ConnectionScreen::settings_url(), $this->workspace_settings(), $this->store_access() ) )->register();
		( new SettingsPage( $this->settings(), $this->file ) )->register();
		( new ConnectNotice( $this->connection(), $this->templates(), ConnectionScreen::settings_url() ) )->register();
		( new DeveloperTools( $this->settings(), $this->connection(), $this->webhook_endpoint(), $this->events_pull(), $this->product_recheck(), $this->catalogue_cache(), $this->designer_links(), $this->request_log(), $this->templates(), $this->file ) )->register();
		( new UpgradeNotice( plugin_basename( $this->file ), self::VERSION, $this->templates() ) )->register();
		( new PluginLinks( plugin_basename( $this->file ), ConnectionScreen::settings_url() ) )->register();
		$designs = new Designs( $this->sdk() );
		$this->add_to_cart()->register();
		$this->order_again()->register();
		( new CheckoutCheck( $this->connection(), $this->sdk(), $this->labels(), new WcLogger() ) )->register();
		( new OrderLines( $this->connection(), $this->sdk(), new WcLogger() ) )->register();
		$this->cart_cleanup()->register();
		( new DesignBasis() )->register();
		$this->proof_cache()->register();
		( new ProofRoute( $this->proof_urls(), $this->proof_cache(), $designs, new WcLogger() ) )->register();
		( new CartProofs( $this->proof_urls(), $this->templates(), $this->labels(), $this->settings(), new LinePicture( $this->settings() ) ) )->register();
		( new ErasedFieldValues() )->register();
		$this->cart_edit()->register();
		( new CartEditRoute( $this->cart_edit(), $this->labels() ) )->register();
		$this->hosted_correction()->register();
		( new Purchasability() )->register();
		$this->product_recheck()->register();
		( new VariantWatch( $this->product_recheck() ) )->register();
		( new TrashedProducts( $this->notices(), $this->product_recheck() ) )->register();
		$this->product_pictures()->register();
		$this->picture_queue()->register();
		// The runners' admin-ajax action is registered once Action Scheduler, which ships the request class, is loaded.
		add_action(
			'action_scheduler_init',
			function (): void {
				$this->picture_runner();
			}
		);
		$this->catalogue_search()->register();
		$this->regenerate_pictures()->register();
		$this->regenerate_now()->register();
		if ( is_admin() ) {
			$product_panel = $this->product_panel();
			$product_panel->register();
			( new LayoutAdjustments( $this->sdk(), new StandingRows( $this->catalogue(), $designs, $this->sdk(), new WcLogger() ), new WcLogger() ) )->register();
			( new VariationPanel( $product_panel, $this->templates(), $this->variant_field() ) )->register();
			( new PictureFields( $this->templates(), $this->settings() ) )->register();
			$this->showcase_field()->register();
			( new StatusReport( $this->templates() ) )->register();
			( new OrderBox( $this->line_actions(), $this->proof_urls(), $this->templates() ) )->register();
		}
		$this->storefront()->register();
		$this->opening_price()->register();
		( new PrintedSwatches( $this->inline_products(), $this->settings() ) )->register();
		$this->personalise_button()->register();
		$this->personalised_designs()->register();
		( new MovedAttributeLinks() )->register();
		$this->designer_links()->register();
		$this->designer_link_withdrawals()->register();
		( new UnavailableReports( $this->inline_products(), $this->catalogue(), $this->notices(), new WcLogger() ) )->register();
		$this->abilities()->register();
	}

	/**
	 * The plugin's abilities on the WordPress Abilities API.
	 */
	public function abilities(): Abilities {
		return new Abilities(
			function (): array {
				$panel   = $this->product_panel();
				$setup   = new ProductSetup( $this->settings() );
				$links   = $this->product_links();
				$fields  = new SetupFields( fn (): array => $this->catalogue()->live_versions() );
				$designs = new Designs( $this->sdk() );
				$lines   = new LineView( $this->proof_urls() );
				$connect = $this->sdk();
				$status  = new ConnectionStatus( $this->connection(), $connect, $this->webhook_endpoint() );
				return array(
					new ListTemplates( $this->catalogue() ),
					new ListProducts( $setup ),
					new LinkProduct( $links ),
					new UnlinkProduct( $links ),
					new SetProductSetup( $links, $fields ),
					new SetProductShowcase( $links, $this->showcase_field() ),
					new MoveProductTemplate( $links, $fields ),
					new MoveProductAttribute( new WcLogger() ),
					new UseProductPicture( $links, $this->settings() ),
					new ResetStandingLayout( $links, new LayoutAdjustments( $this->sdk(), new StandingRows( $this->catalogue(), $designs, $this->sdk(), new WcLogger() ), new WcLogger() ) ),
					new PersonalisedOrders( $lines, $this->line_actions() ),
					new ActOnOrderLine( $this->line_actions(), $lines ),
					new ChangeDeliveryAddress( $this->delivery_change(), $lines ),
					new RegenerateProductPictures( $this->regenerate_pictures() ),
					new RecheckProducts( $this->connection(), $this->catalogue_cache(), $this->product_recheck() ),
					new ReadPluginSettings( $this->settings() ),
					new UpdatePluginSettings( $this->settings() ),
					$status,
					...array_map(
						fn ( string $action ): ConnectionAction => new ConnectionAction( $action, $this->connection(), $connect, $this->webhook_endpoint(), $this->workspace_settings(), $status ),
						ConnectionAction::ACTIONS
					),
					...StoreResources::abilities(),
				);
			}
		);
	}

	private function product_links(): ProductLinks {
		$panel = $this->product_panel();
		return new ProductLinks( $panel, $this->variant_field(), $this->notices(), new ProductSetup( $this->settings() ), new VariationPanel( $panel, $this->templates(), $this->variant_field() ) );
	}

	public function store_access(): StoreAccess {
		$this->store_access ??= new StoreAccess( $this->connection_store(), $this->pixonaut(), new WcLogger() );
		return $this->store_access;
	}

	public function settings(): Settings {
		$this->settings ??= new Settings();
		return $this->settings;
	}

	public function labels(): Labels {
		$this->labels ??= new Labels();
		return $this->labels;
	}

	public function templates(): Templates {
		$this->templates ??= Templates::for_plugin( $this->file );
		return $this->templates;
	}

	public function notices(): Notices {
		$this->notices ??= new Notices( $this->templates() );
		return $this->notices;
	}

	/**
	 * The stored connection, which gives the client its credentials.
	 */
	public function connection_store(): ConnectionStore {
		$this->connection_store ??= new ConnectionStore( Cipher::for_site() );
		return $this->connection_store;
	}

	/**
	 * What `pixonaut_wc_abilities()` answers.
	 */
	public function bridge_discovery(): Discovery {
		return new Discovery();
	}

	/**
	 * What `pixonaut_wc_status()` answers.
	 */
	public function bridge_status(): Status {
		return new Status( $this->connection() );
	}

	/**
	 * Builds the SDK, one client per timeout.
	 */
	public function pixonaut(): Pixonaut {
		$this->pixonaut ??= new Pixonaut( new Credentials( $this->connection_store() ), new WcLogger(), new HookListener( new Deprecations() ) );
		return $this->pixonaut;
	}

	/**
	 * The SDK, with the usual timeout.
	 */
	public function sdk(): Sdk {
		return $this->pixonaut()->sdk();
	}

	/**
	 * The workspace settings Pixonaut shows the store.
	 */
	public function workspace_settings(): WorkspaceSettings {
		$this->workspace_settings ??= new WorkspaceSettings( $this->connection(), $this->sdk(), new WcLogger() );
		return $this->workspace_settings;
	}

	public function connection(): Connection {
		$this->connection ??= new Connection(
			$this->connection_store(),
			new ConnectAttempts(),
			$this->sdk(),
			$this->notices(),
			new WcLogger(),
			ConnectionScreen::settings_url(),
			null,
			$this->store_access()
		);
		return $this->connection;
	}

	/**
	 * The store's webhook endpoint in Pixonaut.
	 */
	public function webhook_endpoint(): Endpoint {
		$this->webhook_endpoint ??= new Endpoint( $this->connection(), $this->sdk(), new WcLogger() );
		return $this->webhook_endpoint;
	}

	/**
	 * Takes in webhook events, delivered or pulled.
	 */
	public function intake(): Intake {
		$this->intake ??= new Intake( new EventStore(), $this->webhook_processor(), new WcLogger() );
		return $this->intake;
	}

	/**
	 * Pulls the event stream, for events a webhook did not deliver.
	 */
	public function events_pull(): EventsPull {
		$this->events_pull ??= new EventsPull( $this->connection_store(), $this->sdk(), $this->intake(), new WcLogger() );
		return $this->events_pull;
	}

	/**
	 * The developer tools' log of requests to Pixonaut.
	 */
	public function request_log(): RequestLog {
		$this->request_log ??= new RequestLog( $this->settings() );
		return $this->request_log;
	}

	public function updates(): Updates {
		$this->updates ??= Updates::for_plugin( $this->settings(), $this->file );
		return $this->updates;
	}

	public function catalogue(): Catalogue {
		$this->catalogue ??= new Catalogue( $this->sdk() );
		return $this->catalogue;
	}

	public function catalogue_cache(): CatalogueCache {
		$this->catalogue_cache ??= new CatalogueCache( $this->connection_store() );
		return $this->catalogue_cache;
	}

	/**
	 * The admin's catalogue search routes.
	 */
	public function catalogue_search(): CatalogueSearch {
		$this->catalogue_search ??= new CatalogueSearch( $this->catalogue(), $this->catalogue_cache() );
		return $this->catalogue_search;
	}

	/**
	 * Re-checks products when their templates or blanks change.
	 */
	public function product_recheck(): ProductRecheck {
		$this->product_recheck ??= new ProductRecheck( new ProductLinker( $this->catalogue(), new Designs( $this->sdk() ), $this->sdk(), new WcLogger() ), $this->catalogue(), new InvalidProductNotice( $this->notices() ), new WcLogger(), $this->product_variants(), $this->designer_links() );
		return $this->product_recheck;
	}

	/**
	 * Each variation's blank variant.
	 */
	public function product_variants(): ProductVariants {
		$this->product_variants ??= new ProductVariants( $this->catalogue(), new VariantNotice( $this->notices() ) );
		return $this->product_variants;
	}

	/**
	 * The product screen's blank variant field.
	 */
	public function variant_field(): VariantField {
		return new VariantField( $this->product_variants(), $this->product_recheck(), $this->templates(), new WcLogger() );
	}

	public function product_panel(): ProductPanel {
		return new ProductPanel( new ProductLinker( $this->catalogue(), new Designs( $this->sdk() ), $this->sdk(), new WcLogger() ), new LinkedBlank( $this->catalogue(), $this->catalogue_cache() ), $this->notices(), $this->templates(), $this->settings(), $this->file, $this->variant_field(), $this->showcase_field(), $this->regenerate_pictures(), $this->proof_urls(), $this->connection() );
	}

	/**
	 * Draws the products' Pixonaut pictures.
	 */
	public function product_pictures(): ProductPictures {
		$this->product_pictures ??= new ProductPictures( new Pictures( $this->pixonaut() ), $this->catalogue(), $this->catalogue_cache(), new WcLogger(), $this->settings() );
		return $this->product_pictures;
	}

	/**
	 * Regenerate now's routes and dialog.
	 */
	public function regenerate_now(): RegenerateNow {
		return new RegenerateNow( $this->templates(), $this->settings(), $this->regenerate_pictures(), $this->picture_queue(), $this->product_pictures(), $this->file );
	}

	public function picture_queue(): PictureQueue {
		$this->picture_queue ??= new PictureQueue( $this->settings() );
		return $this->picture_queue;
	}

	/**
	 * The runners that draw pictures at once; null until Action Scheduler is loaded.
	 */
	public function picture_runner(): ?PictureRunner {
		if ( null === $this->picture_runner && did_action( 'action_scheduler_init' ) ) {
			$this->picture_runner = new PictureRunner();
		}
		return $this->picture_runner;
	}

	/**
	 * The product screen's Regenerate in background button, the products list's bulk and row actions, and the button's route.
	 */
	public function regenerate_pictures(): RegeneratePictures {
		return new RegeneratePictures( $this->templates(), $this->settings(), $this->picture_queue(), $this->picture_runner( ... ) );
	}

	/**
	 * The product screen's product images field.
	 */
	public function showcase_field(): ShowcaseField {
		return new ShowcaseField( $this->catalogue(), $this->catalogue_cache(), $this->templates(), $this->settings(), new LinkedBlank( $this->catalogue(), $this->catalogue_cache() ) );
	}

	/**
	 * Handles webhook events, each type by its handler.
	 */
	public function webhook_processor(): Processor {
		$failed                    = new OrderFailed( $this->order_event_lines() );
		$cancelled                 = new OrderCancelled( $this->order_event_lines() );
		$tracking_changed          = new OrderTrackingChanged( $this->order_event_lines() );
		$this->webhook_processor ??= new Processor(
			new EventStore(),
			array(
				OrderStatusChanged::TYPE      => array( $this->order_status_changed(), 'handle' ),
				OrderFailed::TYPE             => array( $failed, 'handle' ),
				OrderHeld::TYPE               => array( new OrderHeld( $this->order_event_lines() ), 'handle' ),
				OrderReleased::TYPE           => array( new OrderReleased( $this->order_event_lines(), $this->order_status_changed(), $failed, $cancelled ), 'handle' ),
				OrderCancelled::TYPE          => array( $cancelled, 'handle' ),
				OrderSubmitted::TYPE          => array( new OrderSubmitted( $this->order_event_lines(), $this->order_status_changed(), $this->line_actions() ), 'handle' ),
				OrderDeliveryChanged::TYPE    => array( new OrderDeliveryChanged( $this->order_event_lines() ), 'handle' ),
				OrderTrackingChanged::ADDED   => array( $tracking_changed, 'handle' ),
				OrderTrackingChanged::CHANGED => array( $tracking_changed, 'handle' ),
				DesignChanged::EDITED         => array( $this->design_changed(), 'handle' ),
				DesignChanged::DELETED        => array( $this->design_changed(), 'handle' ),
				DesignChanged::ERASED         => array( $this->design_changed(), 'handle' ),
				DesignApproved::TYPE          => array( new DesignApproved( $this->connection(), $this->sdk(), new WcLogger() ), 'handle' ),
				ChangesRequested::TYPE        => array( new ChangesRequested( $this->connection(), $this->proof_state() ), 'handle' ),
				DesignRejected::TYPE          => array( new DesignRejected( $this->connection(), $this->sdk(), $this->proof_denial(), new WcLogger() ), 'handle' ),
			) + $this->product_recheck()->handlers()
				+ ( new DesignerLinkChanges( $this->designer_links(), new DesignerLinks( $this->sdk(), new WcLogger() ), new WcLogger() ) )->handlers()
				+ $this->store_access()->handlers(),
			new WcLogger(),
			null,
			// A copied site's events wait: they are about the orders of the site it was copied from.
			fn (): bool => Connection::PAUSED_SITE_CHANGED === $this->connection()->paused()
		);
		return $this->webhook_processor;
	}

	private function order_event_lines(): OrderEventLines {
		return new OrderEventLines( $this->connection(), new WcLogger() );
	}

	public function order_status_changed(): OrderStatusChanged {
		$this->order_status_changed ??= new OrderStatusChanged( $this->order_event_lines(), new Shipments() );
		return $this->order_status_changed;
	}

	public function design_changed(): DesignChanged {
		$jobs                   = $this->fulfilment_jobs();
		$this->design_changed ??= new DesignChanged( $this->connection(), $this->sdk(), $jobs->schedule_again( ... ), new WcLogger() );
		return $this->design_changed;
	}

	public function fulfilment_jobs(): FulfilmentJobs {
		$this->fulfilment_jobs ??= new FulfilmentJobs( $this->fulfiller(), $this->active_connection(), new WcLogger() );
		return $this->fulfilment_jobs;
	}

	/**
	 * Cancels lines whose order was cancelled or refunded.
	 */
	public function line_cancellation(): LineCancellation {
		return new LineCancellation( $this->sdk(), $this->active_connection(), new WcLogger() );
	}

	/**
	 * Erases a line's personal data in Pixonaut.
	 */
	public function line_erasure(): LineErasure {
		return new LineErasure( new Designs( $this->sdk() ), $this->sdk(), $this->active_connection(), new WcLogger() );
	}

	/**
	 * What becomes of a proof-by-email line whose proof was denied.
	 */
	public function proof_denial(): ProofDenial {
		return new ProofDenial( $this->settings(), $this->line_cancellation(), new LineRefund() );
	}

	/**
	 * Sends a changed shipping address to each line's printshop order.
	 */
	public function delivery_change(): DeliveryChange {
		$this->delivery_change ??= new DeliveryChange( $this->sdk(), $this->active_connection(), new WcLogger() );
		return $this->delivery_change;
	}

	/**
	 * The actions the order screen offers for a line.
	 */
	public function line_actions(): LineActions {
		return new LineActions(
			$this->sdk(),
			$this->active_connection(),
			$this->order_status_changed(),
			new OrderFailed( $this->order_event_lines() ),
			FulfilmentJobs::schedule( ... ),
			FulfilmentJobs::is_scheduled( ... ),
			new WcLogger()
		);
	}

	public function fulfiller(): Fulfiller {
		return new Fulfiller(
			$this->sdk(),
			$this->active_connection(),
			$this->proof_request()
		);
	}

	/**
	 * `fn(): ?StoredConnection`: the connection to act for; null while there is none or it is paused.
	 */
	private function active_connection(): Closure {
		$store = $this->connection_store();
		return static fn (): ?StoredConnection => null === $store->paused() ? $store->load() : null;
	}

	/**
	 * Asks the customer to approve a proof, for a line whose approval mode is proof by email.
	 */
	public function proof_request(): ProofRequest {
		$settings = $this->settings();
		return new ProofRequest(
			$this->sdk(),
			$this->proof_state(),
			static fn (): int => (int) $settings->get( Settings::PROOF_DEADLINE_DAYS ),
			static fn ( string $locale ): ?string => DesignerLanguage::resolve( $settings, $locale )
		);
	}

	/**
	 * Signs and verifies the `state` proof pages carry back, with a key
	 * derived from the site's salts.
	 */
	public function proof_state(): ProofState {
		return new ProofState( wp_salt( 'auth' ) );
	}

	public function inline_products(): InlineProducts {
		$this->inline_products ??= new InlineProducts( $this->settings(), $this->connection(), $this->cart_edit(), $this->order_edit() );
		return $this->inline_products;
	}

	/**
	 * Edits a cart line's design on its product page.
	 */
	public function cart_edit(): CartEdit {
		$this->cart_edit ??= new CartEdit( $this->connection(), $this->sdk(), $this->settings(), $this->labels(), new WcLogger() );
		return $this->cart_edit;
	}

	/**
	 * Edits an order line's design after the customer asked for changes to its proof.
	 */
	public function order_edit(): OrderEdit {
		$this->order_edit ??= new OrderEdit(
			$this->proof_state(),
			$this->sdk(),
			new CorrectionMount( $this->connection(), $this->sdk(), new WcLogger(), $this->settings() ),
			$this->settings(),
			$this->labels(),
			new WcLogger(),
			FulfilmentJobs::schedule( ... )
		);
		return $this->order_edit;
	}

	/**
	 * Corrects a Personalise-button product's design on Pixonaut's hosted page.
	 */
	public function hosted_correction(): HostedCorrection {
		return new HostedCorrection(
			$this->cart_edit(),
			$this->order_edit(),
			new CorrectionMount( $this->connection(), $this->sdk(), new WcLogger(), $this->settings() ),
			new CorrectionTrips(),
			$this->labels(),
			$this->personalised_designs()
		);
	}

	public function opening_price(): OpeningPrice {
		$this->opening_price ??= new OpeningPrice( $this->inline_products(), new ButtonProducts( $this->settings() ) );
		return $this->opening_price;
	}

	public function server_reads(): ServerReads {
		$this->server_reads ??= new ServerReads( $this->catalogue(), $this->catalogue_cache(), $this->sdk() );
		return $this->server_reads;
	}

	public function storefront(): Storefront {
		$this->storefront ??= new Storefront( $this->inline_products(), $this->templates(), $this->labels(), $this->settings(), $this->file, $this->server_reads(), $this->opening_price() );
		return $this->storefront;
	}

	/**
	 * Mints the signed URLs of proof images, for every screen that shows one.
	 */
	public function proof_urls(): ProofUrls {
		$this->proof_urls ??= new ProofUrls( wp_salt( ProofUrls::SALT_SCHEME ) );
		return $this->proof_urls;
	}

	public function proof_cache(): ProofCache {
		$this->proof_cache ??= new ProofCache();
		return $this->proof_cache;
	}

	/**
	 * The guard on every add of a personalised product to the cart.
	 */
	public function add_to_cart(): AddToCart {
		$this->add_to_cart ??= new AddToCart( $this->connection(), new Designs( $this->sdk() ), $this->sdk(), $this->settings(), $this->labels(), $this->notices(), new WcLogger(), $this->order_again(), null, new CommitFailures( $this->notices(), new WcLogger() ), $this->personalised_designs() );
		return $this->add_to_cart;
	}

	/**
	 * Deletes the designs of cart lines that will never be ordered.
	 */
	public function cart_cleanup(): CartCleanup {
		$this->cart_cleanup ??= new CartCleanup( $this->connection(), new Designs( $this->sdk() ), new WcLogger() );
		return $this->cart_cleanup;
	}

	/**
	 * Copies an order line's designs when the customer orders it again.
	 */
	public function order_again(): OrderAgain {
		$this->order_again ??= new OrderAgain( $this->sdk(), $this->cart_cleanup(), new WcLogger() );
		return $this->order_again;
	}

	public function personalise_button(): PersonaliseButton {
		$this->personalise_button ??= new PersonaliseButton( new ButtonProducts( $this->settings() ), $this->connection(), $this->designer_links(), new DesignerVisits(), $this->add_to_cart(), $this->storefront(), $this->templates(), $this->labels(), new WcLogger(), $this->opening_price(), $this->personalised_designs(), $this->proof_urls(), new LinePicture( $this->settings() ), new AddAfterApprove( $this->settings() ), $this->server_reads() );
		return $this->personalise_button;
	}

	/**
	 * The customer's designs waiting on the Personalise button's product pages.
	 */
	public function personalised_designs(): PersonalisedDesigns {
		$this->personalised_designs ??= new PersonalisedDesigns( $this->cart_cleanup() );
		return $this->personalised_designs;
	}

	/**
	 * Each product's kept designer link.
	 */
	public function designer_links(): KeptDesignerLinks {
		$this->designer_links ??= new KeptDesignerLinks( new DesignerLinks( $this->sdk(), new WcLogger() ), $this->designer_link_withdrawals(), $this->connection(), $this->settings(), $this->notices(), new WcLogger() );
		return $this->designer_links;
	}

	/**
	 * The designer links waiting to be withdrawn.
	 */
	public function designer_link_withdrawals(): DesignerLinkWithdrawals {
		$this->designer_link_withdrawals ??= new DesignerLinkWithdrawals( new DesignerLinks( $this->sdk(), new WcLogger() ), $this->connection(), new WcLogger() );
		return $this->designer_link_withdrawals;
	}

	public function file(): string {
		return $this->file;
	}
}
