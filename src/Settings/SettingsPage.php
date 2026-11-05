<?php
/**
 * The Pixonaut settings page, a top-level item in the admin menu.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Settings;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\DeveloperTools;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Updates\UpdateChannel;
use WC_Admin_Settings;

/**
 * Shows every registered setting on a tab of its own page, grouped by what
 * it decides, each with its description under it. A setting that only
 * applies while another has some value is shown, indented, only then, and is
 * marked required when it must be filled in then. The fields are
 * WooCommerce settings fields: WooCommerce renders them and saves each into
 * the settings option through its `option[key]` id, as it did while the
 * settings were a tab under WooCommerce > Settings, which now sends its
 * visitors here.
 */
final class SettingsPage {

	/**
	 * The page's `page` query argument.
	 */
	public const PAGE = 'pixonaut';

	/**
	 * The query argument naming the tab shown.
	 */
	public const TAB_ARG = 'tab';

	public const CONNECTION = 'connection';

	public const STOREFRONT = 'storefront';

	public const APPROVAL = 'approval';

	public const UPDATES = 'updates';

	/**
	 * The tab the settings had under WooCommerce > Settings, which redirects here.
	 */
	public const WC_TAB = 'pixonaut';

	/**
	 * Who may open the page and save it, as for WooCommerce's settings.
	 */
	public const CAPABILITY = Notices::CAPABILITY;

	/**
	 * The admin screen the page is.
	 */
	public const HOOK_SUFFIX = 'toplevel_page_' . self::PAGE;

	public const SAVE_ACTION = 'pixonaut_wc_save_settings';

	public const SCRIPT_HANDLE = 'pixonaut-wc-admin-settings';

	/**
	 * Where the menu item sits: after WooCommerce's own top-level items
	 * (WooCommerce 55.5, Products, Analytics and Marketing up to 58).
	 */
	private const MENU_POSITION = 58.5;

	/**
	 * The menu item's icon: Pixonaut's helmet in one colour, which WordPress
	 * repaints in the admin colour scheme's menu colours. It does so only for
	 * an SVG given as a base64 data URI.
	 */
	private const MENU_ICON_FILE = 'assets/images/menu-icon.svg';

	private const SVG_DATA_URI_PREFIX = 'data:image/svg+xml;base64,';

	/**
	 * Shown if the icon file cannot be read.
	 */
	private const FALLBACK_MENU_ICON = 'dashicons-admin-generic';

	/**
	 * WooCommerce's settings script: the colour picker, enhanced selects and
	 * the warning before leaving with unsaved changes.
	 */
	private const WC_SETTINGS_SCRIPT = 'woocommerce_settings';

	/**
	 * The section of the general settings no group names.
	 */
	private const OTHERS = 'pixonaut_wc_other';

	/**
	 * A dependent setting's row, with the prefix WooCommerce puts before a
	 * row class that lacks it.
	 */
	private const DEPENDENT_ROW = 'wc-settings-row-pixonaut-dependent';

	/**
	 * The groups each tab with settings shows, in order, by section id: the
	 * keys of the settings in each. General settings no group names follow
	 * on the last tab, in a section of their own without a heading.
	 */
	private const GROUPS = array(
		self::STOREFRONT => array(
			'pixonaut_wc_designer'     => array(
				Settings::DEFAULT_LAYOUT,
				Settings::FORM_LAYOUT,
				Settings::OPTIONS_AS_GROUPS,
				Settings::OPTION_GROUPS_POSITION,
				Settings::GROUP_NUMBERS,
				Settings::DESIGNER_LANGUAGE,
				Settings::DESIGNER_PRELOAD,
				Settings::DESIGNER_ACCENT,
				Settings::DESIGNER_ACCENT_COLOUR,
				Settings::PRINT_AREA_FRAME,
				Settings::ADD_AFTER_APPROVE,
				Settings::ATTRIBUTE_OPTIONS,
			),
			'pixonaut_wc_pictures'     => array(
				Settings::PRODUCT_PICTURES,
			),
			'pixonaut_wc_line_details' => array(
				Settings::LINE_PICTURE_VIEW,
				Settings::SHOW_FIELD_VALUES,
			),
		),
		self::APPROVAL   => array(
			'pixonaut_wc_approval' => array(
				Settings::DEFAULT_APPROVAL_MODE,
			),
			'pixonaut_wc_proof'    => array(
				Settings::PROOF_DEADLINE_DAYS,
				Settings::PROOF_REMINDERS,
				Settings::PROOF_REMINDER_AFTER_DAYS,
				Settings::PROOF_REMINDER_EVERY_DAYS,
				Settings::PROOF_NO_ANSWER,
				Settings::PROOF_ON_DENY,
			),
		),
		self::UPDATES    => array(
			'pixonaut_wc_updates' => array(
				UpdateChannel::SETTING,
			),
		),
	);

