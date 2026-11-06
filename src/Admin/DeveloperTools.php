<?php
/**
 * The hidden developer tools on the Pixonaut settings page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use ActionScheduler_Abstract_Schedule;
use ActionScheduler_Action;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\RequestLog;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ApiVersion;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use Pixonaut\WooCommerce\Webhooks\EventsPull;
use WP_Admin_Bar;

/**
 * For testing the plugin against a local or staging Pixonaut and debugging a
 * store. The section is rendered on every visit to the settings page's
 * Connection tab and only shown once unlocked in the browser; unlocking is
 * not a security boundary.
 * Every setting and action is saved and checked here, for users who may
 * manage WooCommerce, through `admin-post.php`.
 */
final class DeveloperTools {

	/**
	 * The settings field type the section renders as.
	 */
	public const FIELD_TYPE = 'pixonaut_developer_tools';

	/**
	 * The section's element id, which the tab's links jump to.
	 */
	public const ANCHOR = 'pixonaut-developer-tools';

	/**
	 * The form the section's fields submit, printed outside WooCommerce's
	 * settings form, which a form cannot be nested in.
	 */
	public const FORM_ID = 'pixonaut-developer-tools-form';

	public const SAVE_ACTION = 'pixonaut_wc_developer_save';

	public const PULL_ACTION = 'pixonaut_wc_developer_pull';

	public const PING_ACTION = 'pixonaut_wc_developer_ping';

	public const RECHECK_ACTION = 'pixonaut_wc_developer_recheck';

	public const CLEAR_ACTION = 'pixonaut_wc_developer_clear';

	/**
	 * The posted settings, by key.
	 */
	public const FIELDS_ARG = 'pixonaut_developer';

	/**
	 * The posted setting to reset instead of saving the form.
	 */
	public const RESET_ARG = 'pixonaut_developer_reset';

	/**
	 * Query argument carrying the outcome back to the section.
	 */
	public const RESULT_ARG = 'pixonaut_developer_result';

	public const SAVED = 'saved';

	public const INVALID_URL = 'invalid_url';

	public const PULLED = 'pulled';

	/**
	 * The pull did not read the stream: Pixonaut refused or was not
	 * reached, or the first cursor is not set yet.
	 */
	public const PULL_FAILED = 'pull_failed';

	public const PINGED = 'pinged';

	public const RECHECK_QUEUED = 'recheck_queued';

	public const RECHECK_FAILED = 'recheck_failed';

	public const CLEARED = 'cleared';

	/**
	 * Not connected, or paused: the action needs a working connection.
	 */
	public const NOT_CONNECTED = 'not_connected';

	public const SCRIPT_HANDLE = 'pixonaut-wc-admin-developer-tools';

	/**
	 * Who may change where the store sends its key: an administrator.
	 */
	public const ADDRESS_CAPABILITY = 'manage_options';

	/**
	 * Who may also load a custom SDK script, which runs for every visitor:
	 * a user who may add scripts to the site.
	 */
	public const SCRIPT_CAPABILITY = 'unfiltered_html';

	/**
	 * The admin bar node shown while Pixonaut is somewhere else.
	 */
	public const ADMIN_BAR_NODE = 'pixonaut-custom-host';

	/**
	 * Jobs listed per status.
	 */
	public const MAX_JOBS = 50;

	/**
	 * The Action Scheduler statuses listed: jobs still to run, and jobs that failed.
	 */
	public const JOB_STATUSES = array( 'pending', 'failed' );

	/**
	 * Characters of the integration key shown: its type and environment and a
	 * few of its own, enough to tell keys apart without revealing one.
	 */
	public const KEY_PREFIX_LENGTH = 12;

	private bool $rendered = false;

