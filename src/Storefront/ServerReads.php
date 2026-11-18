<?php
/**
 * The Pixonaut reads a product page's designer starts from, made on the server.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * Reads what each side's designer opens with — its template, the placement's
 * box, and the blank's views and variants — so the page prints at its final
 * layout and the SDK starts without reading them again in the browser.
 * Answers are cached like the admin's catalogue reads, as the JSON text
 * Pixonaut sent, which the page passes on unchanged; once one's minutes
 * are up, the one from before serves while a new one is read after the
 * page is sent, and a job reads each again before that copy runs out
 * (`ServerReadsWarmUp`), so a visitor waits on Pixonaut only for an answer
 * no page asked for in days. When Pixonaut fails,
 * the page stops reading for a minute and the SDK reads in the browser as it
 * would without them, so an outage does not hold every product page up for
 * the API's timeout.
 */
final class ServerReads {

	/**
	 * Set for a minute after a read failed; product pages skip the reads
	 * while it is.
	 */
	public const PAUSE_TRANSIENT = Schema::SERVER_READS_PAUSE_TRANSIENT;

	/**
	 * A minute, in seconds.
	 */
	public const PAUSE_SECONDS = 60;

	/**
	 * The reads a product page makes from the template, its placement and
	 * its blank, as they are cached.
	 */
	private const TEMPLATE_READ = 'storefront-template';

	private const CONSTRAINTS_READ = 'storefront-constraints';

	private const BLANK_VIEWS_READ = 'storefront-blank-views';

	/**
	 * A blank's variants with their options named as written, which no
	 * designer shows: its views are not read again for them.
	 */
	private const WRITTEN_VARIANTS_READ = 'storefront-blank-written-variants';

	/**
	 * The template `template_read()` decoded last, by template id and
	 * language, with the answer it was decoded from: a product page asks
	 * for the same template for each variation on it.
	 *
	 * @var array<string, array{0: string, 1: array<mixed>}>
	 */
	private array $decoded = array();

	/**
	 * @param Catalogue              $catalogue Reads Pixonaut.
	 * @param CatalogueCache         $cache     Keeps the answers for a few minutes.
	 * @param Sdk                    $pixonaut  Reads the designs a correction opens.
	 * @param ServerReadsWarmUp|null $warm_up   Notes each read, to read it again before its kept copy runs out; null notes none.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
		private readonly Sdk $pixonaut,
		private readonly ?ServerReadsWarmUp $warm_up = null,
	) {
	}

	/**
	 * Reads one of a product page's answers again and keeps it, for the
	 * job keeping them from running out (`ServerReadsWarmUp`). False when
	 * Pixonaut failed; a read no page makes has nothing to read.
	 *
	 * @param string                $what Which read.
	 * @param array<string, string> $args What it was asked with.
	 */
	public function read_again( string $what, array $args ): bool {
		$load = $this->loader( $what, $args );
		if ( null === $load ) {
			return true;
		}
		try {
			$this->cache->read_again( $what, $args, $load );
		} catch ( ApiException ) {
			return false;
		}
		$this->warm_up?->read( $what, $args );
		return true;
	}

