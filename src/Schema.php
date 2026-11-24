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
	 * Variable product meta holding the blanks its variations are generated
	 * from, and which of its attribute values picks each.
	 */
	const PRODUCT_VARIATION_PLAN_META = '_pixonaut_variation_plan';

	/**
	 * Variation meta set on a variation its product's plan made or
	 * disabled: what it was made as, and whether the plan disabled it.
	 */
	const VARIATION_GENERATED_META = '_pixonaut_generated';

	/**
	 * Prefix of the option a run generating a product's variations holds,
	 * by product id, so two runs never write the same product at once.
	 */
	const VARIATION_PLAN_LOCK_OPTION_PREFIX = 'pixonaut_wc_variation_plan_lock_';

	/**
	 * Variation meta holding the print size it is sold at instead of its
	 * product's, per side, with Pixonaut's verdict on it.
	 */
	const VARIATION_PRINT_SIZE_META = '_pixonaut_print_size';

	/**
	 * Product and variation meta holding the id of the re-check job it was
	 * last queued in, so it is not queued again while that job waits.
	 */
	const PRODUCT_RECHECK_JOB_META = '_pixonaut_recheck_job';

	/**
	 * Product and variation meta holding the images the store manager
	 * accepted at a size although they print below their recommended
	 * resolution there, by side, size and picture.
	 */
	const LOW_RESOLUTION_ACCEPTED_META = '_pixonaut_low_resolution_accepted';

	/**
	 * Product meta noting the custom attributes it was moved off, so links
	 * and cart lines naming one keep selecting the same value.
	 */
	const PRODUCT_MOVED_ATTRIBUTES_META = '_pixonaut_moved_attributes';

	/**
	 * Product meta listing the values of the attribute a variable product is
	 * listed by, one card per value: a row per term id, in the product's
	 * order of values. The shop's listings join it to show each card.
	 */
	const PRODUCT_SPLIT_CARD_META = '_pixonaut_split_card';

	/**
	 * Product meta naming the values a split product's cards hold of the
	 * attributes it lists a card per value of only while a filter chooses
	 * one: a row per value, `<taxonomy>:<term id>`. The shop's listings
	 * read it beside the card rows.
	 */
	const PRODUCT_SPLIT_FILTERED_META = '_pixonaut_split_filtered';

	/**
	 * Product meta holding a split product's cards' own titles and images,
	 * by term id, as the store manager set them.
	 */
	const PRODUCT_SPLIT_CARD_LOOKS_META = '_pixonaut_split_card_looks';

	/**
	 * Product meta holding the images a split product's cards wait for,
	 * copied from their URLs in the background, and those that could not be
	 * copied: by card key, the URL, the copy's token, its state and why it
	 * failed.
	 */
	const PRODUCT_SPLIT_CARD_IMAGE_COPIES_META = '_pixonaut_split_card_image_copies';

	/**
	 * Attachment meta holding the URL a card's image was copied from.
	 */
	const ATTACHMENT_CARD_IMAGE_SOURCE_META = '_pixonaut_card_image_source';

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
	 * Variation meta marking one a filtered shop card opens on, which is
	 * drawn on its blank's photo too when its image is drawn flat
	 * (`CardPhotos`).
	 */
	const VARIATION_CARD_PHOTO_META = '_pixonaut_card_photo';

	/**
	 * Product meta choosing whether each of its variations is drawn on its
	 * blank's photo, its own picture, rather than sharing the flat one:
	 * `on`, `off`, or absent for the store-wide setting
	 * (`VariationPictures`).
	 */
	const PRODUCT_VARIATION_PICTURES_META = '_pixonaut_variation_pictures';

	/**
	 * Option holding when the picture report was last sent to Pixonaut, as
	 * a Unix time (`PictureReport`).
	 */
	const PICTURE_REPORT_SENT_OPTION = 'pixonaut_wc_picture_report_sent';

	/**
	 * Transient keeping the average bytes of a Pixonaut picture the shop
	 * keeps, for the estimate of variation pictures (`PictureStorage`).
	 */
	const PICTURE_AVERAGE_TRANSIENT = 'pixonaut_wc_picture_average_bytes';

	/**
	 * Option holding the exchange rates last read, their day and when they
	 * were read: converts a printshop's costs to the store's currency.
	 */
	const EXCHANGE_RATES_OPTION = 'pixonaut_wc_exchange_rates';

	/**
	 * Product and variation meta holding what its price follows: the
	 * printshop cost last read for it, the price the pricing rule last set,
	 * and what the rule suggests now. JSON.
	 */
	const PRICE_META = '_pixonaut_price';

	/**
	 * Product, variation and attribute term meta holding the parts of the
	 * store's pricing rule the product, the variation or every variation
	 * with the attribute value changes, by part. JSON.
	 */
	const PRICE_RULE_META = '_pixonaut_price_rule';

	/**
	 * Product and variation meta, `always` or `never`, on one whose prices
	 * change by themselves, or never do, whatever the store's switch says;
	 * a variation's answers its product's. Absent on one that follows.
	 */
	const PRICE_AUTOMATIC_META = '_pixonaut_price_automatic';

	/**
	 * Product meta on each product with a price that follows the pricing
	 * rule: finds the products whose costs are read again, and counts the
	 * prices the rule would change. JSON.
	 */
	const PRICE_WATCH_META = '_pixonaut_price_watch';

	/**
	 * Meta on a simple product or variation whose margin is below the
	 * store's lowest margin: `new` until the daily email named it, then
	 * `reported`. Taken away once the margin is back at the floor.
	 */
	const MARGIN_LOW_META = '_pixonaut_margin_low';

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
	 * Attachment meta holding the showcase set a Pixonaut picture was drawn
	 * with; empty for one drawn with the template's defaults. A picture
	 * drawn before the plugin recorded it has none.
	 */
	const PICTURE_SHOWCASE_META = '_pixonaut_picture_showcase';

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
	 * Prefix of the option holding the number a blank's pictures are drawn
	 * under, followed by the md5 of the blank's id; moving it draws again
	 * only the pictures of the products using that blank. Absent until the
	 * blank first moves.
	 */
	const BLANK_PICTURE_GENERATION_OPTION_PREFIX = 'pixonaut_wc_blank_picture_generation_';

	/**
	 * Set once any blank's number moved, so that until then no picture
	 * reads which blanks it uses.
	 */
	const BLANK_PICTURE_GENERATIONS_MOVED_OPTION = 'pixonaut_wc_blank_picture_generations_moved';

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
	 * Option holding when Pixonaut takes the store's print size checks
	 * again, as Unix time, after it refused one for too many requests.
	 */
	const CHECKS_SLOWED_UNTIL_OPTION = 'pixonaut_wc_checks_slowed_until';

	/**
	 * Option set while every variable product's split cards wait to be
	 * written after an upgrade, until the job doing it is queued.
	 */
	const SPLIT_CARDS_BACKFILL_OPTION = 'pixonaut_wc_split_cards_backfill';

	/**
	 * Option set while every Pixonaut picture kept as a PNG waits to be
	 * written again as WebP after an upgrade, until the job doing it is queued.
	 */
	const SMALLER_PICTURES_BACKFILL_OPTION = 'pixonaut_wc_smaller_pictures_backfill';

	/**
	 * Option set while the pictures stopped jobs left unfinished wait to be
	 * deleted after an upgrade, holding the last one taken up.
	 */
	const UNFINISHED_PICTURES_BACKFILL_OPTION = 'pixonaut_wc_unfinished_pictures_backfill';

	/**
	 * Prefix of the transients caching Pixonaut catalogue answers for the admin.
	 */
	const CATALOGUE_CACHE_PREFIX = 'pixonaut_wc_catalogue_';

	/**
	 * Before a hash of a cached catalogue answer's key: the option a page view holds while it reads a new answer in place of a kept one.
	 */
	const CATALOGUE_REFRESH_HOLD_OPTION_PREFIX = 'pixonaut_wc_catalogue_refresh_hold_';

	/**
	 * Before a picture's key: the option a page view holds while it draws a
	 * picture a product page's preview shows until the designer has drawn
	 * (`StagePosters`).
	 */
	const STAGE_POSTER_HOLD_OPTION_PREFIX = 'pixonaut_wc_stage_poster_hold_';

	/**
	 * Under the uploads, the directory holding those pictures.
	 */
	const STAGE_POSTER_DIRECTORY = 'pixonaut-posters';

	/**
	 * Option holding the catalogue cache's generation, which every cached
	 * answer's key carries: raising it drops them all at once.
	 */
	const CATALOGUE_CACHE_GENERATION_OPTION = 'pixonaut_wc_catalogue_generation';

	/**
	 * Option holding the Pixonaut reads product pages made, each with when
	 * it was last read and last asked for by a page, which a job reads
	 * again before their kept copies run out (`ServerReadsWarmUp`).
	 */
	const SERVER_READS_IN_USE_OPTION = 'pixonaut_wc_server_reads_in_use';

	/**
	 * Transient set for a minute after a product page's Pixonaut read failed,
	 * under the catalogue cache's prefix so it goes with the cache.
	 */
	const SERVER_READS_PAUSE_TRANSIENT = self::CATALOGUE_CACHE_PREFIX . 'server_reads_paused';

	/**
	 * Transient holding the products whose stored configuration may name a
	 * template that is not live, for the shop to leave out.
	 */
	const NOT_LIVE_CANDIDATES_TRANSIENT = 'pixonaut_wc_not_live_candidates';

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
	 * Prefix of the options that let one request at a time handle the parcels
	 * of a WooCommerce order; the order's id follows.
	 */
	const PARCEL_LOCK_OPTION_PREFIX = 'pixonaut_wc_parcel_lock_';

	/**
	 * Prefix of the WooCommerce order meta keeping what the store did about
	 * one parcel of the order; the parcel's id follows.
	 */
	const PARCEL_META_PREFIX = '_pixonaut_parcel_';

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
	 * Option holding the ids of the global attributes whose values are listed
	 * as their own products by default only while a filter chooses one.
	 */
	const SPLIT_FILTERED_ATTRIBUTES_OPTION = 'pixonaut_wc_split_filtered_attributes';

	/**
	 * Before a product's id: the transient keeping what the shop's filters
	 * read of its variations and cards between requests.
	 */
	const FILTER_INDEX_PREFIX = 'pixonaut_wc_filter_index_';

	/**
	 * Option raised when an attribute or one of its values changes, which
	 * every kept filter index carries: raising it drops them all at once.
	 */
	const FILTER_INDEX_GENERATION_OPTION = 'pixonaut_wc_filter_index_generation';

	/**
	 * Before a hash of a listing's query: the transient keeping the
	 * Product Filters block's counts for it.
	 */
	const FILTER_COUNTS_PREFIX = 'pixonaut_wc_filter_counts_';

	/**
	 * Option holding the products and counts whose filter data a job is
	 * to read again (`FilterDataRefresh`).
	 */
	const FILTER_DATA_DUE_OPTION = 'pixonaut_wc_filter_data_due';

	/**
	 * Before what a product page keeps between requests: the transients
	 * holding its designer, its variations' data and its delivery rows,
	 * and each product's version.
	 */
	const PRODUCT_PAGE_CACHE_PREFIX = 'pixonaut_wc_product_page_';

	/**
	 * Option every kept product page read carries: a new value drops them
	 * all at once.
	 */
	const PRODUCT_PAGE_GENERATION_OPTION = 'pixonaut_wc_product_page_generation';

	/**
	 * Option holding, by archive, the version, card count and build time of
	 * the archive's index, and when the last build started.
	 */
	const ARCHIVE_INDEXES_OPTION = 'pixonaut_wc_archive_indexes';

	/**
	 * Option holding when each archive with a kept index was last opened.
	 */
	const ARCHIVE_INDEX_VISITS_OPTION = 'pixonaut_wc_archive_index_visits';

	/**
	 * Before an archive's name: the option holding its index's version and
	 * the index, compressed.
	 */
	const ARCHIVE_INDEX_BODY_PREFIX = 'pixonaut_wc_archive_index_';

	/**
	 * Option holding the shop's sort menu, as WooCommerce last showed it.
	 */
	const ARCHIVE_SORTS_OPTION = 'pixonaut_wc_archive_sorts';

	/**
	 * Option holding, by global attribute id, whether its swatches show
	 * their values' names whatever the store's setting says.
	 */
	const SWATCH_NAMES_OPTION = 'pixonaut_wc_swatch_names';

	/**
	 * Option holding, by global attribute id, the name an attribute goes by
	 * in the shop's addresses when the store gave it one of its own.
	 */
	const ATTRIBUTE_QUERY_NAMES_OPTION = 'pixonaut_wc_attribute_query_names';

	/**
	 * Option holding, by global attribute id, the blank option an attribute
	 * stands for and the option value each of its terms stands for.
	 */
	const BLANK_OPTION_LINKS_OPTION = 'pixonaut_wc_blank_option_links';

	/**
	 * Option holding, by global attribute id, the price its choices show on
	 * a product page: what an add-on adds, or a base choice's lowest price.
	 */
	const CHOICE_PRICES_OPTION = 'pixonaut_wc_choice_prices';

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
	 * Where the catalogue's bulk jobs (drawing pictures, re-checking
	 * products, and the jobs queueing them) stand in Action Scheduler's
	 * queue: behind every job at its default of 10, such as an order's,
	 * a webhook event's or a fulfilment job, which Action Scheduler claims
	 * first whatever their dates. A change in Pixonaut can queue thousands
	 * of them at once.
	 */
	const CATALOGUE_JOB_PRIORITY = 20;

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
