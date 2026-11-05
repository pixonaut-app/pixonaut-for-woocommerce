<?php
/**
 * Update channels.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Updates;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Setting;

/**
 * Which releases a store is offered: stable ones only, or stable ones and
 * beta pre-releases (`1.2.0-beta.3`).
 */
final class UpdateChannel {

	public const STABLE = 'stable';

	public const BETA = 'beta';

	/**
	 * Key of the channel in the settings option.
	 */
	public const SETTING = 'update_channel';

	/**
	 * A stable release's version: `1.2.3`.
	 */
	private const STABLE_VERSION = '/^\d+\.\d+\.\d+$/';

	/**
	 * A stable release's version, or a beta pre-release's: `1.2.3-beta.4`.
	 */
	private const BETA_VERSION = '/^\d+\.\d+\.\d+(?:-beta\.\d+)?$/';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::STABLE, self::BETA );
	}

	/**
	 * Whether a store on a channel is offered a release.
	 *
	 * @param string $channel One of all(); anything else is treated as stable.
	 * @param string $version The release's version, without a leading `v`.
	 */
	public static function accepts( string $channel, string $version ): bool {
		$pattern = self::BETA === $channel ? self::BETA_VERSION : self::STABLE_VERSION;
		return 1 === preg_match( $pattern, $version );
	}

	/**
	 * The site option where the update checker keeps its state for a channel.
	 *
	 * @param string $channel One of all(); anything else is treated as stable.
	 */
	public static function state_option( string $channel ): string {
		return Schema::UPDATE_STATE_OPTIONS[ self::BETA === $channel ? self::BETA : self::STABLE ];
	}

	/**
	 * The channel setting on the Pixonaut settings tab.
	 */
	public static function setting(): Setting {
		return Setting::select(
			self::SETTING,
			self::STABLE,
			self::all( ... ),
			static fn (): array => array(
				/* translators: Title of the update channel setting on the settings page, shown to the merchant. */
				'title'    => __( 'Updates', 'pixonaut-for-woocommerce' ),
				'desc'     => __( 'Beta releases bring new features before they are stable. Use them on a staging site first.', 'pixonaut-for-woocommerce' ),
				'desc_tip' => true,
				'options'  => array(
					/* translators: Option in the update channel setting, shown to the merchant: only stable plugin releases. */
					self::STABLE => __( 'Stable releases', 'pixonaut-for-woocommerce' ),
					/* translators: Option in the update channel setting, shown to the merchant: also pre-release versions of the plugin. */
					self::BETA   => __( 'Beta releases', 'pixonaut-for-woocommerce' ),
				),
			)
		);
	}
}
