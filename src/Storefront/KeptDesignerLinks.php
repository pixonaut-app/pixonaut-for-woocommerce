<?php
/**
 * The designer links kept for each product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\DesignerLinks;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use RuntimeException;
use WC_Product;

/**
 * A product or variation on the button layout has one designer link per
 * language, minted without a `state`, a lifetime or a design limit, so it
 * works until it is withdrawn. It is kept in the product's meta with a hash
 * of the body it was minted with, and reused while that body is unchanged;
 * a changed body mints a new link and withdraws the kept one.
 *
 * One request at a time mints a product's link (`lock()`); another that
 * needs it meanwhile waits for it to be kept, and takes it. Each link is
 * named after its product, and Pixonaut keeps a name unique among the
 * workspace's working links: a name another link holds mints the link
 * unnamed.
 *
 * Saving a product mints its links, so a refusal shows on the product's
 * screen rather than to a customer; turning personalisation off withdraws
 * them; `DesignerLinkWithdrawals` asks again for one Pixonaut could not be
 * asked to withdraw. A link belongs to the connection it was minted under:
 * disconnecting forgets every one, since Pixonaut withdraws them, and one
 * kept for another connection, even to the same workspace, is never used.
 *
 * @phpstan-type Kept array{id: string, url: string, hash: string, name: string, template: string, connection: string}
 */
final class KeptDesignerLinks {

	/**
	 * The site's locale while `WPLANG` is empty, as WordPress has it.
	 */
	private const DEFAULT_SITE_LOCALE = 'en_US';

	public const META = Schema::DESIGNER_LINKS_META;

	/**
	 * What is kept of each link.
	 */
	private const FIELDS = array( 'id', 'url', 'hash', 'name', 'template', 'connection' );

	/**
	 * The longest name Pixonaut takes for a link.
	 */
	public const NAME_MAX_LENGTH = 80;

	/**
	 * After the product panel saved the configuration, at the default 10.
	 */
	private const SAVE_PRIORITY = 20;

	/**
	 * Requests a mint may send: free the old link's name, mint, mint again
	 * unnamed, withdraw the old link.
	 */
	private const REQUESTS_PER_MINT = 4;

	/**
	 * After this long a lock is taken to belong to a request that died:
	 * each of a mint's requests timing out.
	 */
	public const LOCK_SECONDS = self::REQUESTS_PER_MINT * Client::TIMEOUT_SECONDS;

	/**
	 * How long a request waits for another's mint of the same product's link
	 * before minting its own: a customer pressing Personalise is waiting.
	 */
	public const WAIT_SECONDS = 5;

	/**
	 * Between two looks at the kept links while waiting: a quarter of a second.
	 */
	private const WAIT_STEP_MICROSECONDS = 250_000;

	private const MICROSECONDS_PER_SECOND = 1_000_000;

	private readonly Closure $sleep;

	private readonly Closure $clock;

	/**
	 * @param DesignerLinks           $api         Mints, renames and lists designer links.
	 * @param DesignerLinkWithdrawals $withdrawals Withdraws the links no longer kept, asking again for those it could not.
	 * @param Connection              $connection  The connection links are minted under.
	 * @param Settings                $settings    The default layout, the designer's language, its print-area frame and its form layout.
	 * @param Notices                 $notices     Says on the product's screen why Pixonaut refused its link.
	 * @param Logger                  $logger      Logs a link that could not be renamed or checked.
	 * @param Closure|null            $sleep       `fn( int $microseconds ): void`; `usleep()` when null.
	 * @param Closure|null            $clock       `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly DesignerLinks $api,
		private readonly DesignerLinkWithdrawals $withdrawals,
		private readonly Connection $connection,
		private readonly Settings $settings,
		private readonly Notices $notices,
		private readonly Logger $logger,
		?Closure $sleep = null,
		?Closure $clock = null,
	) {
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'woocommerce_process_product_meta', array( $this, 'saved' ), self::SAVE_PRIORITY );
		add_action( 'woocommerce_ajax_save_product_variations', array( $this, 'saved' ) );
		add_action( 'before_delete_post', array( $this, 'deleting' ) );
		add_action( 'pixonaut_wc_connection_disconnected', array( $this, 'forget_all' ), 10, 0 );
	}

	/**
	 * The notice saying why Pixonaut refused a product's link.
	 *
	 * @param int $product_id The product or variation.
	 */
	public static function notice_key( int $product_id ): string {
		return 'product_' . $product_id . '_designer_link';
	}