	/**
	 * Each side's reads, by side key, for the sides that open a template by
	 * its id; a side whose read failed is left out.
	 *
	 * @param array<string, array<string, mixed>> $mounts Mount options by side key.
	 * @return array<string, array<string, mixed>> `template`, and with a blank `placement` and `blank` (`mockups`, `variants`), each a JSON object's text.
	 */
	public function for_mounts( array $mounts ): array {
		$reads = array();
		foreach ( $mounts as $side => $options ) {
			if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
				break;
			}
			$template_id = $options['templateId'] ?? null;
			// A stored design or a correction opens from its own reads.
			if ( ! is_string( $template_id ) || isset( $options['design'] ) ) {
				continue;
			}
			try {
				$reads[ (string) $side ] = $this->side( $template_id, $options['blankId'] ?? null, $options['placementId'] ?? null, $options['locale'] ?? null );
			} catch ( ApiException ) {
				set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			}
		}
		return $reads;
	}

	/**
	 * The ids of a template's groups, for a side the page has no read of: a
	 * correction's. Null when Pixonaut could not be asked.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 * @param mixed  $locale      The mount's `locale`, when it names one.
	 * @return list<string>|null
	 */
	public function template_groups( string $template_id, mixed $locale ): ?array {
		$template = $this->template_read( $template_id, $locale );
		return null === $template ? null : array_column( TemplateForm::groups( $template ), 'id' );
	}

	/**
	 * A template as Pixonaut answered it, decoded, for a side the page has no
	 * read of: a variation's on another template. Null when Pixonaut could
	 * not be asked.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 * @param mixed  $locale      The mount's `locale`, when it names one.
	 * @return array<mixed>|null
	 */
	public function template_read( string $template_id, mixed $locale ): ?array {
		if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
			return null;
		}
		try {
			$answer = $this->template( $template_id, self::supported( $locale ) );
		} catch ( ApiException ) {
			set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			return null;
		}
		$key = $template_id . '|' . (string) self::supported( $locale );
		if ( ( $this->decoded[ $key ][0] ?? null ) !== $answer ) {
			$template              = json_decode( $answer, true );
			$this->decoded[ $key ] = array( $answer, is_array( $template ) ? $template : array() );
		}
		return $this->decoded[ $key ][1];
	}

	/**
	 * Whether a template's background shows what stands behind it: null when
	 * Pixonaut does not say, or could not be asked.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 */
	public function background_see_through( string $template_id ): ?bool {
		if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
			return null;
		}
		try {
			$template = json_decode( $this->template( $template_id, null ), true );
		} catch ( ApiException ) {
			set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			return null;
		}
		$see_through = is_array( $template ) ? ( $template['backgroundSeeThrough'] ?? null ) : null;
		return is_bool( $see_through ) ? $see_through : null;
	}

	/**
	 * A template as the designer reads it, cached.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param string|null $language    The language to read it in; null for the workspace's default.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function template( string $template_id, ?string $language ): string {
		return $this->json( self::TEMPLATE_READ, array( 'id' => $template_id ) + ( null === $language ? array() : array( 'lang' => $language ) ) );
	}

	/**
	 * @param string $template_id  `<baseId>@<version>`.
	 * @param mixed  $blank_id     The blank, when the side has one.
	 * @param mixed  $placement_id Its placement.
	 * @param mixed  $locale       The mount's `locale`, when it names one.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function side( string $template_id, mixed $blank_id, mixed $placement_id, mixed $locale ): array {
		// Without a `locale` the designer speaks the workspace's default language, which the template is read in without `lang`.
		$mount_language = self::supported( $locale );
		$reads          = array( 'template' => $this->template( $template_id, $mount_language ) );
		if ( ! is_string( $blank_id ) || ! is_string( $placement_id ) ) {
			return $reads;
		}
		$reads['placement'] = $this->json(
			self::CONSTRAINTS_READ,
			array(
				'id'          => $template_id,
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
			)
		);
		$template           = json_decode( $reads['template'], true );
		$reads['blank']     = $this->blank( $blank_id, self::language( $locale, is_array( $template ) ? ( $template['workspaceLanguage'] ?? null ) : null ) );
		return $reads;
	}

	/**
	 * What the page prints each side that corrects a stored design at, by
	 * side key: the design's print size, whose shape the SDK draws the
	 * correction in, and on a blank the blank's views, for the strip of
	 * views at its final layout. The SDK reads the views again through the
	 * correction link, so none of it is passed on to it. A side whose read
	 * failed is left out.
	 *
	 * @param array<string, array<string, mixed>> $mounts Mount options by side key.
	 * @return array<string, array<string, mixed>> `printSize` (`w`, `h`) and with a blank `blank` (`mockups`, `variants`), each a JSON object's text.
	 */
	public function correction_views( array $mounts ): array {
		$reads = array();
		foreach ( $mounts as $side => $options ) {
			if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
				break;
			}
			$design_id = $options['design'] ?? null;
			if ( ! is_string( $design_id ) ) {
				continue;
			}
			$blank_id = $options['blankId'] ?? null;
			try {
				$reads[ (string) $side ]['printSize'] = $this->print_size( $design_id );
				if ( is_string( $blank_id ) && is_string( $options['placementId'] ?? null ) ) {
					$reads[ (string) $side ]['blank'] = $this->blank( $blank_id, self::language( $options['locale'] ?? null ) );
				}
			} catch ( ApiException ) {
				// The print size read before a blank read failed still shapes the frame.
				set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			}
		}
		return $reads;
	}

	/**
	 * The size a design prints at, as `{w, h}` in millimetres, cached: a
	 * correction keeps the size the design was bought at, so it does not
	 * change. `{}` for a design that holds none.
	 *
	 * @param string $design_id The design.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function print_size( string $design_id ): string {
		return $this->remember(
			'storefront-design-size',
			array( 'id' => $design_id ),
			function () use ( $design_id ): string {
				$size = Design::get( $this->pixonaut, $design_id )->print_size;
				return (string) wp_json_encode(
					null !== $size ? array(
						'w' => $size->width_mm,
						'h' => $size->height_mm,
					) : (object) array()
				);
			}
		);
	}

	/**
	 * The language a side's designer is shown in, which names the blank's
	 * options and views in it too: the mount's `locale`, else the workspace's
	 * default language, which the designer uses without one, as the template
	 * read with it names it (`workspaceLanguage`), else as the store last
	 * read it.
	 *
	 * @param mixed $locale    The mount's `locale`.
	 * @param mixed $workspace The template answer's `workspaceLanguage`; null without one.
	 * @return string|null One of `DesignerLanguage::languages()`; null when the store does not know the workspace's.
	 */
	private static function language( mixed $locale, mixed $workspace = null ): ?string {
		return self::supported( $locale ) ?? self::supported( $workspace ) ?? WorkspaceSettings::default_language();
	}

	/**
	 * @param mixed $language A language code, or anything else.
	 * @return string|null The code when it is one of `DesignerLanguage::languages()`, else null.
	 */
	private static function supported( mixed $language ): ?string {
		return is_string( $language ) && in_array( $language, DesignerLanguage::languages(), true ) ? $language : null;
	}

	/**
	 * The blank's options as written in Pixonaut, each with its `values`
	 * and how each looks (`appearances`), from its cached variants read;
	 * empty when Pixonaut could not be asked.
	 *
	 * @param string $blank_id The blank.
	 * @return list<array<mixed>>
	 */
	public function written_options( string $blank_id ): array {
		if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
			return array();
		}
		try {
			$variants = json_decode( $this->json( self::WRITTEN_VARIANTS_READ, array( 'id' => $blank_id ) ), true );
		} catch ( ApiException ) {
			set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			return array();
		}
		return self::options_of( $variants );
	}

	/**
	 * The `options` of a blank's variants read, each one an array.
	 *
	 * @param mixed $variants The read, decoded.
	 * @return list<array<mixed>>
	 */
	public static function options_of( mixed $variants ): array {
		$options = is_array( $variants ) && is_array( $variants['options'] ?? null ) ? $variants['options'] : array();
		return array_values( array_filter( $options, 'is_array' ) );
	}

	/**
	 * The blank's views and variants, cached.
	 *
	 * @param string      $blank_id The blank.
	 * @param string|null $language The language to name its options and views in; null for their names as written.
	 * @return array{mockups: string, variants: string}
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function blank( string $blank_id, ?string $language ): array {
		$blank = $this->kept( self::BLANK_VIEWS_READ, array( 'id' => $blank_id ) + ( null === $language ? array() : array( 'lang' => $language ) ) );
		return array(
			'mockups'  => is_string( $blank['mockups'] ?? null ) ? $blank['mockups'] : '{}',
			'variants' => is_string( $blank['variants'] ?? null ) ? $blank['variants'] : '{}',
		);
	}

	/**
	 * One of a product page's answers' text, cached, and noted to be read
	 * again before its kept copy runs out.
	 *
	 * @param string                $what Which read.
	 * @param array<string, string> $args What it was asked with.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function json( string $what, array $args ): string {
		$answer = $this->kept( $what, $args );
		return is_string( $answer['json'] ?? null ) ? $answer['json'] : '{}';
	}

	/**
	 * One of a product page's answers, cached, and noted to be read again
	 * before its kept copy runs out.
	 *
	 * @param string                $what Which read.
	 * @param array<string, string> $args What it was asked with.
	 * @return array<int|string, mixed>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function kept( string $what, array $args ): array {
		$load   = $this->loader( $what, $args ) ?? static fn (): array => array();
		$answer = $this->cache->remember_or_kept(
			$what,
			$args,
			function () use ( $what, $args, $load ): array {
				$answer = $load();
				$this->warm_up?->read( $what, $args );
				return $answer;
			}
		);
		// After the read, which notes an answer it loaded as read now.
		$this->warm_up?->asked( $what, $args );
		return $answer;
	}

	/**
	 * How one of a product page's reads asks Pixonaut, the same for the
	 * page and for the job reading it again; null for another read.
	 *
	 * @param string                $what Which read.
	 * @param array<string, string> $args What it was asked with.
	 * @return (Closure(): array<int|string, mixed>)|null
	 */
	private function loader( string $what, array $args ): ?Closure {
		$id       = $args['id'] ?? '';
		$language = $args['lang'] ?? null;
		return match ( $what ) {
			self::TEMPLATE_READ         => fn (): array => array( 'json' => $this->catalogue->template_answer( $id, $language ) ),
			self::CONSTRAINTS_READ      => fn (): array => array( 'json' => $this->catalogue->constraints_answer( $id, $args['blankId'] ?? '', $args['placementId'] ?? '' ) ),
			self::BLANK_VIEWS_READ      => fn (): array => $this->catalogue->blank_views_answers( $id, $language ),
			self::WRITTEN_VARIANTS_READ => fn (): array => array( 'json' => $this->catalogue->blank_variants_answer( $id ) ),
			default                     => null,
		};
	}

	/**
	 * One answer's text, cached.
	 *
	 * @param string                $what Which answer.
	 * @param array<string, string> $args What it was asked with.
	 * @param Closure               $load Asks Pixonaut.
	 * @phpstan-param Closure(): string $load
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function remember( string $what, array $args, Closure $load ): string {
		$answer = $this->cache->remember_or_kept( $what, $args, static fn (): array => array( 'json' => $load() ) );
		return is_string( $answer['json'] ?? null ) ? $answer['json'] : '{}';
	}

	/**
	 * A side's reads decoded, for working out its layout.
	 *
	 * @param array<string, mixed> $reads A side's reads, from `for_mounts()`.
	 * @return array<string, mixed>
	 */
	public static function decoded( array $reads ): array {
		$decoded = array();
		foreach ( $reads as $key => $read ) {
			$decoded[ $key ] = is_array( $read ) ? self::decoded( $read ) : json_decode( (string) $read, true );
		}
		return $decoded;
	}

	/**
	 * The reads as one JSON object, by side key, for a script element: each
	 * answer as Pixonaut sent it, with `<`, `>` and `&` escaped so the text
	 * cannot close the element.
	 *
	 * @param array<string, array<string, mixed>> $reads The reads, from `for_mounts()`.
	 */
	public static function script_json( array $reads ): string {
		return strtr(
			self::json_object( $reads ),
			array(
				'<' => '\u003C',
				'>' => '\u003E',
				'&' => '\u0026',
			)
		);
	}

	/**
	 * @param array<string, mixed> $reads Reads, or one side's.
	 */
	private static function json_object( array $reads ): string {
		$members = array();
		foreach ( $reads as $key => $read ) {
			$members[] = wp_json_encode( (string) $key ) . ':' . ( is_array( $read ) ? self::json_object( $read ) : (string) $read );
		}
		return '{' . implode( ',', $members ) . '}';
	}
}
