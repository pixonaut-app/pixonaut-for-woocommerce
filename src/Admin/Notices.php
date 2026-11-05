<?php
/**
 * Persistent admin notices.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use InvalidArgumentException;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Templates;

/**
 * Notices that stay on every admin screen until whatever raised them removes
 * them, or a store manager dismisses one that allows it. Each notice has a
 * key, so raising the same problem twice shows it once.
 */
final class Notices {

	public const OPTION = Schema::NOTICES_OPTION;

	public const DISMISS_ACTION = 'pixonaut_wc_dismiss_notice';

	public const TYPES = array( 'error', 'warning', 'info', 'success' );

	/**
	 * Who sees the notices and may dismiss them.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * What a notice key may hold: the characters sanitize_key() keeps, so the
	 * dismiss link's key reaches handle_dismiss() unchanged.
	 */
	public const KEY_PATTERN = '/^[a-z0-9_-]+$/';

	/**
	 * @param Templates $templates Renders each notice.
	 */
	public function __construct( private readonly Templates $templates ) {
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Adds a notice, or replaces the one with the same key.
	 *
	 * @param string $key         Identifies the notice, in lower-case letters, digits, `_` and `-`,
	 *                            e.g. `connection_paused` or `product_123_invalid`.
	 * @param string $message     Translated text; may hold links and inline markup allowed in posts.
	 * @param string $type        One of TYPES; anything else is shown as a warning.
	 * @param bool   $dismissible Whether a store manager may dismiss it.
	 * @throws InvalidArgumentException When the key holds other characters.
	 */
	public function add( string $key, string $message, string $type = 'warning', bool $dismissible = true ): void {
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			throw new InvalidArgumentException( sprintf( 'Notice key "%s" may only hold lower-case letters, digits, "_" and "-".', $key ) );
		}
		$notices         = $this->all();
		$notices[ $key ] = array(
			'message'     => $message,
			'type'        => in_array( $type, self::TYPES, true ) ? $type : 'warning',
			'dismissible' => $dismissible,
		);
		update_option( self::OPTION, $notices, false );
	}

	/**
	 * @param string $key The notice's key.
	 */
	public function remove( string $key ): void {
		$notices = $this->all();
		if ( ! isset( $notices[ $key ] ) ) {
			return;
		}
		unset( $notices[ $key ] );
		update_option( self::OPTION, $notices, false );
	}

	/**
	 * @param string $key The notice's key.
	 */
	public function has( string $key ): bool {
		return isset( $this->all()[ $key ] );
	}

	/**
	 * @return array<string, array{message: string, type: string, dismissible: bool}>
	 */
	public function all(): array {
		$stored  = get_option( self::OPTION, array() );
		$notices = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $notice ) {
			if ( ! is_array( $notice ) || ! isset( $notice['message'], $notice['type'] ) ) {
				continue;
			}
			$notices[ (string) $key ] = array(
				'message'     => (string) $notice['message'],
				'type'        => (string) $notice['type'],
				'dismissible' => ! empty( $notice['dismissible'] ),
			);
		}
		return $notices;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		foreach ( $this->all() as $key => $notice ) {
			$this->templates->render(
				'admin/notice.php',
				array(
					'message'     => $notice['message'],
					'type'        => $notice['type'],
					'dismiss_url' => $notice['dismissible'] ? $this->dismiss_url( $key ) : '',
				)
			);
		}
	}

	/**
	 * Handles the dismiss link, then returns to the screen it was clicked on.
	 */
	public function handle_dismiss(): void {
		$key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		check_admin_referer( self::DISMISS_ACTION . '_' . $key );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to dismiss this notice.', 'pixonaut-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$notices = $this->all();
		if ( isset( $notices[ $key ] ) && $notices[ $key ]['dismissible'] ) {
			$this->remove( $key );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * @param string $key The notice's key.
	 */
	private function dismiss_url( string $key ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'notice' => $key,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . '_' . $key
		);
	}
}