	/**
	 * @param Settings          $settings        The settings, the developer tools' among them.
	 * @param Connection        $connection      The connection.
	 * @param Endpoint          $endpoint        The store's webhook endpoint.
	 * @param EventsPull        $events          Pulls the event stream.
	 * @param ProductRecheck    $recheck         Re-checks linked products.
	 * @param CatalogueCache    $catalogue_cache The admin's cached catalogue answers.
	 * @param KeptDesignerLinks $designer_links  The products' stored designer links.
	 * @param RequestLog        $request_log     The request log.
	 * @param Templates         $templates       Renders the section.
	 * @param string            $file            Main plugin file, for asset URLs.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Connection $connection,
		private readonly Endpoint $endpoint,
		private readonly EventsPull $events,
		private readonly ProductRecheck $recheck,
		private readonly CatalogueCache $catalogue_cache,
		private readonly KeptDesignerLinks $designer_links,
		private readonly RequestLog $request_log,
		private readonly Templates $templates,
		private readonly string $file,
	) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::PULL_ACTION, array( $this, 'handle_pull' ) );
		add_action( 'admin_post_' . self::PING_ACTION, array( $this, 'handle_ping' ) );
		add_action( 'admin_post_' . self::RECHECK_ACTION, array( $this, 'handle_recheck' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'handle_clear' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
		add_action( 'admin_footer', array( $this, 'print_form' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
	}

	/**
	 * The section's settings field, for the settings page.
	 *
	 * @return array<string, mixed>
	 */
	public static function field(): array {
		return array(
			'id'        => 'pixonaut_wc_developer_tools',
			'type'      => self::FIELD_TYPE,
			'is_option' => false,
		);
	}

	public function render(): void {
		$this->rendered = true;
		$this->templates->render( 'admin/developer-tools.php', $this->section() );
	}

	/**
	 * What the section shows.
	 *
	 * @return array<string, mixed>
	 */
	public function section(): array {
		$hosts = array();
		foreach ( Hosts::CONSTANTS as $key => $constant ) {
			$hosts[ $key ] = array(
				'value'     => (string) $this->settings->get( $key ),
				'locked_by' => Hosts::locked_by( $key ) ?? '',
				'editable'  => $this->may_change( $key ),
			);
		}
		return array(
			'anchor'           => self::ANCHOR,
			'form_id'          => self::FORM_ID,
			'fields_arg'       => self::FIELDS_ARG,
			'reset_arg'        => self::RESET_ARG,
			'countdown'        => self::countdown(),
			'settings'         => $this->settings->section( Setting::DEVELOPER ),
			'hosts'            => $hosts,
			'effective'        => array(
				Settings::API_BASE        => Hosts::api(),
				Settings::SERVER_API_BASE => Hosts::server_api(),
				Settings::STUDIO_BASE     => Hosts::studio(),
				Settings::CDN_BASE        => Hosts::cdn(),
			),
			'sdk_url'          => (string) $this->settings->get( Settings::SDK_URL ),
			'sdk_url_editable' => $this->may_change( Settings::SDK_URL ),
			'pinned_sdk'       => Sdk::pinned_url(),
			'sdk_debug'        => true === $this->settings->get( Settings::SDK_DEBUG ),
			'request_log'      => $this->request_log->enabled(),
			'requests'         => $this->request_log->entries(),
			'connection'       => $this->connection_details(),
			'actions'          => array(
				'pull'    => $this->action_url( self::PULL_ACTION ),
				'ping'    => $this->action_url( self::PING_ACTION ),
				'recheck' => $this->action_url( self::RECHECK_ACTION ),
				'clear'   => $this->action_url( self::CLEAR_ACTION ),
			),
			'jobs'             => $this->jobs(),
			'result'           => $this->result(),
		);
	}

	/**
	 * The notice counting down the last presses before the section unlocks,
	 * by presses left.
	 *
	 * @return array<int, string>
	 */
	private static function countdown(): array {
		$texts = array();
		foreach ( array( 1, 2, 3 ) as $left ) {
			/* translators: %d: how many more clicks or Home presses unlock the developer tools. */
			$texts[ $left ] = sprintf( _n( '%d more to enable developer tools', '%d more to enable developer tools', $left, 'pixonaut-for-woocommerce' ), $left );
		}
		return $texts;
	}

	/**
	 * The connection, for debugging; null while not connected.
	 *
	 * @return array{workspace: string, key_prefix: string, mode: string, paused: string, webhook_url: string, webhook: array{active: bool, failing: bool, failed_count: int}|null, spec: string, sdk: string}|null
	 */
	private function connection_details(): ?array {
		$stored = $this->connection->current();
		if ( null === $stored ) {
			return null;
		}
		$paused  = $this->connection->paused();
		$webhook = null;
		if ( null === $paused ) {
			try {
				$webhook = $this->endpoint->health( $stored );
			} catch ( ApiException $e ) {
				$webhook = null;
			}
		}
		return array(
			'workspace'   => $stored->tenant_name,
			'key_prefix'  => substr( $stored->integration_key, 0, self::KEY_PREFIX_LENGTH ),
			'mode'        => $stored->environment,
			'paused'      => $paused ?? '',
			'webhook_url' => $stored->webhook_url,
			'webhook'     => $webhook,
			'spec'        => ApiVersion::TARGET,
			'sdk'         => Sdk::VERSION,
		);
	}

