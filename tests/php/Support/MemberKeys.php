<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * The member key Pixonaut's connect answer carries. The plugin does not read
 * it, but the pinned spec requires it.
 */
final class MemberKeys {

	public const KEY = 'mk_live_fixture_member';

	public const ANSWER = array(
		'key'         => self::KEY,
		'memberId'    => 'mem_owner',
		'memberName'  => 'Store Owner',
		'memberEmail' => 'owner@example.test',
	);
}
