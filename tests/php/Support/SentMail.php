<?php
/**
 * Emails WordPress sent during a test.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * Reads the test suite's mock mailer, which keeps every email sent since
 * `reset_phpmailer_instance()`.
 */
final class SentMail {

	/**
	 * @return list<array{to: string, subject: string, body: string}> The emails sent, oldest first.
	 */
	public static function all(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		$mails  = array();
		foreach ( false === $mailer ? array() : $mailer->mock_sent as $sent ) {
			$to      = $sent['to'][0][0] ?? '';
			$mails[] = array(
				'to'      => is_string( $to ) ? $to : '',
				'subject' => (string) ( $sent['subject'] ?? '' ),
				'body'    => (string) ( $sent['body'] ?? '' ),
			);
		}
		return $mails;
	}
}
