<?php
/**
 * Verdicts a scripted `POST /v1/templates/{id}/print-size` answers.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * Every verdict names the images its size prints too soft, so a scripted
 * one carries `lowResolution` even when there are none.
 */
final class VerdictAnswers {

	/**
	 * A size the template prints at, with every image sharp enough.
	 */
	public const LEGAL = array(
		'legal'         => true,
		'lowResolution' => array(),
	);
}
