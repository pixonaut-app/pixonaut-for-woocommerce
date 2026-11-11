<?php
/**
 * Names of what the plugin stores.
 *
 * @package Pixonaut\WooCommerce
 */

namespace Pixonaut\WooCommerce;

/**
 * Kept to PHP 7.2 syntax like Requirements: uninstall.php reads it, and
 * WordPress runs uninstall without the plugin's PHP version check.
 */
final class Schema {

	/**
	 * Option holding the version of the last migration applied.
	 */
	const VERSION_OPTION = 'pixonaut_wc_schema_version';

	/**
	 * Option held while migrations run, so one request runs them at a time.
	 */
	const MIGRATION_LOCK_OPTION = 'pixonaut_wc_migration_lock';

	/**
	 * Option holding the deprecations Pixonaut announced, per operation.
	 */
	const API_DEPRECATIONS_OPTION = 'pixonaut_wc_api_deprecations';

	/**
	 * Option holding the plugin's settings, one array keyed by setting.
	 */
	const SETTINGS_OPTION = 'pixonaut_wc_settings';

	/**
	 * Option holding the persistent admin notices, keyed by notice.
	 */
	const NOTICES_OPTION = 'pixonaut_wc_notices';

	/**
	 * Option holding the store's connection, encrypted, and whether it is paused.
	 */
	const CONNECTION_OPTION = 'pixonaut_wc_connection';

	/**
	 * Option held while a connect or disconnect changes the connection, so
	 * one request does at a time.
	 */
	const CONNECTION_LOCK_OPTION = 'pixonaut_wc_connection_lock';

	/**
	 * Option holding the last test request Pixonaut sent the store's webhook
	 * endpoint: whether it was delivered, why not, and when.
	 */
	const WEBHOOK_PING_OPTION = 'pixonaut_wc_webhook_ping';

	/**
	 * The event types the store's webhook endpoint was last subscribed to.
	 */
	const WEBHOOK_EVENT_TYPES_OPTION = 'pixonaut_wc_webhook_event_types';

	/**
	 * Whether Pixel may make changes to the store: the hash of the store's
	 * credential, and whether it was turned off in WordPress.
	 */
	const STORE_ACCESS_OPTION = 'pixonaut_wc_store_access';

	/**
	 * User meta holding an admin's connect in progress: `state` and PKCE verifier.
	 */
	const CONNECT_ATTEMPT_META = '_pixonaut_wc_connect_attempt';

	/**
	 * User meta that held a user's link to their Pixonaut account in progress.
	 * No longer written; `ForgetMemberLinks` and uninstall delete it.
	 */
	const MEMBER_LINK_ATTEMPT_META = '_pixonaut_wc_member_link_attempt';

	/**
	 * User option that held the user's link to their Pixonaut member, encrypted.
	 * No longer written; `ForgetMemberLinks` and uninstall delete it.
	 */
	const MEMBER_LINK_META = '_pixonaut_wc_member_link';

	/**
	 * User option set when Pixonaut refused the user's member key. No longer
	 * written; `ForgetMemberLinks` and uninstall delete it.
	 */
	const MEMBER_LINK_REVOKED_META = '_pixonaut_wc_member_link_revoked';

	/**
	 * Site options where the update checker keeps the last check and the
	 * update it found, one per update channel (`UpdateChannel::all()`). A
	 * network's sites may be on different channels, so each channel has its
	 * own: a stable site never sees the beta a beta site found.
	 */
	const UPDATE_STATE_OPTIONS = array(
		'stable' => 'pixonaut_wc_update_state_stable',
		'beta'   => 'pixonaut_wc_update_state_beta',
	);

	/**
	 * Cron hook of the update checker's scheduled checks: plugin-update-checker
	 * names it `puc_cron_check_updates-<slug>`.
	 */
	const UPDATE_CHECK_CRON_HOOK = 'puc_cron_check_updates-pixonaut-for-woocommerce';

	/**
	 * Product and variation meta holding the Pixonaut configuration.
	 */
	const PRODUCT_CONFIG_META = '_pixonaut_config';

	/**
	 * Product and variation meta naming each template the configuration
	 * uses, by base id, one row per template: finds what a template's change
	 * affects.
	 */
	const PRODUCT_TEMPLATE_INDEX_META = '_pixonaut_template';

	/**
	 * Product and variation meta naming the blank the configuration uses:
	 * finds what a blank's change affects.
	 */
	const PRODUCT_BLANK_INDEX_META = '_pixonaut_blank';

	/**
	 * Product and variation meta naming the blank variant it sells: the one
	 * the store manager picked, else the one its options match.
	 */
	const PRODUCT_VARIANT_META = '_pixonaut_variant';

	/**
	 * Variation meta holding the print size it is sold at instead of its
	 * product's, per side, with Pixonaut's verdict on it.
	 */
	const VARIATION_PRINT_SIZE_META = '_pixonaut_print_size';

