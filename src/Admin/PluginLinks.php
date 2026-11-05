<?php
/**
 * The plugin's links on the Plugins screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

/**
 * A Settings action beside Deactivate, and Docs and Support beside the
 * plugin's version and author.
 */
final class PluginLinks {

	/**
	 * @param string $basename     The plugin's basename, `pixonaut-for-woocommerce/pixonaut-for-woocommerce.php`.
	 * @param string $settings_url The connection settings.
	 */
	public function __construct( private readonly string $basename, private readonly string $settings_url ) {
	}

	public function register(): void {
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * @param array<int|string, string> $links The row's actions.
	 *
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->settings_url ),
			/* translators: Link on the Plugins screen to the plugin's settings. */
			esc_html__( 'Settings', 'pixonaut-for-woocommerce' )
		);
		return array( 'settings' => $settings ) + $links;
	}

	/**
	 * @param array<int|string, string> $meta The row's details.
	 * @param string                    $file The row's plugin.
	 *
	 * @return array<int|string, string>
	 */
	public function row_meta( array $meta, string $file ): array {
		if ( $file !== $this->basename ) {
			return $meta;
		}
		$meta['docs'] = self::external_link(
			HelpLinks::docs(),
			/* translators: Link on the Plugins screen to the plugin's merchant guide. */
			__( 'Docs', 'pixonaut-for-woocommerce' )
		);
		$meta['support'] = self::external_link(
			HelpLinks::SUPPORT_URL,
			/* translators: Link on the Plugins screen to where merchants ask for help with the plugin. */
			__( 'Support', 'pixonaut-for-woocommerce' )
		);
		return $meta;
	}

	/**
	 * @param string $url  Where it goes.
	 * @param string $text Translated link text.
	 */
	private static function external_link( string $url, string $text ): string {
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html( $text ) );
	}
}
