<?php
/**
 * The plugin's migrations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

/**
 * Every migration the plugin ships, oldest first. Append; never reorder or
 * remove an entry.
 */
final class Migrations {

	/**
	 * @return list<Migration>
	 */
	public static function all(): array {
		return array(
			new CreateWebhookEventsTable(),
			new IndexProductConfigs(),
			new ResolveProductVariants(),
			new ResolveVariantViews(),
			new ReplaceKeptImages(),
			new RecheckTemplateOnlySizes(),
			new ForgetTrashedProductNotices(),
			new ForgetMemberLinks(),
			new ForgetAddAfterApprove(),
		);
	}
}