	/**
	 * The settings shown only while another one has a value: the setting
	 * they depend on, and the value it must equal (`equals`) or be above
	 * (`above`). A required one must be filled in while it is shown.
	 *
	 * @var array<string, array{on: string, equals?: string, above?: int, required?: bool}>
	 */
	private const DEPENDENT = array(
		Settings::OPTION_GROUPS_POSITION    => array(
			'on'      => Settings::OPTIONS_AS_GROUPS,
			'checked' => true,
		),
		Settings::DESIGNER_ACCENT_COLOUR    => array(
			'on'       => Settings::DESIGNER_ACCENT,
			'equals'   => Accent::CUSTOM,
			'required' => true,
		),
		Settings::PROOF_REMINDER_AFTER_DAYS => array(
			'on'    => Settings::PROOF_REMINDERS,
			'above' => 0,
		),
		Settings::PROOF_REMINDER_EVERY_DAYS => array(
			'on'    => Settings::PROOF_REMINDERS,
			'above' => 0,
		),
	);

	/**
	 * @param Settings $settings The registry whose settings the page shows.
	 * @param string   $file     Main plugin file, for asset URLs.
	 */
	public function __construct( private readonly Settings $settings, private readonly string $file = '' ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_wc_tab' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'woocommerce_screen_ids', array( $this, 'screen_ids' ) );
	}

	/**
	 * A tab of the page.
	 *
	 * @param string $tab One of tabs().
	 */
	public static function url( string $tab = self::CONNECTION ): string {
		return add_query_arg(
			array(
				'page'        => self::PAGE,
				self::TAB_ARG => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The tabs shown, in order: Connection, then each tab that has a
	 * setting. The update channel, and so the Updates tab, exists only in
	 * builds that update from GitHub.
	 *
	 * @return list<string>
	 */
	public function tabs(): array {
		$tabs = array( self::CONNECTION );
		foreach ( array_keys( self::GROUPS ) as $tab ) {
			if ( array() !== array_merge( ...array_values( $this->groups( $tab ) ) ) ) {
				$tabs[] = $tab;
			}
		}
		return $tabs;
	}

	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'Pixonaut settings', 'pixonaut-for-woocommerce' ),
			/* translators: The plugin's item in the admin menu, shown to the merchant; the product name, keep it as it is. */
			__( 'Pixonaut', 'pixonaut-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' ),
			$this->menu_icon(),
			self::MENU_POSITION
		);
		add_action( 'load-' . $hook, array( $this, 'load' ) );
		// Names the menu's first item, which WordPress would show as the menu's own name once another page sits under it.
		add_submenu_page(
			self::PAGE,
			__( 'Pixonaut settings', 'pixonaut-for-woocommerce' ),
			/* translators: Item in the admin menu under Pixonaut, opening the plugin's settings. */
			__( 'Settings', 'pixonaut-for-woocommerce' ),
			self::CAPABILITY,
			self::PAGE
		);
	}

	private function menu_icon(): string {
		$file = dirname( __DIR__, 2 ) . '/' . self::MENU_ICON_FILE;
		$svg  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		if ( false === $svg || '' === $svg ) {
			return self::FALLBACK_MENU_ICON;
		}
		return self::SVG_DATA_URI_PREFIX . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress colours a menu icon only when it is a base64 SVG data URI.
	}

