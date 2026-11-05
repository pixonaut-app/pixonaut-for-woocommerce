<?php
/**
 * A Pixonaut template id, `<baseId>@<version>`.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Pixonaut names one published version of a template as `<baseId>@<version>`.
 * A base id may itself hold `@`, so the version is what follows the last one.
 */
final class TemplateId {

	private const SEPARATOR = '@';

	/**
	 * @param string $base_id The template, across its versions.
	 * @param int    $version One published version, from 1.
	 */
	public function __construct(
		public readonly string $base_id,
		public readonly int $version,
	) {
	}

	/**
	 * @param string $id E.g. `mini-tee@2`.
	 * @return self|null Null when the id has no base id or no positive whole version.
	 */
	public static function parse( string $id ): ?self {
		$at = strrpos( $id, self::SEPARATOR );
		if ( false === $at || 0 === $at ) {
			return null;
		}
		$version = substr( $id, $at + 1 );
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $version ) ) {
			return null;
		}
		return new self( substr( $id, 0, $at ), (int) $version );
	}

	public function __toString(): string {
		return $this->base_id . self::SEPARATOR . $this->version;
	}
}
