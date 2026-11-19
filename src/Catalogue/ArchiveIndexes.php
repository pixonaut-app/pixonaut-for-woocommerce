<?php
/**
 * The indexes of the shop's product archives: which are kept, building them, and when.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use ActionScheduler;
use ActionScheduler_Store;
use DateTimeInterface;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Schema;
use WP_Post;
use WP_Term;

/**
 * A theme that filters an archive in the browser declares
 * `add_theme_support( 'pixonaut-archive-index' )`. For such a theme, each
 * product archive a shopper opens (the shop page, a product category or
 * tag) gets an index of its cards (`ArchiveIndexBuild`), built in the
 * background by an Action Scheduler job, never on a shopper's request, and
 * kept compressed in an option of its own. The first visit to an archive
 * queues its build; the archive works as it always has until then.
 *
 * Whatever an index is worked out from changing (a product or variation
 * written, its meta, terms or status, an attribute or one of its values,
 * the store's currency, tax, stock or sorting settings, the plugin's
 * listing settings) queues one build of every kept archive
 * `DELAY_SECONDS` later, however many changes come meanwhile, and no
 * sooner than `MIN_INTERVAL_SECONDS` after the last build started. A build
 * gives an index a new version only when what it holds changed; an index
 * older than a day is built again on the next visit. An archive nobody
 * opened for `UNUSED_SECONDS` is no longer kept.
 */
final class ArchiveIndexes {

	/**
	 * What a theme declares to have the archives' indexes built.
	 */
	public const THEME_SUPPORT = 'pixonaut-archive-index';

	/**
	 * Action Scheduler hook building every kept archive's index.
	 */
	public const ACTION = 'pixonaut_wc_catalogue_archive_indexes';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * How long after a change the indexes are built, so a run of changes,
	 * such as a product saved with its variations, builds them once.
	 */
	public const DELAY_SECONDS = 60;

	/**
	 * How old an index may grow before a visit builds it again: a day.
	 */
	public const STALE_SECONDS = 86400;

	/**
	 * How long an archive nobody opens is kept: thirty days.
	 */
	public const UNUSED_SECONDS = 2592000;

	/**
	 * Between an index's version and its compressed body in the option keeping them.
	 */
	private const BODY_SEPARATOR = ':';

	/**
	 * How many hex digits of the index's hash its version keeps.
	 */
	private const VERSION_LENGTH = 12;

	/**
	 * The strongest gzip compression: an index is compressed once and sent many times.
	 */
	private const GZIP_LEVEL = 9;

	/**
	 * The least time between the starts of two builds: a store busy with
	 * changes, such as pictures being drawn, builds every few minutes at most.
	 */
	public const MIN_INTERVAL_SECONDS = 300;

	/**
	 * Where a build stands in Action Scheduler's queue: ahead of the jobs
	 * queued at its default of 10 and of the pictures' redraws
	 * (`Schema::CATALOGUE_JOB_PRIORITY`). A store may hold hundreds of
	 * those, a minute each, while the index names pictures they replace
	 * and delete.
	 */
	public const PRIORITY = 5;

	/**
	 * How long one build may run: an archive of a few hundred products
	 * reads each of their variations.
	 */
	private const TIME_LIMIT_SECONDS = 600;

	/**
	 * The shop page's archive.
	 */
	public const SHOP = 'shop';

	/**
	 * The taxonomies whose terms have an archive with an index.
	 */
	public const TAXONOMIES = array( 'product_cat', 'product_tag' );

	/**
	 * Between a taxonomy and a term id in an archive's name.
	 */
	private const NAME_SEPARATOR = '-';

	/**
	 * Meta a product's or variation's index never reads, written often.
	 */
	private const IGNORED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/**
	 * The store's options an index is worked out from.
	 */
	private const OPTIONS = array(
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'woocommerce_calc_taxes',
		'woocommerce_prices_include_tax',
		'woocommerce_tax_display_shop',
		'woocommerce_hide_out_of_stock_items',
		'woocommerce_default_catalog_orderby',
		'woocommerce_placeholder_image',
		'permalink_structure',
		'woocommerce_permalinks',
		Schema::SETTINGS_OPTION,
		Schema::SPLIT_ATTRIBUTES_OPTION,
		Schema::SPLIT_FILTERED_ATTRIBUTES_OPTION,
		Schema::FILTER_INDEX_GENERATION_OPTION,
		Schema::ATTRIBUTE_QUERY_NAMES_OPTION,
		Schema::ARCHIVE_SORTS_OPTION,
	);

	/**
	 * Arguments of the meta write actions read: the meta row, the post, the key.
	 */
	private const META_ACTION_ARGS = 3;

	/**
	 * Arguments of `set_object_terms` read: the post, the terms, their term taxonomy ids, the taxonomy.
	 */
	private const TERMS_ACTION_ARGS = 4;