	/**
	 * Product meta noting the custom attributes it was moved off, so links
	 * and cart lines naming one keep selecting the same value.
	 */
	const PRODUCT_MOVED_ATTRIBUTES_META = '_pixonaut_moved_attributes';

	/**
	 * Product and variation meta recording its Pixonaut picture: the
	 * attachment, and what it was drawn from.
	 */
	const PRODUCT_PICTURE_META = '_pixonaut_picture';

	/**
	 * Product meta listing the template's showcase sets the store manager
	 * chose as its images, each with how it is drawn: JSON, a list of
	 * `{id, name, kind, view?, photo?}`.
	 */
	const PRODUCT_SHOWCASE_IMAGES_META = '_pixonaut_showcase_images';

	/**
	 * Product and variation meta choosing what a picture is drawn on: on a
	 * product, the picture drawn without a showcase set; on a variation, its
	 * picture in place of its product's choice. JSON, `{kind, view?, photo?}`.
	 */
	const PICTURE_VIEW_META = '_pixonaut_picture_view';

	/**
	 * Product and variation meta, `yes` when it showed its own image rather
	 * than its Pixonaut picture. No longer written: `ReplaceKeptImages` turns
	 * it into `PRODUCT_PREVIOUS_IMAGE_META`.
	 */
	const PRODUCT_KEEPS_IMAGE_META = '_pixonaut_keeps_image';

	/**
	 * Product and variation meta holding the image it had before its
	 * Pixonaut picture first became its image: an attachment id, `0` for
	 * none. Put back when it no longer shows the picture.
	 */
	const PRODUCT_PREVIOUS_IMAGE_META = '_pixonaut_previous_image';

	/**
	 * Attachment meta naming a product or variation whose Pixonaut picture
	 * it is, one row for each that shows it: variations drawn from the same
	 * source share one picture. Only such an attachment is ever deleted by
	 * the plugin, once no row is left.
	 */
	const PICTURE_OF_META = '_pixonaut_picture_of';

	/**
	 * Attachment meta holding the key of what a Pixonaut picture was drawn
	 * from, so a product or variation drawn from the same reuses it.
	 */
	const PICTURE_SOURCE_META = '_pixonaut_picture_source';

	/**
	 * A number every product picture is drawn under; moving it draws them
	 * all again.
	 */
	const PICTURE_GENERATION_OPTION = 'pixonaut_wc_picture_generation';

	/**
	 * Product meta: a number the product's pictures, and its variations',
	 * are drawn under; moving it draws them again. Absent until the store
	 * manager first asks for them to be drawn again.
	 */
	const PRODUCT_PICTURE_GENERATION_META = '_pixonaut_picture_generation';

	/**
	 * Prefix of the option a job holds while it draws a product's picture,
	 * followed by the product's id.
	 */
	const PICTURE_LOCK_OPTION_PREFIX = 'pixonaut_wc_picture_lock_';

	/**
	 * Prefix of the option a runner of the pictures' jobs holds while it
	 * runs, followed by its slot.
	 */
	const PICTURE_RUNNER_OPTION_PREFIX = 'pixonaut_wc_picture_runner_';

	/**
	 * Option set while every product's picture waits to be checked after an
	 * upgrade, until the job doing it is queued.
	 */
	const PICTURES_BACKFILL_OPTION = 'pixonaut_wc_pictures_backfill';

	/**
	 * Product and variation meta holding its kept designer links, by language.
	 */
	const DESIGNER_LINKS_META = '_pixonaut_designer_links';

	/**
	 * Option set while every product's blank variant waits to be resolved
	 * after an upgrade, until the job doing it is queued.
	 */
	const VARIANTS_BACKFILL_OPTION = 'pixonaut_wc_variants_backfill';

	/**
	 * Prefix of the transients caching Pixonaut catalogue answers for the admin.
	 */
	const CATALOGUE_CACHE_PREFIX = 'pixonaut_wc_catalogue_';

	/**
	 * Option holding the catalogue cache's generation, which every cached
	 * answer's key carries: raising it drops them all at once.
	 */
	const CATALOGUE_CACHE_GENERATION_OPTION = 'pixonaut_wc_catalogue_generation';

	/**
	 * Transient set for a minute after a product page's Pixonaut read failed,
	 * under the catalogue cache's prefix so it goes with the cache.
	 */
	const SERVER_READS_PAUSE_TRANSIENT = self::CATALOGUE_CACHE_PREFIX . 'server_reads_paused';

	/**
	 * Option holding the developer tools' request log: the last requests to
	 * Pixonaut, newest first, while the log is on.
	 */
	const REQUEST_LOG_OPTION = 'pixonaut_wc_request_log';

	/**
	 * Prefix of the transients caching proof images for the proof route.
	 */
	const PROOF_CACHE_PREFIX = 'pixonaut_wc_proof_';