	/**
	 * The plugin's pending and failed jobs, newest first per status.
	 *
	 * @return list<array{id: int, hook: string, status: string, date: string, url: string}>
	 */
	private function jobs(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}
		$jobs = array();
		foreach ( self::JOB_STATUSES as $status ) {
			$actions = as_get_scheduled_actions(
				array(
					'group'    => Schema::ACTION_GROUP,
					'status'   => $status,
					'per_page' => self::MAX_JOBS,
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			);
			foreach ( $actions as $id => $action ) {
				if ( ! $action instanceof ActionScheduler_Action ) {
					continue;
				}
				$schedule = $action->get_schedule();
				$date     = $schedule instanceof ActionScheduler_Abstract_Schedule ? $schedule->get_date() : null;
				$jobs[]   = array(
					'id'     => (int) $id,
					'hook'   => $action->get_hook(),
					'status' => $status,
					'date'   => null === $date ? '' : $date->format( 'Y-m-d H:i:s' ) . ' UTC',
					'url'    => add_query_arg(
						array(
							'page'   => 'wc-status',
							'tab'    => 'action-scheduler',
							'status' => $status,
							's'      => $action->get_hook(),
						),
						admin_url( 'admin.php' )
					),
				);
			}
		}
		return $jobs;
	}

	/**
	 * Prints the section's form, outside WooCommerce's settings form, once
	 * the section was rendered.
	 */
	public function print_form(): void {
		if ( ! $this->rendered ) {
			return;
		}
		?>
		<form id="<?php echo esc_attr( self::FORM_ID ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>
		</form>
		<?php
	}

	/**
	 * @param string $hook_suffix The admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( SettingsPage::HOOK_SUFFIX !== $hook_suffix || SettingsPage::CONNECTION !== ( new SettingsPage( $this->settings ) )->current_tab() ) {
			return;
		}
		$asset_file = dirname( $this->file ) . '/assets/build/admin-developer-tools.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset   = require $asset_file;
		$version = is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION;
		wp_enqueue_style( self::SCRIPT_HANDLE, plugins_url( 'assets/build/admin-developer-tools.css', $this->file ), array(), $version );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/admin-developer-tools.js', $this->file ),
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			$version,
			true
		);
	}

	/**
	 * Says in the admin bar that Pixonaut is somewhere else, so nobody
	 * forgets the store's key is sent there.
	 *
	 * @param WP_Admin_Bar $bar The admin bar.
	 */
	public function admin_bar( WP_Admin_Bar $bar ): void {
		$hosts = Hosts::custom();
		if ( array() === $hosts || ! current_user_can( Notices::CAPABILITY ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => self::ADMIN_BAR_NODE,
				/* translators: Item in the admin bar, shown to the merchant, when the plugin talks to a custom Pixonaut host instead of the default one. */
				'title' => esc_html__( 'Custom Pixonaut host', 'pixonaut-for-woocommerce' ),
				'href'  => ConnectionScreen::settings_url() . '#' . self::ANCHOR,
				'meta'  => array( 'title' => implode( ', ', $hosts ) ),
			)
		);
	}