	/**
	 * Sends WooCommerce > Settings > Pixonaut, where the settings were, to
	 * the page, with the query arguments it carried, such as the outcome of
	 * a connection action.
	 */
	public function redirect_wc_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reads where the request is going.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'wc-settings' !== $page || self::WC_TAB !== $tab || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$carried = array_diff_key( map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ), array_flip( array( 'page', 'tab', 'section' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode_deep', $carried ), self::url() ) );
		exit;
	}

	/**
	 * Saves the tab's form before the page is rendered.
	 */
	public function load(): void {
		self::load_wc_settings();
		if ( ! isset( $_POST['save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below.
			return;
		}
		check_admin_referer( self::SAVE_ACTION );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change the Pixonaut settings.', 'pixonaut-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$this->save( $this->current_tab() );
	}

	/**
	 * Saves a tab's settings, then keeps the custom accent colour it had
	 * when Designer accent colour is A custom colour and no valid colour
	 * was entered, and says what is missing.
	 *
	 * @param string $tab One of tabs().
	 */
	public function save( string $tab ): void {
		self::load_wc_settings();
		$before = $this->settings->get( Settings::DESIGNER_ACCENT_COLOUR );
		WC_Admin_Settings::save_fields( $this->fields( $tab ) );
		if ( self::STOREFRONT === $tab && Accent::CUSTOM === $this->settings->get( Settings::DESIGNER_ACCENT ) && '' === $this->settings->get( Settings::DESIGNER_ACCENT_COLOUR ) ) {
			$this->settings->set( Settings::DESIGNER_ACCENT_COLOUR, $before );
			WC_Admin_Settings::add_error( self::missing_colour() );
			return;
		}
		WC_Admin_Settings::add_message( __( 'Your settings have been saved.', 'pixonaut-for-woocommerce' ) );
	}

	public function render(): void {
		$current = $this->current_tab();
		$fields  = $this->fields( $current );
		?>
		<div class="wrap woocommerce pixonaut-settings">
			<h1><?php esc_html_e( 'Pixonaut settings', 'pixonaut-for-woocommerce' ); ?></h1>
			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $tab ) : ?>
					<a href="<?php echo esc_url( self::url( $tab ) ); ?>" class="nav-tab<?php echo $tab === $current ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( self::label( $tab ) ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php WC_Admin_Settings::show_messages(); ?>
			<?php if ( self::CONNECTION === $current ) : ?>
				<?php
				// The card and the developer tools have their own actions, and no form.
				WC_Admin_Settings::output_fields( $fields );
				?>
			<?php else : ?>
				<form method="post" id="mainform" action="<?php echo esc_url( self::url( $current ) ); ?>">
					<?php WC_Admin_Settings::output_fields( $fields ); ?>
					<p class="submit">
						<button name="save" class="button-primary woocommerce-save-button" type="submit" value="1"><?php esc_html_e( 'Save changes', 'pixonaut-for-woocommerce' ); ?></button>
						<?php wp_nonce_field( self::SAVE_ACTION ); ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A tab's fields: the connection card and the developer tools on
	 * Connection, the tab's groups of settings on the others.
	 *
	 * @param string $tab One of tabs().
	 * @return list<array<string, mixed>>
	 */
	public function fields( string $tab ): array {
		if ( self::CONNECTION === $tab ) {
			return array( ConnectionScreen::field(), DeveloperTools::field() );
		}
		$fields = array();
		foreach ( $this->groups( $tab ) as $id => $settings ) {
			$fields[] = array( 'id' => $id ) + self::heading( $id );
			foreach ( $settings as $setting ) {
				$fields[] = $this->field( $setting );
			}
			$fields[] = array(
				'id'   => $id,
				'type' => 'sectionend',
			);
		}
		return $fields;
	}

	/**
	 * @param string $hook_suffix The admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}
		if ( self::CONNECTION !== $this->current_tab() ) {
			// It needs the settings form, which Connection does not have.
			$this->enqueue_wc_settings();
		}
		if ( '' === $this->file ) {
			return;
		}
		$asset_file = dirname( $this->file ) . '/assets/build/admin-settings.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset   = require $asset_file;
		$version = is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION;
		wp_enqueue_style( self::SCRIPT_HANDLE, plugins_url( 'assets/build/admin-settings.css', $this->file ), array(), $version );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/admin-settings.js', $this->file ),
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			$version,
			true
		);
	}

	/**
	 * Makes the page one of WooCommerce's screens, so it loads WooCommerce's
	 * admin styles and scripts the settings fields need.
	 *
	 * @param array<int, string> $ids WooCommerce's screen ids.
	 * @return array<int, string>
	 */
	public function screen_ids( array $ids ): array {
		$ids[] = self::HOOK_SUFFIX;
		return $ids;
	}

	/**
	 * The tab asked for; Connection when none or an unknown one is.
	 */
	public function current_tab(): string {
		$tab = isset( $_GET[ self::TAB_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::TAB_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab is shown.
		return in_array( $tab, $this->tabs(), true ) ? $tab : self::CONNECTION;
	}

	/**
	 * The script WooCommerce's own settings screens load, as they load it.
	 */
	private function enqueue_wc_settings(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script( self::WC_SETTINGS_SCRIPT, WC()->plugin_url() . '/assets/js/admin/settings' . $suffix . '.js', array( 'jquery', 'wp-util', 'jquery-ui-datepicker', 'jquery-ui-sortable', 'iris', 'selectWoo' ), WC()->version, true );
		wp_localize_script(
			self::WC_SETTINGS_SCRIPT,
			'woocommerce_settings_params',
			array(
				'i18n_nav_warning' => __( 'The changes you made will be lost if you navigate away from this page.', 'pixonaut-for-woocommerce' ),
			)
		);
	}

	/**
	 * WooCommerce loads its settings renderer on its own settings screens only.
	 */
	private static function load_wc_settings(): void {
		if ( ! class_exists( WC_Admin_Settings::class, false ) && function_exists( 'WC' ) ) {
			include_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
		}
	}

	/**
	 * A tab's general settings by group, in the groups' order, leaving out
	 * a group with none; the Updates tab also has any a group does not name.
	 *
	 * @param string $tab A key of GROUPS.
	 * @return array<string, list<Setting>>
	 */
	private function groups( string $tab ): array {
		$general = $this->settings->section( Setting::GENERAL );
		$groups  = array();
		foreach ( self::GROUPS[ $tab ] ?? array() as $id => $keys ) {
			$group = array_values( array_intersect_key( $general, array_flip( $keys ) ) );
			// In the group's order, not the registry's.
			usort( $group, static fn ( Setting $a, Setting $b ): int => array_search( $a->key, $keys, true ) <=> array_search( $b->key, $keys, true ) );
			if ( array() !== $group ) {
				$groups[ $id ] = $group;
			}
		}
		if ( self::UPDATES !== $tab ) {
			return $groups;
		}
		$grouped = array_merge( ...array_values( array_map( static fn ( array $tab_groups ): array => array_merge( ...array_values( $tab_groups ) ), self::GROUPS ) ) );
		$others  = array_values( array_diff_key( $general, array_flip( $grouped ) ) );
		if ( array() !== $others ) {
			$groups[ self::OTHERS ] = $others;
		}
		return $groups;
	}

	/**
	 * A setting's field: its description shown under it, and, for one that
	 * depends on another, what it depends on and whether it is shown now.
	 *
	 * @param Setting $setting The setting.
	 * @return array<string, mixed>
	 */
	private function field( Setting $setting ): array {
		$field = array(
			'id'       => self::field_id( $setting->key ),
			'default'  => $setting->stored( $setting->fallback ),
			'desc_tip' => false,
		) + $setting->field();
		$rule  = self::DEPENDENT[ $setting->key ] ?? null;
		if ( null === $rule ) {
			return $field;
		}
		$attributes = array( 'data-pixonaut-depends' => self::field_id( $rule['on'] ) );
		if ( isset( $rule['checked'] ) ) {
			$attributes['data-pixonaut-when-checked'] = 'true';
		}
		if ( isset( $rule['equals'] ) ) {
			$attributes['data-pixonaut-when-equals'] = $rule['equals'];
		}
		if ( isset( $rule['above'] ) ) {
			$attributes['data-pixonaut-when-above'] = (string) $rule['above'];
		}
		if ( $rule['required'] ?? false ) {
			$attributes['data-pixonaut-required'] = self::missing_colour();
		}
		$field['custom_attributes'] = $attributes + ( is_array( $field['custom_attributes'] ?? null ) ? $field['custom_attributes'] : array() );
		$field['row_class']         = self::DEPENDENT_ROW . ( $this->applies( $rule ) ? '' : ' pixonaut-hidden' );
		return $field;
	}

	/**
	 * Whether a dependent setting applies with the settings as stored.
	 *
	 * @param array{on: string, checked?: bool, equals?: string, above?: int, required?: bool} $rule What it depends on.
	 */
	private function applies( array $rule ): bool {
		$value = $this->settings->get( $rule['on'] );
		if ( isset( $rule['checked'] ) ) {
			return true === $value;
		}
		if ( isset( $rule['equals'] ) ) {
			return $rule['equals'] === $value;
		}
		return isset( $rule['above'] ) && is_int( $value ) && $value > $rule['above'];
	}

	/**
	 * A tab's name.
	 *
	 * @param string $tab One of tabs().
	 */
	private static function label( string $tab ): string {
		switch ( $tab ) {
			case self::STOREFRONT:
				/* translators: Tab of the Pixonaut settings page, shown to the merchant: the settings for the designer on the store's product pages. */
				return __( 'Storefront', 'pixonaut-for-woocommerce' );
			case self::APPROVAL:
				/* translators: Tab of the Pixonaut settings page, shown to the merchant: how customers approve their designs, and the proof emails. */
				return _x( 'Approval', 'settings tab', 'pixonaut-for-woocommerce' );
			case self::UPDATES:
				/* translators: Tab of the Pixonaut settings page, shown to the merchant: which releases of the plugin WordPress offers. */
				return _x( 'Updates', 'settings tab', 'pixonaut-for-woocommerce' );
			default:
				/* translators: Tab of the Pixonaut settings page, shown to the merchant: the store's connection to its Pixonaut workspace. */
				return __( 'Connection', 'pixonaut-for-woocommerce' );
		}
	}

	/**
	 * A group's heading.
	 *
	 * @param string $id The group's section id.
	 * @return array{type: string, title: string, desc: string}
	 */
	private static function heading( string $id ): array {
		switch ( $id ) {
			case 'pixonaut_wc_designer':
				return array(
					'type'  => 'title',
					/* translators: Heading of a group of settings on the Pixonaut settings page, shown to the merchant: how the designer on product pages looks and behaves. */
					'title' => __( 'Product page designer', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'How the designer looks and behaves on product pages. A product’s Pixonaut tab can change the layouts for that product.', 'pixonaut-for-woocommerce' ),
				);
			case 'pixonaut_wc_pictures':
				return array(
					'type'  => 'title',
					/* translators: Heading of a group of settings on the Pixonaut settings page, shown to the merchant: the images of personalised products. */
					'title' => __( 'Product images', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'The image the shop, feeds, emails and search results show for a personalised product.', 'pixonaut-for-woocommerce' ),
				);
			case 'pixonaut_wc_line_details':
				return array(
					'type'  => 'title',
					/* translators: Heading of a group of settings on the Pixonaut settings page, shown to the merchant: how a personalised item shows in the cart, the checkout, orders and emails. */
					'title' => __( 'Cart and orders', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'How a personalised item shows in the cart, the checkout, the customer’s orders and the order emails.', 'pixonaut-for-woocommerce' ),
				);
			case 'pixonaut_wc_approval':
				return array(
					'type'  => 'title',
					/* translators: Heading of a group of settings on the Pixonaut settings page, shown to the merchant: how customers approve their design before it is printed. */
					'title' => __( 'Approval', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'How customers approve their design before it is printed. A product’s Pixonaut tab can choose another way for that product.', 'pixonaut-for-woocommerce' ),
				);
			case 'pixonaut_wc_proof':
				return array(
					'type'  => 'title',
					/* translators: Heading of a group of settings on the Pixonaut settings page, shown to the merchant: the approval mode where customers approve a proof sent by email. */
					'title' => __( 'Proof by email', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'For every product whose approval is Proof by email: how long customers have to answer, the reminders they get, and what happens when they do not approve.', 'pixonaut-for-woocommerce' ),
				);
			case 'pixonaut_wc_updates':
				return array(
					'type'  => 'title',
					/* translators: Heading of a group of settings on the Pixonaut settings page, shown to the merchant: which releases of this plugin WordPress offers. */
					'title' => __( 'Plugin updates', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Which releases of this plugin WordPress offers you on the Plugins and Updates screens.', 'pixonaut-for-woocommerce' ),
				);
			default:
				return array(
					'type'  => 'title',
					'title' => '',
					'desc'  => '',
				);
		}
	}

	/**
	 * The field id WooCommerce saves a setting under.
	 *
	 * @param string $key The setting's key.
	 */
	private static function field_id( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	private static function missing_colour(): string {
		return __( 'Designer accent colour is set to A custom colour: enter the colour, e.g. #1e73be.', 'pixonaut-for-woocommerce' );
	}
}
