<?php
/**
 * The workspace settings Pixonaut shows a connected store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Closure;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStore;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreBrand;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * `GET /v1/connect` answers the workspace settings that shape
 * what the store's customers and merchant get: the brand its hosted pages
 * are drawn in and the workspace's brands it may choose from, whether proofs
 * carry a watermark, where orders ship, the minimum print resolution, and
 * the language its pages use when the store names none. The settings tab
 * shows them; they are changed in the studio, except the store's brand,
 * which the settings tab chooses too.
 *
 * The store's brand, its accent colour, the workspace's default
 * language and its slug are kept in an option, so the designer can take the accent
 * without asking Pixonaut on a product page, and the Designer language
 * setting can name the language, and the studio links the plugin builds
 * can carry the workspace (`StudioPage`): read whenever the settings tab reads the
 * connection or chooses a brand, when the store connects, and by a job
 * once a day after. Connecting adds the site's counterpart with or without
 * `www.` to the publishable key's origins too, so personalised products load
 * on both, and keeps the settings `PATCH /v1/connect` answers.
 */
final class WorkspaceSettings {

	public const OPTION = Schema::WORKSPACE_SETTINGS_OPTION;

	/**
	 * The job reading the workspace settings again.
	 */
	public const REFRESH_ACTION = 'pixonaut_wc_connection_refresh_workspace';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * How long the kept accent is used before it is read again: a day.
	 */
	public const STALE_SECONDS = 24 * 60 * 60;

	private readonly Closure $clock;

	/**
	 * @param Connection      $connection The connection, its origins and whether it is paused.
	 * @param Sdk             $api        The SDK, for `GET /v1/connect`.
	 * @param LoggerInterface $logger     Logs a read or an origin change Pixonaut refused.
	 * @param Closure|null    $clock      `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $api,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'pixonaut_wc_connection_connected', array( $this, 'connected' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_disconnected', array( self::class, 'forget' ), 10, 0 );
		add_action( self::REFRESH_ACTION, array( $this, 'refresh' ), 10, 0 );
	}

	/**
	 * Forgets the last workspace's settings, adds the site's counterpart with
	 * or without `www.` to the publishable key and keeps the settings
	 * Pixonaut answers. A refusal is logged; the card's Allow adds it later.
	 */
	public function connected(): void {
		self::forget();
		$current = $this->connection->current();
		if ( null === $current || null !== $this->connection->paused() ) {
			return;
		}
		// The slug the connect answered, kept even when the call below is
		// refused; nothing read yet, so the accent is still read again soon.
		if ( null !== $current->tenant_slug ) {
			update_option( self::OPTION, array( 'tenant_slug' => $current->tenant_slug ), false );
		}
		try {
			$this->remember( $this->connection->allow_www( true ) );
		} catch ( ApiException $e ) {
			$this->logger->warning( sprintf( 'Pixonaut did not add the site\'s own addresses to the store\'s key: %s', $e->getMessage() ), array( 'code' => $e->errorCode ) );
		}
	}

	/**
	 * The job: reads the workspace settings again.
	 */
	public function refresh(): void {
		$current = $this->connection->current();
		if ( null === $current || null !== $this->connection->paused() ) {
			return;
		}
		try {
			$this->remember( ConnectedStore::get( $this->api ) );
		} catch ( ApiException $e ) {
			$this->logger->warning( sprintf( 'Could not read the workspace settings: %s', $e->getMessage() ), array( 'code' => $e->errorCode ) );
			// Another request may have disconnected or connected again while this one waited for Pixonaut.
			if ( ! $this->still_connected( $current->connection_id ) ) {
				return;
			}
			wp_cache_delete( self::OPTION, 'options' );
			// What was read last stays, and is read again a day later rather than on every product page.
			$kept = self::kept();
			$this->keep( self::brand_id(), is_string( $kept['accent'] ?? null ) ? $kept['accent'] : null, self::default_language(), self::kept_slug() );
		}
	}

	/**
	 * Keeps the brand, its accent colour, the default language and the
	 * workspace's slug of a `GET` or `PATCH /v1/connect` answer about the
	 * connection stored now. An answer without a slug keeps the one known.
	 *
	 * @param ConnectedStore $answer The connection as Pixonaut answered it.
	 */
	public function remember( ConnectedStore $answer ): void {
		if ( ! $this->still_connected( $answer->connection_id ) ) {
			return;
		}
		$shown = self::shown( $answer );
		$slug  = $answer->tenant_slug;
		$this->keep( $shown['brand_id'], $shown['accent'], self::language_of( $answer ), '' !== $slug ? $slug : self::kept_slug() );
	}

	/**
	 * Whether an answer is about the connection stored now: one read while
	 * the store disconnected or connected again, in another request, is
	 * about one that is gone, and is not kept.
	 *
	 * @param string $connection_id The connection Pixonaut answered about.
	 */
	private function still_connected( string $connection_id ): bool {
		// Another request may have changed the connection while this one waited for Pixonaut.
		wp_cache_delete( Schema::CONNECTION_OPTION, 'options' );
		$current = $this->connection->current()?->connection_id;
		return null !== $current && $connection_id === $current;
	}

	/**
	 * @param string|null $brand_id The brand the store's pages are drawn in, read now.
	 * @param string|null $accent   The accent colour to keep, read now.
	 * @param string|null $language The workspace's default language, read now.
	 * @param string|null $slug     The workspace's slug.
	 */
	private function keep( ?string $brand_id, ?string $accent, ?string $language, ?string $slug ): void {
		update_option(
			self::OPTION,
			array(
				'brand_id'         => $brand_id,
				'accent'           => $accent,
				'default_language' => $language,
				'tenant_slug'      => $slug,
				'read_at'          => ( $this->clock )(),
			),
			false
		);
	}

