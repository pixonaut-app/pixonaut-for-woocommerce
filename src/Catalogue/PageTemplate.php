<?php
/**
 * One of the other templates a page of a side printed as pages may be designed on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * A page layout: a template of the side's ratio, such as two or four
 * photos, that the shopper may design a page of a book on instead of the
 * side's own template, which every page starts on. It follows its latest
 * published version as the side's template does, or stays on its version
 * when the side is pinned; a design made on the version it moved from is
 * still accepted, as one made on the side's own previous version is.
 */
final class PageTemplate {

	/**
	 * Most page layouts a side offers besides its own template: a row of
	 * thumbnails on a phone, and a book has a handful of layouts. Pixonaut's
	 * API and SDK hold the same limit.
	 */
	public const MAX = 8;

	/**
	 * @param string      $base_id          The template, across its versions.
	 * @param int         $version          The version a page is designed on now.
	 * @param int|null    $previous_version The version before that, set when the layout moved on.
	 * @param int|null    $latest_version   The template's latest published version, as last known; null when never read.
	 * @param string|null $name             Cached template name.
	 */
	public function __construct(
		public readonly string $base_id,
		public readonly int $version,
		public readonly ?int $previous_version = null,
		public readonly ?int $latest_version = null,
		public readonly ?string $name = null,
	) {
	}

	/**
	 * The layout on a template version, e.g. `two-photos@2`.
	 *
	 * @param TemplateId $id The version.
	 */
	public static function of( TemplateId $id ): self {
		return new self( $id->base_id, $id->version );
	}

	/**
	 * The version a page is designed on, as Pixonaut names it.
	 */
	public function id(): TemplateId {
		return new TemplateId( $this->base_id, $this->version );
	}

	/**
	 * The versions a design made on the layout is accepted from: its own
	 * and the one before it.
	 *
	 * @return list<int>
	 */
	public function accepted_versions(): array {
		return array_filter( array( $this->version, $this->previous_version ), 'is_int' );
	}

	/**
	 * The layout following the template's live version, as a side does
	 * (`Side::rolled_to()`): the version it leaves becomes the previous one.
	 * A pinned one stays and only learns which version is the latest.
	 *
	 * @param int  $live_version The template's live version.
	 * @param bool $pinned       Whether the side stays on its versions.
	 */
	public function rolled_to( int $live_version, bool $pinned ): self {
		if ( $pinned || $live_version === $this->version ) {
			return ( $this->latest_version ?? $this->version ) === $live_version ? $this : new self( $this->base_id, $this->version, $this->previous_version, $live_version, $this->name );
		}
		return new self( $this->base_id, $live_version, $this->version, $live_version, $this->name );
	}

	/**
	 * A copy that knows the version it was on before and the latest one.
	 *
	 * @param int|null $previous_version The version a design is still accepted from besides its own.
	 * @param int|null $latest_version   The latest published version; null when unknown.
	 */
	public function with_versions( ?int $previous_version, ?int $latest_version ): self {
		return new self( $this->base_id, $this->version, $previous_version, $latest_version, $this->name );
	}

	/**
	 * A copy with the template's name as Pixonaut gives it now.
	 *
	 * @param string|null $name The name; null keeps the one it has.
	 */
	public function with_name( ?string $name ): self {
		return null === $name || $name === $this->name ? $this : new self( $this->base_id, $this->version, $this->previous_version, $this->latest_version, $name );
	}

	/**
	 * A copy on template `$to` when it is on `$from`; this very one otherwise.
	 *
	 * @param string $from The template's old base id.
	 * @param string $to   Its new base id.
	 */
	public function with_template_renamed( string $from, string $to ): self {
		return $this->base_id === $from ? new self( $to, $this->version, $this->previous_version, $this->latest_version, $this->name ) : $this;
	}

	/**
	 * @return array{baseId: string, version: int, previousVersion: int|null, latestVersion: int|null, name: string|null}
	 */
	public function to_array(): array {
		return array(
			'baseId'          => $this->base_id,
			'version'         => $this->version,
			'previousVersion' => $this->previous_version,
			'latestVersion'   => $this->latest_version,
			'name'            => $this->name,
		);
	}

	/**
	 * Layouts as stored; an entry in no shape read here is left out, and
	 * none is read where there is no list, as on a side stored before
	 * layouts were offered.
	 *
	 * @param mixed $data The stored list.
	 * @return list<self>
	 */
	public static function list_from_array( mixed $data ): array {
		$layouts = array();
		foreach ( is_array( $data ) ? $data : array() as $entry ) {
			$base    = is_array( $entry ) ? ( $entry['baseId'] ?? null ) : null;
			$version = is_array( $entry ) ? ( $entry['version'] ?? null ) : null;
			if ( ! is_string( $base ) || '' === $base || ! is_int( $version ) || $version < 1 ) {
				continue;
			}
			$name      = $entry['name'] ?? null;
			$layouts[] = new self(
				$base,
				$version,
				is_int( $entry['previousVersion'] ?? null ) ? $entry['previousVersion'] : null,
				is_int( $entry['latestVersion'] ?? null ) ? $entry['latestVersion'] : null,
				is_string( $name ) && '' !== $name ? $name : null
			);
		}
		return $layouts;
	}
}
