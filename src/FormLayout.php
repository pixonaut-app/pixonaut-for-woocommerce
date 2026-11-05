<?php
/**
 * How the designer's form lays out its steps.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * The designer's form, inline and on the hosted designer the Personalise
 * button opens: as the template's author chose, every step on one page, or
 * one step at a time.
 */
final class FormLayout {

	/**
	 * The template's own layout, a page when it has none.
	 */
	public const TEMPLATE = 'template';

	/**
	 * Every step under a numbered heading.
	 */
	public const PAGE = 'page';

	/**
	 * One step at a time, with Back and Next.
	 */
	public const STEPS = 'steps';

	/**
	 * Every choice, in the order the store manager sees them.
	 */
	private const CHOICES = array( self::TEMPLATE, self::PAGE, self::STEPS );

	/**
	 * @return list<string> Every choice.
	 */
	public static function available(): array {
		return self::CHOICES;
	}

	/**
	 * Each choice's name, for the store manager.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			/* translators: Option in the form layout setting, shown to the merchant: let the template decide how the form is laid out. */
			self::TEMPLATE => __( 'Template’s choice', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the form layout setting, shown to the merchant: every step of the designer form on one page. */
			self::PAGE     => __( 'One page', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the form layout setting, shown to the merchant: the designer form shows one step at a time. */
			self::STEPS    => __( 'Steps', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * The SDK's and the designer link's `layout` for a product: its own
	 * override when it names a choice, else the store's default; null leaves
	 * it to the template.
	 *
	 * @param ProductConfig $config   The product's configuration.
	 * @param Settings      $settings The store's settings.
	 * @return string|null `page` or `steps`, or null to send none.
	 */
	public static function resolve( ProductConfig $config, Settings $settings ): ?string {
		$layout = in_array( $config->form_layout, self::CHOICES, true ) ? $config->form_layout : $settings->get( Settings::FORM_LAYOUT );
		return self::TEMPLATE === $layout || ! in_array( $layout, self::CHOICES, true ) ? null : (string) $layout;
	}
}