	/**
	 * Saves the section's settings, or resets one of them. An address a
	 * constant fixes, or one the user may not change, is left as it is.
	 */
	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION );
		$this->require_capability();
		$posted = isset( $_POST[ self::FIELDS_ARG ] ) && is_array( $_POST[ self::FIELDS_ARG ] ) ? wp_unslash( $_POST[ self::FIELDS_ARG ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is checked below, by type and as an address.
		$reset  = isset( $_POST[ self::RESET_ARG ] ) ? sanitize_key( wp_unslash( $_POST[ self::RESET_ARG ] ) ) : '';

		$settings = $this->settings->section( Setting::DEVELOPER );
		if ( '' !== $reset ) {
			if ( isset( $settings[ $reset ] ) && $this->may_change( $reset ) ) {
				$this->settings->set( $reset, $settings[ $reset ]->fallback );
			}
			$this->back( self::SAVED );
		}

		$result = self::SAVED;
		$was_on = $this->request_log->enabled();
		foreach ( $settings as $key => $setting ) {
			if ( is_bool( $setting->fallback ) ) {
				$this->settings->set( $key, isset( $posted[ $key ] ) && '1' === $posted[ $key ] );
				continue;
			}
			if ( ! $this->may_change( $key ) ) {
				continue;
			}
			$value = is_string( $posted[ $key ] ?? null ) ? trim( $posted[ $key ] ) : '';
			// esc_url_raw() keeps percent-encoding; an address it or the setting refuses is not saved.
			$address = '' === $value ? '' : esc_url_raw( $value, array( 'http', 'https' ) );
			if ( '' !== $value && ( '' === $address || '' === $setting->resolve( $address ) ) ) {
				$result = self::INVALID_URL;
				continue;
			}
			$this->settings->set( $key, $address );
		}
		if ( ! $was_on && $this->request_log->enabled() ) {
			// Turning the log on starts a new one.
			$this->request_log->clear();
		}
		$this->back( $result );
	}

	/**
	 * Whether the current user may change a setting. An address sends the
	 * store's key elsewhere, so it takes an administrator; a custom SDK
	 * script runs for every visitor, so it also takes one who may add
	 * scripts to the site. A constant fixes an address for everyone.
	 *
	 * @param string $key A developer tools setting.
	 */
	public function may_change( string $key ): bool {
		if ( Settings::SDK_URL === $key ) {
			return current_user_can( self::ADDRESS_CAPABILITY ) && current_user_can( self::SCRIPT_CAPABILITY );
		}
		if ( isset( Hosts::CONSTANTS[ $key ] ) ) {
			return null === Hosts::locked_by( $key ) && current_user_can( self::ADDRESS_CAPABILITY );
		}
		return true;
	}

	public function handle_pull(): void {
		check_admin_referer( self::PULL_ACTION );
		$this->require_capability();
		$this->require_connection();
		$this->back( $this->events->pull_now() ? self::PULLED : self::PULL_FAILED );
	}

	/**
	 * Has Pixonaut send the store's own endpoint a test webhook.
	 */
	public function handle_ping(): void {
		check_admin_referer( self::PING_ACTION );
		$this->require_capability();
		$stored = $this->require_connection();
		$this->endpoint->ping( $stored );
		$this->back( self::PINGED );
	}

	public function handle_recheck(): void {
		check_admin_referer( self::RECHECK_ACTION );
		$this->require_capability();
		$this->require_connection();
		$this->back( $this->recheck->queue_recheck_all() ? self::RECHECK_QUEUED : self::RECHECK_FAILED );
	}

	/**
	 * Clears the cached catalogue answers and the products' stored designer
	 * links, which are queued to be withdrawn; a product mints a new link
	 * when it is next shown.
	 */
	public function handle_clear(): void {
		check_admin_referer( self::CLEAR_ACTION );
		$this->require_capability();
		$this->catalogue_cache->flush();
		$this->designer_links->clear();
		$this->back( self::CLEARED );
	}

	/**
	 * The working connection; sends the admin back when there is none.
	 */
	private function require_connection(): StoredConnection {
		$stored = $this->connection->current();
		if ( null === $stored || null !== $this->connection->paused() ) {
			$this->back( self::NOT_CONNECTED );
		}
		return $stored;
	}

	/**
	 * The outcome the last action sent back, as a key.
	 */
	private function result(): string {
		return isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	}

	/**
	 * @param string $action An `admin-post.php` action.
	 */
	private function action_url( string $action ): string {
		return wp_nonce_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ), $action );
	}

	private function require_capability(): void {
		if ( ! current_user_can( Notices::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to use the Pixonaut developer tools.', 'pixonaut-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * @param string $result What happened, for the section.
	 */
	private function back( string $result ): never {
		wp_safe_redirect( add_query_arg( self::RESULT_ARG, $result, ConnectionScreen::settings_url() ) . '#' . self::ANCHOR );
		exit;
	}
}
