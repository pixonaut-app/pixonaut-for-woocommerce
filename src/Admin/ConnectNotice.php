<?php
/**
 * The notice asking to connect the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Templates;

/**
 * While the store is not connected, the Plugins screen and the WooCommerce
 * screens say so and link to the connection settings. Each user may dismiss
 * it for themselves; connecting takes it away for everyone. A paused
 * connection has a notice of its own.
 */
final class ConnectNotice {

	public const DISMISS_ACTION = 'pixonaut_wc_dismiss_connect_notice';

	public const META = Schema::CONNECT_NOTICE_DISMISSED_META;

	/**
	 * The Plugins screen's id.
	 */
	private const PLUGINS_SCREEN = 'plugins';

	/**
	 * The settings page's screen, which shows the connection card instead.
	 */
	private const SETTINGS_SCREEN = 'toplevel_page_' . SettingsPage::PAGE;

	/**
	 * @param Connection $connection   The connection.
	 * @param Templates  $templates    Renders the notice.
	 * @param string     $settings_url The connection settings.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Templates $templates,
		private readonly string $settings_url,
	) {
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Whether the notice shows to a user on a screen.
	 *
	 * @param int    $user_id   The user.
	 * @param string $screen_id The screen's id, as `get_current_screen()` names it.
	 */
	public function shows( int $user_id, string $screen_id ): bool {
		return user_can( $user_id, Notices::CAPABILITY )
			&& self::on_screen( $screen_id )
			&& null === $this->connection->current()
			&& null === $this->connection->paused()
			&& ! get_user_option( self::META, $user_id );
	}

	public function render(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! $this->shows( get_current_user_id(), $screen->id ) ) {
			return;
		}
		$this->templates->render(
			'admin/notice.php',
			array(
				'message'     => sprintf(
					/* translators: 1: link to the plugin's connection settings, 2: link to the guide on connecting. */
					__( 'Pixonaut for WooCommerce is not connected yet. <a href="%1$s">Connect your store to Pixonaut</a> to sell personalised products. <a href="%2$s" target="_blank" rel="noopener noreferrer">How connecting works</a>', 'pixonaut-for-woocommerce' ),
					esc_url( $this->settings_url ),
					esc_url( HelpLinks::docs( HelpLinks::CONNECT ) )
				),
				'type'        => 'info',
				'dismiss_url' => wp_nonce_url( add_query_arg( 'action', self::DISMISS_ACTION, admin_url( 'admin-post.php' ) ), self::DISMISS_ACTION ),
			)
		);
	}

	/**
	 * Hides the notice from the user who dismissed it, then returns to the
	 * screen they were on.
	 */
	public function handle_dismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( Notices::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to dismiss this notice.', 'pixonaut-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		update_user_option( get_current_user_id(), self::META, '1' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * @param string $screen_id The screen's id.
	 */
	private static function on_screen( string $screen_id ): bool {
		if ( self::PLUGINS_SCREEN === $screen_id ) {
			return true;
		}
		return self::SETTINGS_SCREEN !== $screen_id
			&& function_exists( 'wc_get_screen_ids' )
			&& in_array( $screen_id, wc_get_screen_ids(), true );
	}
}
