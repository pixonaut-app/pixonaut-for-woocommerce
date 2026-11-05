<?php
/**
 * Suggested privacy policy text for the store's privacy policy.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Privacy;

/**
 * Settings > Privacy > Policy guide: a section on what the store sends to
 * Pixonaut (designs, photos, delivery details, Pixel), for the merchant to
 * adapt into their own privacy policy.
 */
final class PolicyText {

	/**
	 * The section's heading in the policy guide: the plugin's name.
	 */
	public const PLUGIN_NAME = 'Pixonaut for WooCommerce';

	public const SUBPROCESSORS_URL = 'https://pixonaut.app/subprocessors';

	public const PRIVACY_URL = 'https://pixonaut.app/privacy';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'add' ) );
	}

	/**
	 * Adds the section; WordPress takes it only on `admin_init` in wp-admin.
	 */
	public function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( self::PLUGIN_NAME, self::text() );
	}

	/**
	 * The section: a note to the merchant, then the suggested text.
	 */
	public static function text(): string {
		$suggested = array(
			esc_html__( 'We use Pixonaut to personalise and print the products you design in our store.', 'pixonaut-for-woocommerce' ),
			esc_html__( 'When you personalise a product, the text you type and the photos you upload are sent to Pixonaut, which stores your design and makes its previews and print files.', 'pixonaut-for-woocommerce' ),
			esc_html__( 'When you order a personalised product, your name, email address, phone number and shipping address are sent to Pixonaut and to the printshop that prints and delivers it.', 'pixonaut-for-woocommerce' ),
			esc_html__( 'Where the designer offers Pixel, Pixonaut\'s AI assistant, what you ask Pixel, and the photos and design it works on, are sent to Pixonaut. Our staff may also use Pixel to manage the store, which can include reading your order and its details. Pixonaut processes what is sent to Pixel with AI model providers.', 'pixonaut-for-woocommerce' ),
			esc_html__( 'Pixonaut erases your design and delivery details a number of days after your order is finished. When you ask us to erase your personal data, they are erased sooner, unless we keep them with your order or still need them to deliver it.', 'pixonaut-for-woocommerce' ),
			sprintf(
				/* translators: 1: URL of Pixonaut's privacy policy, 2: URL of Pixonaut's list of sub-processors. */
				esc_html__( 'Pixonaut privacy policy: %1$s. Pixonaut\'s sub-processors: %2$s.', 'pixonaut-for-woocommerce' ),
				'<a href="' . esc_url( self::PRIVACY_URL ) . '">' . esc_html( self::PRIVACY_URL ) . '</a>',
				'<a href="' . esc_url( self::SUBPROCESSORS_URL ) . '">' . esc_html( self::SUBPROCESSORS_URL ) . '</a>'
			),
		);
		return '<div class="wp-suggested-text">'
			. '<p class="privacy-policy-tutorial">' . esc_html__( 'Personalised products are designed, proofed and printed through Pixonaut, so the store sends Pixonaut the personal data below. Adapt this text to what your store uses: leave out Pixel if you do not use it.', 'pixonaut-for-woocommerce' ) . '</p>'
			/* translators: Label before the text WordPress suggests for the store's privacy policy. */
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'pixonaut-for-woocommerce' ) . '</strong></p>'
			. implode( '', array_map( static fn ( string $paragraph ): string => '<p>' . $paragraph . '</p>', $suggested ) )
			. '</div>';
	}
}