	/**
	 * Arguments of the term actions read: the term, its term taxonomy id, the taxonomy.
	 */
	private const TERM_ACTION_ARGS = 3;

	/**
	 * Arguments of `transition_post_status` read: the new status, the old, the post.
	 */
	private const STATUS_ACTION_ARGS = 3;

	/**
	 * Whether a build runs now: what it reads writes nothing it is worked out from.
	 *
	 * @var bool
	 */
	private static bool $building = false;

	public static function register(): void {
		add_action( self::ACTION, array( self::class, 'build_all' ), 10, 0 );
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_delete_product', 'woocommerce_delete_product_variation', 'woocommerce_trash_product', 'woocommerce_trash_product_variation', 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted', 'woocommerce_tax_rate_added', 'woocommerce_tax_rate_updated', 'woocommerce_tax_rate_deleted', 'switch_theme' ) as $action ) {
			add_action( $action, array( self::class, 'changed' ), 10, 0 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $action ) {
			add_action( $action, array( self::class, 'meta_written' ), 10, self::META_ACTION_ARGS );
		}
		add_action( 'set_object_terms', array( self::class, 'terms_set' ), 10, self::TERMS_ACTION_ARGS );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $action ) {
			add_action( $action, array( self::class, 'term_changed' ), 10, self::TERM_ACTION_ARGS );
		}
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, self::STATUS_ACTION_ARGS );
		foreach ( self::OPTIONS as $option ) {
			add_action( "update_option_{$option}", array( self::class, 'changed' ), 10, 0 );
			add_action( "add_option_{$option}", array( self::class, 'changed' ), 10, 0 );
		}
	}

	/**
	 * Whether the active theme filters archives with their indexes.
	 */
	public static function wanted(): bool {
		return current_theme_supports( self::THEME_SUPPORT );
	}

	/**
	 * The name of an archive's index: `shop`, or the taxonomy and the term's id.
	 *
	 * @param WP_Term|null $term The archive's term; null for the shop page.
	 */
	public static function name( ?WP_Term $term ): ?string {
		if ( null === $term ) {
			return self::SHOP;
		}
		return in_array( $term->taxonomy, self::TAXONOMIES, true ) ? $term->taxonomy . self::NAME_SEPARATOR . $term->term_id : null;
	}

	/**
	 * Whether a string names an archive.
	 *
	 * @param string $archive A name.
	 */
	public static function is_name( string $archive ): bool {
		return 1 === preg_match( self::pattern(), $archive );
	}

	/**
	 * What an archive's name looks like, as a regular expression without delimiters.
	 */
	public static function name_pattern(): string {
		return self::SHOP . '|(?:' . implode( '|', self::TAXONOMIES ) . ')' . self::NAME_SEPARATOR . '[1-9]\d*';
	}

	/**
	 * `name_pattern()`, whole, with delimiters.
	 */
	private static function pattern(): string {
		return '/^(?:' . self::name_pattern() . ')$/';
	}

	/**
	 * The tax query an archive adds to the shop's: none for the shop page,
	 * its term with the term's children for a category or tag. Null for a
	 * name that is not an archive's, or whose term is gone.
	 *
	 * @param string $archive An archive's name.
	 * @return array<int|string, mixed>|null
	 */
	public static function tax_query( string $archive ): ?array {
		if ( self::SHOP === $archive ) {
			return array();
		}
		if ( ! self::is_name( $archive ) ) {
			return null;
		}
		list( $taxonomy, $term_id ) = explode( self::NAME_SEPARATOR, $archive, 2 );
		$term                       = get_term( (int) $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		return array(
			array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => array( $term->term_id ),
				'include_children' => true,
			),
		);
	}

	/**
	 * What is kept of an archive's index: its version, how many cards it
	 * shows unfiltered, and when it was built; null before it was.
	 *
	 * @param string $archive An archive's name.
	 * @return array{version: string, count: int, built: int}|null
	 */
	public static function current( string $archive ): ?array {
		$entry = self::state()['built'][ $archive ] ?? null;
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * An archive's index as kept: its version and its JSON compressed with
	 * gzip, read together, so the two always belong to each other; null
	 * before it is built.
	 *
	 * @param string $archive An archive's name.
	 * @return array{version: string, gzip: string}|null
	 */
	public static function body( string $archive ): ?array {
		$stored = get_option( Schema::ARCHIVE_INDEX_BODY_PREFIX . $archive );
		$parts  = is_string( $stored ) ? explode( self::BODY_SEPARATOR, $stored, 2 ) : array();
		if ( 2 !== count( $parts ) || '' === $parts[0] ) {
			return null;
		}
		$gzip = base64_decode( $parts[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the index's compressed bytes, kept as text.
		return false === $gzip || '' === $gzip ? null : array(
			'version' => $parts[0],
			'gzip'    => $gzip,
		);
	}

	/**
	 * Notes that a shopper opened an archive, and queues its build when it
	 * has no index yet or its index is old.
	 *
	 * @param string $archive An archive's name.
	 */
	public static function visited( string $archive ): void {
		$now    = time();
		$visits = self::visits();
		if ( $now - (int) ( $visits[ $archive ] ?? 0 ) > self::STALE_SECONDS ) {
			$visits[ $archive ] = $now;
			update_option( Schema::ARCHIVE_INDEX_VISITS_OPTION, $visits, true );
		}
		$built = self::current( $archive );
		if ( null === $built ) {
			self::queue( 0 );
		} elseif ( $now - $built['built'] > self::STALE_SECONDS ) {
			self::queue( self::DELAY_SECONDS );
		}
	}

	/**
	 * Queues a build of every kept archive's index after a change.
	 */
	public static function changed(): void {
		if ( ! self::$building && array() !== self::visits() ) {
			self::queue( self::DELAY_SECONDS );
		}
	}

	/**
	 * Queues a build when a product's or variation's meta is written.
	 *
	 * @param mixed $meta_id   The meta row; unused.
	 * @param mixed $object_id The post.
	 * @param mixed $meta_key  The key written.
	 */
	public static function meta_written( mixed $meta_id, mixed $object_id, mixed $meta_key ): void {
		unset( $meta_id );
		if ( is_string( $meta_key ) && ! in_array( $meta_key, self::IGNORED_META, true ) && is_numeric( $object_id ) && self::is_product( (int) $object_id ) ) {
			self::changed();
		}
	}

	/**
	 * Queues a build when a product's categories, tags or attributes' values are set.
	 *
	 * @param mixed $object_id The post.
	 * @param mixed $terms     The terms; unused.
	 * @param mixed $tt_ids    Their term taxonomy ids; unused.
	 * @param mixed $taxonomy  The taxonomy.
	 */
	public static function terms_set( mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy ): void {
		unset( $terms, $tt_ids );
		if ( is_string( $taxonomy ) && is_numeric( $object_id ) && self::is_product( (int) $object_id ) ) {
			self::changed();
		}
	}

	/**
	 * Queues a build when a category, a tag or a value of an attribute is
	 * added, renamed, moved or deleted.
	 *
	 * @param mixed $term_id  The term; unused.
	 * @param mixed $tt_id    Its term taxonomy id; unused.
	 * @param mixed $taxonomy Its taxonomy.
	 */
	public static function term_changed( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		unset( $term_id, $tt_id );
		if ( is_string( $taxonomy ) && ( in_array( $taxonomy, self::TAXONOMIES, true ) || taxonomy_is_product_attribute( $taxonomy ) ) ) {
			self::changed();
		}
	}

	/**
	 * Queues a build when a product is published, hidden or trashed.
	 *
	 * @param mixed $new_status The post's status now.
	 * @param mixed $old_status Its status before.
	 * @param mixed $post       The post.
	 */
	public static function status_changed( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( $new_status !== $old_status && $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			self::changed();
		}
	}

	/**
	 * Builds the index of every kept archive, drops those of archives nobody
	 * opened for long, and keeps a new version of each whose index changed.
	 *
	 * An archive whose build had a database error keeps its last index, and
	 * the build is queued again: a failed read may have found too few
	 * products. A change written while the build runs queues the next one
	 * (`queue()` counts only builds waiting to start), as does an archive
	 * first opened meanwhile.
	 */
	public static function build_all(): void {
		if ( ! self::wanted() ) {
			return;
		}
		wc_set_time_limit( self::TIME_LIMIT_SECONDS );
		self::$building = true;
		try {
			$state        = self::state();
			$now          = time();
			$state['ran'] = $now;
			update_option( Schema::ARCHIVE_INDEXES_OPTION, $state, true );
			$terms  = new ArchiveTerms();
			$build  = new ArchiveIndexBuild( new ArchiveCards( $terms ), $terms );
			$hidden = ( new NotLiveProducts() )->hidden_ids();
			$failed = array();
			$unused = array();
			foreach ( self::visits() as $archive => $seen ) {
				$archive = (string) $archive;
				$errors  = self::errors();
				$built   = $now - (int) $seen > self::UNUSED_SECONDS ? null : $build->build( $archive, $hidden );
				if ( self::errors() !== $errors ) {
					$failed[] = $archive;
					continue;
				}
				if ( null === $built ) {
					$unused[] = $archive;
					unset( $state['built'][ $archive ] );
					delete_option( Schema::ARCHIVE_INDEX_BODY_PREFIX . $archive );
					continue;
				}
				$kept = self::keep( $archive, $built['index'], $built['count'], $now );
				if ( null === $kept ) {
					$failed[] = $archive;
					continue;
				}
				$state['built'][ $archive ] = $kept;
			}
			update_option( Schema::ARCHIVE_INDEXES_OPTION, $state, true );
			self::forget_visits( $unused );
		} finally {
			self::$building = false;
		}
		if ( array() !== $failed ) {
			( new WcLogger() )->warning( 'The index of some product archives could not be built; their last index stays, and the build runs again.', array( 'archives' => $failed ) );
			self::queue( self::DELAY_SECONDS );
		}
	}

	/**
	 * Keeps an archive's index, under a new version only when it changed,
	 * its version stored with it. Null when it could not be stored: the
	 * last index stays.
	 *
	 * @param string               $archive The archive.
	 * @param array<string, mixed> $index   Its index, without a version.
	 * @param int                  $count   How many cards it shows unfiltered.
	 * @param int                  $now     The time.
	 * @return array{version: string, count: int, built: int}|null
	 */
	private static function keep( string $archive, array $index, int $count, int $now ): ?array {
		$json    = (string) wp_json_encode( $index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$version = substr( md5( $json ), 0, self::VERSION_LENGTH );
		if ( ( self::body( $archive )['version'] ?? null ) !== $version ) {
			$body   = (string) wp_json_encode( array( 'version' => $version ) + $index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$stored = $version . self::BODY_SEPARATOR . base64_encode( (string) gzencode( $body, self::GZIP_LEVEL ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- compressed bytes kept in an option, which holds text.
			update_option( Schema::ARCHIVE_INDEX_BODY_PREFIX . $archive, $stored, false );
			if ( ( self::body( $archive )['version'] ?? null ) !== $version ) {
				return null;
			}
		}
		return array(
			'version' => $version,
			'count'   => $count,
			'built'   => $now,
		);
	}

	/**
	 * Queues the build, unless one is waiting to start already; a build
	 * running now does not count, as it may have read what changed. A
	 * waiting build queued at another priority, as before `PRIORITY`, is
	 * queued again at it for the same time, so it no longer waits behind
	 * the picture jobs.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function queue( int $delay ): void {
		if ( ! self::wanted() || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$waiting = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		$at      = max( time() + $delay, self::state()['ran'] + self::MIN_INTERVAL_SECONDS );
		if ( array() !== $waiting ) {
			$action = ActionScheduler::store()->fetch_action( (string) reset( $waiting ) );
			if ( $action->get_priority() === self::PRIORITY ) {
				return;
			}
			$due = $action->get_schedule()->get_date();
			$at  = $due instanceof DateTimeInterface ? $due->getTimestamp() : $at;
			as_unschedule_action( self::ACTION, array(), self::GROUP );
		}
		as_schedule_single_action( $at, self::ACTION, array(), self::GROUP, false, self::PRIORITY );
	}

	/**
	 * How many database errors this request has had.
	 */
	private static function errors(): int {
		global $EZSQL_ERROR;
		return is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
	}

	/**
	 * Whether a post is a product or a variation.
	 *
	 * @param int $post_id A post.
	 */
	private static function is_product( int $post_id ): bool {
		return in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true );
	}

	/**
	 * When each kept archive was last opened, by name. Kept apart from the
	 * built indexes, so a visit during a build is never written over.
	 *
	 * @return array<string, int>
	 */
	private static function visits(): array {
		$visits = get_option( Schema::ARCHIVE_INDEX_VISITS_OPTION, array() );
		/** @var array<string, int> */
		return is_array( $visits ) ? $visits : array();
	}

	/**
	 * Stops keeping archives nobody opened for long, unless opened since.
	 *
	 * @param string[] $archives Archive names.
	 */
	private static function forget_visits( array $archives ): void {
		if ( array() === $archives ) {
			return;
		}
		$visits = self::visits();
		$now    = time();
		foreach ( $archives as $archive ) {
			if ( $now - (int) ( $visits[ $archive ] ?? 0 ) > self::UNUSED_SECONDS ) {
				unset( $visits[ $archive ] );
			}
		}
		update_option( Schema::ARCHIVE_INDEX_VISITS_OPTION, $visits, true );
	}

	/**
	 * The built indexes, and when the last build started.
	 *
	 * @return array{built: array<string, array{version: string, count: int, built: int}>, ran: int}
	 */
	private static function state(): array {
		$state = get_option( Schema::ARCHIVE_INDEXES_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		/** @var array{built: array<string, array{version: string, count: int, built: int}>, ran: int} */
		return array(
			'built' => is_array( $state['built'] ?? null ) ? $state['built'] : array(),
			'ran'   => is_int( $state['ran'] ?? null ) ? $state['ran'] : 0,
		);
	}
}