	/**
	 * Where the hosted designer sends the customer back to: the product and
	 * variation the link was minted for ride in its query, which Pixonaut
	 * keeps.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public static function return_url( WC_Product $product ): string {
		$is_variation = $product->is_type( 'variation' );
		return add_query_arg(
			array(
				PersonaliseButton::RETURN_ARG    => PersonaliseButton::RETURN_VALUE,
				PersonaliseButton::PRODUCT_ARG   => $is_variation ? $product->get_parent_id() : $product->get_id(),
				PersonaliseButton::VARIATION_ARG => $is_variation ? $product->get_id() : 0,
			),
			home_url( '/' )
		);
	}

	/**
	 * The address of a product's designer link in a language: the kept one
	 * while the body it would be minted with is unchanged, else a new one,
	 * which replaces it. While another request mints it, waits for that one.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 * @param string        $locale  The request's WordPress locale, e.g. `sv_SE`; the Designer language setting may choose another language.
	 *
	 * @return string|null Null when the store is not connected, the product sells no variant of its blank, or its sides cannot be sent.
	 *
	 * @throws ApiError When Pixonaut would not mint the link, or could not be reached.
	 */
	public function url_for( WC_Product $product, ProductConfig $config, string $locale ): ?string {
		return $this->kept_for( $product, $config, $locale )['url'] ?? null;
	}

	/**
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 * @param string        $locale  WordPress's locale.
	 *
	 * @return array<string, string>|null
	 * @phpstan-return Kept|null
	 *
	 * @throws ApiError When Pixonaut would not mint the link, or could not be reached.
	 */
	private function kept_for( WC_Product $product, ProductConfig $config, string $locale ): ?array {
		$connection = $this->connection_id();
		$language   = DesignerLanguage::resolve( $this->settings, $locale );
		$key        = self::key( $language );
		$variant    = null === $config->blank_id ? null : ProductVariant::sold_on( $product, $config );
		$link       = DesignerLinkBody::for_config(
			$config,
			self::return_url( $product ),
			$language,
			$variant,
			true === $this->settings->get( Settings::PRINT_AREA_FRAME ),
			FormLayout::resolve( $config, $this->settings )
		);
		// Without a variant of its blank the product cannot be bought, and a link minted now would be replaced once it has one.
		if ( null === $connection || null === $link || ( null !== $config->blank_id && null === $variant ) ) {
			return null;
		}

		/**
		 * Filters the body of a product's designer link.
		 *
		 * The link is minted once and kept until the body changes: the
		 * filter runs whenever a customer presses Personalise and whenever
		 * the product is saved, and a body that differs from the kept
		 * link's mints a new link. It carries no
		 * `state`, since every customer opens the same link; the product and
		 * variation ride in `returnUrl`'s query. Pixonaut checks every proof
		 * against the product's configuration when it is added to the cart,
		 * so a template, blank, placement or print size changed here is
		 * refused there.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $body    One side's `blankId`, `placementId`, `views` (when the product narrows them), `variants` and `initialVariant` (the variant it sells), `initialView` (the product's starting view), `printSizes`, `selectedPrintSizeIndex` (the size it starts on) at the top level, or 2–10 under `sides` with `key`, `label` and `templateId` when it is not the link's; `returnUrl`, `locale`, `printAreaFrame` (`true`, when the store frames the print area), and `layout` (`page` or `steps`, when the product or store fixes the form's layout).
		 * @param WC_Product           $product The product or variation.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_storefront_designer_link', function ( $body ) {
		 *     $body['locale'] = 'sv';
		 *     return $body;
		 * } );
		 */
		$body       = (array) apply_filters( 'pixonaut_wc_storefront_designer_link', $link->body, $product );
		$hash       = hash( 'sha256', (string) wp_json_encode( array( $link->template_base_id, $body ) ) );
		$product_id = $product->get_id();
		$kept       = self::kept( $product_id, $connection )[ $key ] ?? null;
		if ( null !== $kept && $hash === $kept['hash'] ) {
			return $kept;
		}
		$locked = $this->lock( $product_id );
		if ( ! $locked ) {
			$minted = $this->wait( $product_id, $connection, $key, $hash );
			if ( null !== $minted ) {
				return $minted;
			}
			// The other request ended without the link, or is still at it: mint it here.
			$locked = $this->lock( $product_id );
		}
		try {
			// Another request may have kept the link since this one read it.
			wp_cache_delete( (string) $product_id, 'post_meta' );
			$kept = self::kept( $product_id, $connection )[ $key ] ?? null;
			if ( null !== $kept && $hash === $kept['hash'] ) {
				return $kept;
			}
			return $this->mint( $product, $key, $link->template_base_id, $body, $hash, $connection, $kept );
		} finally {
			if ( $locked ) {
				$this->unlock( $product_id );
			}
		}
	}