	/**
	 * Prefix of the transients marking a design whose proof images were
	 * erased, so a fetch already running does not cache them again. Under
	 * PROOF_CACHE_PREFIX, so removing the cache removes them too.
	 */
	const PROOF_ERASED_PREFIX = self::PROOF_CACHE_PREFIX . 'erased_';

	/**
	 * Prefix of the options, not autoloaded, that keep for good that a design
	 * no order line holds, one in a cart, was erased: a cart outlives the
	 * transient above, and its line has no other place to keep it. Deleted
	 * with the design.
	 */
	const ERASED_CART_DESIGN_PREFIX = 'pixonaut_wc_erased_cart_design_';

	/**
	 * Prefix of the options that let one request at a time fetch a proof image.
	 */
	const PROOF_LOCK_OPTION_PREFIX = 'pixonaut_wc_proof_lock_';

	/**
	 * Prefix of the options that let one request at a time mint a product's
	 * designer link.
	 */
	const DESIGNER_LINK_LOCK_OPTION_PREFIX = 'pixonaut_wc_designer_link_lock_';

	/**
	 * Option holding the store's brand and its accent colour as last read, and when.
	 */
	const WORKSPACE_SETTINGS_OPTION = 'pixonaut_wc_workspace_settings';

	/**
	 * Option holding the ids of the global attributes whose values are listed
	 * as their own products by default.
	 */
	const SPLIT_ATTRIBUTES_OPTION = 'pixonaut_wc_split_attributes';

	/**
	 * Option holding, by global attribute id, whether its swatches show
	 * their values' names whatever the store's setting says.
	 */
	const SWATCH_NAMES_OPTION = 'pixonaut_wc_swatch_names';

	/**
	 * Option holding the ids of designer links Pixonaut could not be asked
	 * to withdraw yet, which a job asks again.
	 */
	const DESIGNER_LINK_WITHDRAWALS_OPTION = 'pixonaut_wc_designer_link_withdrawals';

	/**
	 * Order item meta holding a line's fulfilment state and Pixonaut refs.
	 */
	const LINE_STATE_META = '_pixonaut_line';

	/**
	 * Order item meta holding the line's Pixonaut order id, so a webhook finds
	 * the line by it. Written only alongside the line state.
	 */
	const LINE_ORDER_ID_META = '_pixonaut_order_id';

	/**
	 * Order item meta holding each of the line's design ids, one row per
	 * side, so a design's lines are found by it. Written only alongside the
	 * line state.
	 */
	const LINE_DESIGN_ID_META = '_pixonaut_design_id';

	/**
	 * Table, after the site's prefix, holding the webhook events received:
	 * the dedupe log, and the body the job that handles an event reads.
	 */
	const WEBHOOK_EVENTS_TABLE = 'pixonaut_webhook_events';

	/**
	 * Option holding the event stream's cursor:
	 * `array( 'after' => ?string, 'connection' => string )`, the id of the
	 * last event pulled, null for the stream's start, and the `connection_id`
	 * of the connection it belongs to; a cursor of another connection counts
	 * as none. Absent until the first cursor of a connection is set.
	 */
	const EVENTS_CURSOR_OPTION = 'pixonaut_wc_events_cursor';

	/**
	 * Option holding how far setting the first cursor has paged, while it
	 * takes more than one job: the same shape as EVENTS_CURSOR_OPTION.
	 */
	const EVENTS_BASELINE_OPTION = 'pixonaut_wc_events_baseline';

	/**
	 * Option holding every change of a template's id Pixonaut reported
	 * (`template.renamed`), as `{from, to, at}`.
	 */
	const TEMPLATE_RENAMES_OPTION = 'pixonaut_wc_template_renames';

	/**
	 * Action Scheduler group of the plugin's jobs.
	 */
	const ACTION_GROUP = 'pixonaut-for-woocommerce';

	/**
	 * Prefix of the options that throttle reports from product pages whose
	 * designer could not load, each holding when its window ends.
	 */
	const REPORT_THROTTLE_OPTION_PREFIX = 'pixonaut_wc_report_';

	/**
	 * Option holding the recent failed design commits at add to cart, and
	 * the cause of the notice they raised, if any.
	 */
	const COMMIT_FAILURES_OPTION = 'pixonaut_wc_commit_failures';

	/**
	 * Option held while one request changes the failed commits, so
	 * concurrent add-to-cart requests neither lose a count nor raise the
	 * notice twice.
	 */
	const COMMIT_FAILURES_LOCK_OPTION = 'pixonaut_wc_commit_failures_lock';

	/**
	 * User option, under the site's table prefix, set once a user dismissed
	 * the notice asking to connect the store.
	 */
	const CONNECT_NOTICE_DISMISSED_META = '_pixonaut_wc_connect_notice_dismissed';
}