	/**
	 * @return array<mixed> What was kept; empty when nothing was.
	 */
	private static function kept(): array {
		$kept = get_option( self::OPTION );
		return is_array( $kept ) ? $kept : array();
	}

	/**
	 * Forgets what was kept: the store disconnected, or connected again.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The brand the store's hosted pages are drawn in as last read: its own,
	 * else the workspace's default; null when none was read, or the
	 * workspace has no brand yet.
	 */
	public static function brand_id(): ?string {
		$brand_id = self::kept()['brand_id'] ?? null;
		return is_string( $brand_id ) ? $brand_id : null;
	}

	/**
	 * The accent colour of the store's brand as last read, `#rrggbb`, or
	 * null when it has none or none was read. One read more than
	 * STALE_SECONDS ago, or none at all, queues the job reading it again.
	 *
	 * @param int|null $now Unix time; `time()` when null.
	 */
	public static function accent( ?int $now = null ): ?string {
		self::read_again_when_stale( $now );
		$kept = self::kept();
		return is_string( $kept['accent'] ?? null ) ? $kept['accent'] : null;
	}

	/**
	 * Queues the job reading the workspace settings again when none were
	 * read, or the last read was more than STALE_SECONDS ago, failed or not.
	 *
	 * @param int|null $now Unix time; `time()` when null.
	 */
	private static function read_again_when_stale( ?int $now = null ): void {
		$kept    = self::kept();
		$read_at = is_int( $kept['read_at'] ?? null ) ? $kept['read_at'] : null;
		if ( ( null === $read_at || ( $now ?? time() ) - $read_at > self::STALE_SECONDS ) && function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::REFRESH_ACTION, null, self::GROUP ) ) {
			as_enqueue_async_action( self::REFRESH_ACTION, array(), self::GROUP );
		}
	}

	/**
	 * The workspace's slug, which every studio page sits under
	 * (`/w/<slug>/…`): the one the connect answered, else the one last read.
	 * None known queues the job reading the workspace settings again, for a
	 * store connected before Pixonaut answered one, unless they were read
	 * less than STALE_SECONDS ago: a read that failed, or answered none, is
	 * not repeated on every screen.
	 *
	 * @param int|null $now Unix time; `time()` when null.
	 */
	public static function tenant_slug( ?int $now = null ): ?string {
		$slug = self::kept_slug();
		if ( null === $slug ) {
			self::read_again_when_stale( $now );
		}
		return $slug;
	}

	/**
	 * The workspace's slug as kept; null when none is.
	 */
	private static function kept_slug(): ?string {
		$slug = self::kept()['tenant_slug'] ?? null;
		return is_string( $slug ) && '' !== $slug ? $slug : null;
	}

	/**
	 * The workspace's default language as last read, one of
	 * `DesignerLanguage::languages()`; null when none was read.
	 */
	public static function default_language(): ?string {
		return self::known_language( self::kept()['default_language'] ?? null );
	}

	/**
	 * The default language a `GET` or `PATCH /v1/connect` answer names.
	 *
	 * @param ConnectedStore $answer The connection as Pixonaut answered it.
	 */
	private static function language_of( ConnectedStore $answer ): ?string {
		return self::known_language( $answer->default_language );
	}

	/**
	 * @param mixed $language A language code, as read.
	 * @return string|null The code when it is one of `DesignerLanguage::languages()`, else null.
	 */
	private static function known_language( mixed $language ): ?string {
		return is_string( $language ) && null !== DesignerLanguage::name( $language ) ? $language : null;
	}

	/**
	 * What the settings tab shows of a `GET` or `PATCH /v1/connect` answer.
	 *
	 * @param ConnectedStore $answer The connection as Pixonaut answered it.
	 *
	 * @return array{brand_id: string|null, chosen_brand_id: string|null, brands: list<array{id: string, name: string, is_default: bool}>, logo_url: string|null, accent: string|null, watermark: bool, deliver_to: string|null, min_print_dpi: float, default_language: string|null}
	 *         `brand_id` is the brand the store's hosted pages are drawn in, its own or else the workspace's default, null while the workspace has none, and `logo_url` and `accent` are that brand's; `chosen_brand_id` is the store's own, null while it follows the workspace's default. `brands` are the workspace's brands the store may choose from, the default first. `default_language` is the workspace's default language, named in that language (`Deutsch`), null for one the plugin does not know. `deliver_to` is the name of the address every order ships to, null when orders ship to their customer.
	 */
	public static function shown( ConnectedStore $answer ): array {
		$accent = $answer->branding->accent_color;
		return array(
			'brand_id'         => $answer->branding->brand_id,
			'chosen_brand_id'  => $answer->brand_id,
			'brands'           => array_map(
				static fn ( ConnectedStoreBrand $brand ): array => array(
					'id'         => $brand->id,
					'name'       => $brand->name,
					'is_default' => $brand->is_default,
				),
				$answer->brands
			),
			'logo_url'         => $answer->branding->logo_url,
			'accent'           => null !== $accent && 1 === preg_match( '/^#[0-9a-f]{6}$/i', $accent ) ? strtolower( $accent ) : null,
			'watermark'        => $answer->proof_watermark,
			'deliver_to'       => $answer->deliver_orders_to?->name,
			'min_print_dpi'    => $answer->printing->min_print_dpi,
			'default_language' => DesignerLanguage::name( self::language_of( $answer ) ),
		);
	}
}