	/**
	 * Takes the right to mint a product's links. `add_option()` inserts or
	 * fails atomically, so two requests never both hold it; a lock older
	 * than LOCK_SECONDS is taken over.
	 *
	 * @param int $product_id The product or variation.
	 */
	public function lock( int $product_id ): bool {
		$option = self::lock_option( $product_id );
		$now    = ( $this->clock )();
		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}
		$held_since = get_option( $option );
		if ( is_numeric( $held_since ) && $now - (int) $held_since < self::LOCK_SECONDS ) {
			return false;
		}
		delete_option( $option );
		return add_option( $option, (string) $now, '', false );
	}

	/**
	 * @param int $product_id The product or variation.
	 */
	public function unlock( int $product_id ): void {
		delete_option( self::lock_option( $product_id ) );
	}

	/**
	 * Waits for another request's mint of a product's link, up to WAIT_SECONDS.
	 *
	 * @param int    $product_id The product or variation.
	 * @param string $connection The connection it is minted under.
	 * @param string $language   The link's language, or `DesignerLanguage::WORKSPACE_DEFAULT` for none.
	 * @param string $hash       The hash of the body it must be minted with.
	 *
	 * @return array<string, string>|null The link, or null when it was not kept in time or the mint ended without it.
	 * @phpstan-return Kept|null
	 */
	private function wait( int $product_id, string $connection, string $language, string $hash ): ?array {
		$option = self::lock_option( $product_id );
		for ( $waited = 0; $waited < self::WAIT_SECONDS * self::MICROSECONDS_PER_SECOND; $waited += self::WAIT_STEP_MICROSECONDS ) {
			( $this->sleep )( self::WAIT_STEP_MICROSECONDS );
			// The other request writes to the database; drop what this request remembers of it.
			wp_cache_delete( (string) $product_id, 'post_meta' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( $option, 'options' );
			$kept = self::kept( $product_id, $connection )[ $language ] ?? null;
			if ( null !== $kept && $hash === $kept['hash'] ) {
				return $kept;
			}
			if ( false === get_option( $option ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Mints a product's link and keeps it in place of the one it replaces,
	 * which gives up its name first and is withdrawn after.
	 *
	 * @param WC_Product           $product    The product or variation.
	 * @param string               $language   The link's language, or `DesignerLanguage::WORKSPACE_DEFAULT` for none.
	 * @param string               $template   The template the link is minted for.
	 * @param array<string, mixed> $body       The link's body.
	 * @param string               $hash       The body's hash.
	 * @param string               $connection The connection it is minted under.
	 * @param array<string, mixed> $replaced   The kept link it replaces, or null.
	 * @phpstan-param Kept|null $replaced
	 *
	 * @return array<string, string>
	 * @phpstan-return Kept
	 *
	 * @throws ApiError When Pixonaut would not mint it, or could not be reached.
	 */
	private function mint( WC_Product $product, string $language, string $template, array $body, string $hash, string $connection, ?array $replaced ): array {
		$name = self::name( $product, $language );
		if ( null !== $replaced && '' !== $replaced['name'] ) {
			$this->rename( $replaced['id'], null );
		}
		try {
			$made = $this->api->create( $template, array( 'name' => $name ) + $body );
		} catch ( ApiError $error ) {
			if ( ErrorCodes::SHARE_LINK_NAME_TAKEN !== $error->error_code ) {
				throw $error;
			}
			// A request that minted without the lock may have kept the same link a moment ago.
			wp_cache_delete( (string) $product->get_id(), 'post_meta' );
			$raced = self::kept( $product->get_id(), $connection )[ $language ] ?? null;
			if ( null !== $raced && $hash === $raced['hash'] ) {
				return $raced;
			}
			$this->logger->warning(
				sprintf( 'Another Pixonaut link holds the name "%s", so the designer link of product %d has no name.', $name, $product->get_id() ),
				array( 'holder' => $error->details['holderId'] ?? null )
			);
			$name = '';
			$made = $this->api->create( $template, $body );
		}
		$kept = array(
			'id'         => $made['id'],
			'url'        => $made['url'],
			'hash'       => $hash,
			'name'       => $name,
			'template'   => $template,
			'connection' => $connection,
		);
		$this->store( $product->get_id(), array( $language => $kept ) + self::kept( $product->get_id(), $connection ) );
		if ( null !== $replaced && $replaced['id'] !== $made['id'] ) {
			$this->withdrawals->withdraw_or_keep( array( $replaced['id'] ) );
		}
		return $kept;
	}

	/**
	 * Mints the links of a saved product, or of each of its variations, or
	 * withdraws those that no longer personalise on the button. A refusal
	 * raises a notice on the product's screen.
	 *
	 * @param mixed $product_id The saved product.
	 */
	public function saved( mixed $product_id ): void {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$products = array( $product );
		if ( $product->is_type( 'variable' ) ) {
			$products = array_filter( array_map( 'wc_get_product', $product->get_children() ), static fn ( $child ): bool => $child instanceof WC_Product );
		}
		foreach ( $products as $sold ) {
			$this->refresh( $sold );
		}
	}

	/**
	 * @param WC_Product $product A product or variation a customer buys.
	 */
	private function refresh( WC_Product $product ): void {
		$config = ProductConfig::config_for( $product )->for_designer();
		// A disabled variation is stored as private.
		$disabled = $product->is_type( 'variation' ) && ProductConfig::PUBLIC_STATUS !== $product->get_status();
		if ( $disabled || ! Layout::personalises_on_button( $config, $this->settings ) ) {
			$this->withdraw_all( $product->get_id() );
			$this->notices->remove( self::notice_key( $product->get_id() ) );
			return;
		}
		if ( null !== $this->connection->paused() ) {
			return;
		}
		try {
			$kept = $this->kept_for( $product, $config, get_locale() );
		} catch ( ApiError $error ) {
			if ( ! $error->retryable ) {
				$this->notices->add( self::notice_key( $product->get_id() ), self::refusal( $product, $error ), 'error', false );
			}
			$this->logger->warning( sprintf( 'Pixonaut did not mint the designer link of product %d: %s', $product->get_id(), $error->getMessage() ), array( 'code' => $error->error_code ) );
			return;
		}
		$this->notices->remove( self::notice_key( $product->get_id() ) );
		$key = self::key( DesignerLanguage::resolve( $this->settings, get_locale() ) );
		if ( null !== $kept && '' !== $kept['name'] && self::name( $product, $key ) !== $kept['name'] ) {
			$this->renamed( $product, $kept, $key );
		}
	}

	/**
	 * Gives a kept link its product's new name.
	 *
	 * @param WC_Product           $product  The product or variation.
	 * @param array<string, mixed> $kept     The kept link.
	 * @param string               $language Its language, or `DesignerLanguage::WORKSPACE_DEFAULT` for none.
	 * @phpstan-param Kept $kept
	 */
	private function renamed( WC_Product $product, array $kept, string $language ): void {
		$name = self::name( $product, $language );
		if ( $this->rename( $kept['id'], $name ) ) {
			$kept['name'] = $name;
			$this->store( $product->get_id(), array( $language => $kept ) + self::kept( $product->get_id(), $kept['connection'] ) );
		}
	}

	/**
	 * Withdraws the links of a product or variation being deleted.
	 *
	 * @param mixed $post_id The post being deleted.
	 */
	public function deleting( mixed $post_id ): void {
		if ( in_array( get_post_type( (int) $post_id ), array( 'product', 'product_variation' ), true ) ) {
			$this->withdraw_all( (int) $post_id );
			$this->notices->remove( self::notice_key( (int) $post_id ) );
		}
	}

	/**
	 * Withdraws every link kept for a product or variation, and forgets them;
	 * one Pixonaut could not be asked about is kept for the job to ask again.
	 * While the connection is paused they are kept, to be withdrawn once it
	 * works again.
	 *
	 * @param int $product_id The product or variation.
	 */
	public function withdraw_all( int $product_id ): void {
		if ( null !== $this->connection->paused() ) {
			return;
		}
		$connection = $this->connection_id();
		$this->withdrawals->withdraw_or_keep( array_column( null === $connection ? array() : self::kept( $product_id, $connection ), 'id' ) );
		delete_post_meta( $product_id, self::META );
	}

	/**
	 * Forgets every kept link, and every link waiting to be withdrawn:
	 * Pixonaut withdraws a store's links when it disconnects.
	 */
	public function forget_all(): void {
		delete_post_meta_by_key( self::META );
		$this->withdrawals->forget_all();
	}

	/**
	 * Forgets every kept link while the store stays connected, queueing the
	 * links of the current connection to be withdrawn; a product mints a new
	 * one when it is next shown. The links already waiting to be withdrawn
	 * keep waiting.
	 */
	public function clear(): void {
		$connection = $this->connection_id();
		$ids        = array();
		$products   = null === $connection ? array() : get_posts(
			array(
				'post_type'   => array( 'product', 'product_variation' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a developer tool's one-off sweep.
			)
		);
		foreach ( $products as $product_id ) {
			$ids = array_merge( $ids, array_column( self::kept( (int) $product_id, (string) $connection ), 'id' ) );
		}
		$this->withdrawals->queue( array_values( array_unique( $ids ) ) );
		delete_post_meta_by_key( self::META );
	}

	/**
	 * Forgets the kept links of a product and its variations that Pixonaut no
	 * longer lists as working: withdrawn in the studio, or kept for another
	 * connection. A link Pixonaut could not be asked about is kept.
	 *
	 * @param int $product_id A product or variation.
	 */
	public function prune( int $product_id ): void {
		$connection = $this->connection_id();
		$product    = wc_get_product( $product_id );
		if ( null === $connection || ! $product instanceof WC_Product ) {
			return;
		}
		$working = array();
		foreach ( array_merge( array( $product_id ), $product->get_children() ) as $id ) {
			$stored = get_post_meta( $id, self::META, true );
			if ( ! is_array( $stored ) || array() === $stored ) {
				continue;
			}
			$kept = self::kept( $id, $connection );
			foreach ( $kept as $language => $link ) {
				try {
					$working[ $link['template'] ] ??= $this->api->working( $link['template'] );
				} catch ( ApiError $error ) {
					$this->logger->warning( sprintf( 'Could not check the designer links of product %d: %s', $id, $error->getMessage() ), array( 'code' => $error->error_code ) );
					return;
				}
				if ( ! in_array( $link['id'], $working[ $link['template'] ], true ) ) {
					unset( $kept[ $language ] );
				}
			}
			if ( count( $kept ) !== count( $stored ) ) {
				$this->store( $id, $kept );
			}
		}
	}

	/**
	 * The products and variations keeping a link, whatever the connection
	 * and their status, the bin included: a product restored from it uses
	 * the links it kept.
	 *
	 * @param string $id The link.
	 *
	 * @return list<int>
	 */
	public function holders( string $id ): array {
		$products = get_posts(
			array(
				'post_type'   => array( 'product', 'product_variation' ),
				'post_status' => array_keys( get_post_stati() ),
				'numberposts' => -1,
				'fields'      => 'ids',
				// A link id is in the serialized meta as a quoted string; change() reads each match again.
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Pixonaut withdrawing a link is rare.
					array(
						'key'     => self::META,
						'value'   => '"' . $id . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		return array_values( array_map( 'intval', $products ) );
	}

	/**
	 * Forgets a link wherever it is kept, as Pixonaut withdrew it: the
	 * product mints a new one when it is next shown or saved.
	 *
	 * @param string $id The link.
	 *
	 * @throws RuntimeException When another request is minting a product's links; the event is handled again later.
	 */
	public function forget( string $id ): void {
		foreach ( $this->holders( $id ) as $product_id ) {
			$this->change( $product_id, static fn ( array $link ): ?array => $link['id'] === $id ? null : $link );
		}
	}

	/**
	 * Forgets a link wherever it is kept and asks Pixonaut to withdraw it,
	 * again later when it cannot be asked now: its address is no longer
	 * known, so the product mints a new one.
	 *
	 * @param string $id The link.
	 *
	 * @throws RuntimeException When another request is minting a product's links; the event is handled again later.
	 */
	public function retire( string $id ): void {
		$this->forget( $id );
		$this->withdrawals->withdraw_or_keep( array( $id ) );
	}

	/**
	 * Keeps a link's new address wherever it is kept, as Pixonaut
	 * regenerated it under the same id.
	 *
	 * @param string $id  The link.
	 * @param string $url The address it opens now.
	 *
	 * @throws RuntimeException When another request is minting a product's links; the event is handled again later.
	 */
	public function readdress( string $id, string $url ): void {
		foreach ( $this->holders( $id ) as $product_id ) {
			$this->change( $product_id, static fn ( array $link ): array => $link['id'] === $id ? array( 'url' => $url ) + $link : $link );
		}
	}

	/**
	 * Changes each link a product keeps, holding the right to mint its links
	 * so no mint or other change in between is overwritten, and reading them
	 * afresh once it holds it.
	 *
	 * @param int                                                         $product_id The product or variation.
	 * @param callable(array<string, mixed>): (array<string, mixed>|null) $edit The link changed, or null to forget it.
	 *
	 * @throws RuntimeException When another request holds the right.
	 */
	private function change( int $product_id, callable $edit ): void {
		if ( ! $this->lock( $product_id ) ) {
			throw new RuntimeException( sprintf( 'The designer links of product %d are being minted; the change is made again later.', $product_id ) );
		}
		try {
			wp_cache_delete( (string) $product_id, 'post_meta' );
			$stored  = get_post_meta( $product_id, self::META, true );
			$changed = array();
			foreach ( is_array( $stored ) ? $stored : array() as $language => $link ) {
				$link = is_array( $link ) ? $edit( $link ) : $link;
				if ( null !== $link ) {
					$changed[ $language ] = $link;
				}
			}
			$this->store( $product_id, $changed );
		} finally {
			$this->unlock( $product_id );
		}
	}

	/**
	 * @param string      $id   The link.
	 * @param string|null $name Its new name; null for none.
	 * @return bool Whether Pixonaut renamed it.
	 */
	private function rename( string $id, ?string $name ): bool {
		try {
			$this->api->rename( $id, $name );
			return true;
		} catch ( ApiError $error ) {
			$this->logger->warning( sprintf( 'Could not rename designer link %s: %s', $id, $error->getMessage() ), array( 'code' => $error->error_code ) );
			return false;
		}
	}

	/**
	 * Pixonaut's id for the connection links are minted under now, new each
	 * time the store connects; null when not connected.
	 */
	private function connection_id(): ?string {
		return $this->connection->current()?->connection_id;
	}

	private static function lock_option( int $product_id ): string {
		return Schema::DESIGNER_LINK_LOCK_OPTION_PREFIX . $product_id;
	}

	/**
	 * What a link in a language is kept under: the language, or
	 * `DesignerLanguage::WORKSPACE_DEFAULT` for one minted without.
	 *
	 * @param string|null $language One of `DesignerLanguage::languages()`; null for none.
	 */
	private static function key( ?string $language ): string {
		return $language ?? DesignerLanguage::WORKSPACE_DEFAULT;
	}

	/**
	 * The links kept for a product or variation under a connection, by language.
	 *
	 * @param int    $product_id The product or variation.
	 * @param string $connection The connection.
	 *
	 * @return array<string, array<string, string>>
	 * @phpstan-return array<string, Kept>
	 */
	private static function kept( int $product_id, string $connection ): array {
		$stored = get_post_meta( $product_id, self::META, true );
		$kept   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $language => $link ) {
			if ( ! is_string( $language ) || ! is_array( $link ) || ( $link['connection'] ?? null ) !== $connection ) {
				continue;
			}
			$fields = array_intersect_key( $link, array_flip( self::FIELDS ) );
			if ( count( $fields ) !== count( self::FIELDS ) || count( array_filter( $fields, 'is_string' ) ) !== count( self::FIELDS ) ) {
				continue;
			}
			/** @phpstan-var Kept $fields */
			$kept[ $language ] = $fields;
		}
		return $kept;
	}

	/**
	 * Replaces a product's kept links.
	 *
	 * @param int                                  $product_id The product or variation.
	 * @param array<string, array<string, string>> $kept       The links to keep, by language.
	 */
	private function store( int $product_id, array $kept ): void {
		if ( array() === $kept ) {
			delete_post_meta( $product_id, self::META );
			return;
		}
		update_post_meta( $product_id, self::META, $kept );
	}

	/**
	 * The link's name: the product's, its id, and the language when it is
	 * not the site's, within Pixonaut's length.
	 *
	 * @param WC_Product $product  The product or variation.
	 * @param string     $language The link's language, or `DesignerLanguage::WORKSPACE_DEFAULT` for none.
	 */
	private static function name( WC_Product $product, string $language ): string {
		$suffix = sprintf( ' (#%d)', $product->get_id() );
		$site   = (string) get_option( 'WPLANG' );
		if ( self::key( DesignerLanguage::for_locale( '' === $site ? self::DEFAULT_SITE_LOCALE : $site ) ) !== $language ) {
			$suffix .= ' · ' . $language;
		}
		$title = wp_strip_all_tags( $product->get_name() );
		return mb_substr( $title, 0, self::NAME_MAX_LENGTH - mb_strlen( $suffix ) ) . $suffix;
	}

	/**
	 * What the product's screen says when Pixonaut refused its link.
	 *
	 * @param WC_Product $product The product or variation.
	 * @param ApiError   $error   The refusal.
	 */
	private static function refusal( WC_Product $product, ApiError $error ): string {
		$why = match ( $error->error_code ) {
			/* translators: Why Pixonaut refused a product, filling in %2$s of a message on the admin product page. Ends a sentence; lower case. */
			ErrorCodes::PRINT_SIZE_ILLEGAL => __( 'one of the print sizes it offers does not fit its template or its position on the blank. Change the sizes in its Pixonaut tab.', 'pixonaut-for-woocommerce' ),
			/* translators: Why Pixonaut refused a product, filling in %2$s of a message on the admin product page. Ends a sentence; lower case. */
			ErrorCodes::UNKNOWN_PLACEMENT  => __( 'its blank no longer has a position one of its sides prints on. Pick the positions again in its Pixonaut tab.', 'pixonaut-for-woocommerce' ),
			/* translators: %s: the error code Pixonaut answered, e.g. `invalid_input`. */
			default                        => sprintf( __( 'Pixonaut answered %s.', 'pixonaut-for-woocommerce' ), $error->error_code ),
		};
		return sprintf(
			/* translators: 1: the product's name, 2: why Pixonaut refused its link. */
			__( 'Customers cannot open the designer for %1$s: %2$s', 'pixonaut-for-woocommerce' ),
			esc_html( $product->get_name() ),
			esc_html( $why )
		);
	}
}
