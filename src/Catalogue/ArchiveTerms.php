<?php
/**
 * How an archive's index names attributes and their values.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Storefront\QueryNames;
use WP_Term;

/**
 * An archive's index (`ArchiveIndexes`) names an attribute as the shop's
 * addresses do: by its short name (`QueryNames`), `storlek`, else by
 * WooCommerce's filter argument, `filter_storlek`. It names a value by its
 * slug, as an address writes it. Read once a build.
 */
final class ArchiveTerms {

	/**
	 * Each attribute's key, by taxonomy.
	 *
	 * @var array<string, string>
	 */
	private array $keys = array();

	/**
	 * Each term's taxonomy and slug, by term id; null for a term that is gone.
	 *
	 * @var array<int, array{0: string, 1: string}|null>
	 */
	private array $terms = array();

	/**
	 * The key an attribute goes by in the index and the shop's addresses.
	 *
	 * @param string $taxonomy A global attribute's taxonomy (`pa_storlek`).
	 */
	public function key( string $taxonomy ): string {
		if ( ! isset( $this->keys[ $taxonomy ] ) ) {
			$slug                    = wc_attribute_taxonomy_slug( $taxonomy );
			$short                   = QueryNames::of( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$this->keys[ $taxonomy ] = '' !== $short ? $short : QueryNames::FILTER_PREFIX . $slug;
		}
		return $this->keys[ $taxonomy ];
	}

	/**
	 * A term's taxonomy and slug; null for a term that is gone.
	 *
	 * @param int $term_id A term.
	 * @return array{0: string, 1: string}|null
	 */
	public function term( int $term_id ): ?array {
		if ( ! array_key_exists( $term_id, $this->terms ) ) {
			$term                    = get_term( $term_id );
			$this->terms[ $term_id ] = $term instanceof WP_Term ? array( $term->taxonomy, $term->slug ) : null;
		}
		return $this->terms[ $term_id ];
	}

	/**
	 * Term ids of one attribute or several, as the index writes them: the
	 * slugs of each, by the attribute's key.
	 *
	 * @param int[] $term_ids Term ids.
	 * @return array<string, list<string>> Slugs by key.
	 */
	public function by_key( array $term_ids ): array {
		$named = array();
		foreach ( $term_ids as $term_id ) {
			$term = $this->term( (int) $term_id );
			if ( null !== $term ) {
				$named[ $this->key( $term[0] ) ][] = $term[1];
			}
		}
		return $named;
	}
}
