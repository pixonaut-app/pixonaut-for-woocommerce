<?php
/**
 * What the plugin's emails share.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Templates;
use WC_Email;

/**
 * A WooCommerce email whose templates are the plugin's, overridden from the
 * theme next to the plugin's other templates.
 */
abstract class Email extends WC_Email {

	public function __construct() {
		$this->template_base = dirname( __DIR__, 2 ) . '/templates/';
		parent::__construct();
	}

	public function get_content_html(): string {
		return $this->render( $this->template_html, false );
	}

	public function get_content_plain(): string {
		return $this->render( $this->template_plain, true );
	}

	/**
	 * Where a theme overrides the template: next to the plugin's other overrides.
	 *
	 * @param string $template Template path below `templates/`.
	 */
	public function get_theme_template_file( $template ): string {
		return get_stylesheet_directory() . '/' . Templates::THEME_PATH . $template;
	}

	/**
	 * The variables the templates receive besides the ones every email has.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function template_args(): array;

	/**
	 * @param string $template   Template path below `templates/`.
	 * @param bool   $plain_text Whether it is the plain text one.
	 */
	private function render( string $template, bool $plain_text ): string {
		return wc_get_template_html(
			$template,
			Emails::template_args(
				array(
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => ! $this->is_customer_email(),
					'plain_text'         => $plain_text,
					'email'              => $this,
				) + $this->template_args(),
				$this,
				$plain_text
			),
			Templates::THEME_PATH,
			$this->template_base
		);
	}
}
