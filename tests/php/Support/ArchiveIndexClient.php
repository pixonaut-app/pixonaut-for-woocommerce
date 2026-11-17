<?php
/**
 * An archive's index read as a theme's client reads it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * The rules of the archive index's contract (issue #267), applied to an
 * index as a theme filtering in the browser applies them, for tests to
 * compare with what the server-rendered archive lists.
 */
final class ArchiveIndexClient {

	/**
	 * @param array<string, mixed> $index An archive's index, decoded as arrays.
	 */
	public function __construct( private readonly array $index ) {
	}

	/**
	 * The cards the archive shows for a choice, in order, each with the
	 * link and picture it shows.
	 *
	 * @param array<string, list<string>> $chosen Chosen value ids by filter key.
	 * @param float|null                  $min    `min_price`.
	 * @param float|null                  $max    `max_price`.
	 * @param string|null                 $sort   An `orderby`; the index's order when null.
	 * @return list<array{id: string, link: string, image: string, swatches: list<array{link: string, image: string, title: string}>}>
	 */
	public function shown( array $chosen, ?float $min = null, ?float $max = null, ?string $sort = null ): array {
		$chosen   = array_filter( $chosen, static fn ( array $ids ): bool => array() !== $ids );
		$filtered = array() !== $chosen || null !== $min || null !== $max;
		$waived   = $this->waived( $chosen );
		$shown    = array();
		foreach ( $this->cards() as $place => $card ) {
			$only = $card['onlyFiltered'];
			if ( array() !== array_diff( $only, array_keys( $chosen ) ) ) {
				continue;
			}
			if ( ! $filtered ) {
				$shown[] = array( $place, $card, null );
				continue;
			}
			$holds = $card['holds'];
			foreach ( $chosen as $key => $ids ) {
				if ( ! in_array( $key, $card['listedBy'], true ) ) {
					continue;
				}
				$holds_chosen = array() !== array_intersect( $holds[ $key ] ?? array(), $ids );
				if ( ! $holds_chosen && ! ( isset( $waived[ $key ] ) && ! isset( $holds[ $key ] ) ) ) {
					continue 2;
				}
			}
			$opened = null;
			foreach ( $card['variations'] as $row ) {
				if ( $this->matches( $row, $chosen, $waived, $min, $max ) ) {
					$opened = $row;
					break;
				}
			}
			if ( null !== $opened ) {
				$shown[] = array( $place, $card, $opened );
			}
		}
		if ( null !== $sort ) {
			usort( $shown, static fn ( array $first, array $second ): int => array( $first[1]['sort'][ $sort ], $first[0] ) <=> array( $second[1]['sort'][ $sort ], $second[0] ) );
		}
		$cards    = array();
		$swatches = array();
		$designs  = array();
		foreach ( $shown as list( , $card, $opened ) ) {
			$drawn = array(
				'id'    => (string) $card['id'],
				'link'  => $this->link( $card, $opened ),
				'image' => $this->image( $card, $opened, $chosen, $waived ),
			);
			$holds = $card['holds'];
			foreach ( $card['onlyFiltered'] as $key ) {
				unset( $holds[ $key ] );
			}
			ksort( $holds );
			$design = $card['productId'] . (string) wp_json_encode( $holds );
			// With filters on, a design shows once: its first card, the others its swatches.
			if ( $filtered && isset( $designs[ $design ] ) ) {
				$swatches[ $designs[ $design ] ][] = array(
					'link'  => $drawn['link'],
					'image' => $drawn['image'],
					'title' => (string) $card['title'],
				);
				continue;
			}
			$designs[ $design ] = count( $cards );
			$cards[]            = $drawn;
		}
		foreach ( $cards as $index => $drawn ) {
			$cards[ $index ] = $drawn + array( 'swatches' => $swatches[ $index ] ?? array() );
		}
		return $cards;
	}

	/**
	 * Each value's count for a choice: the cards shown with it added.
	 *
	 * @param array<string, list<string>> $chosen Chosen value ids by filter key.
	 * @param float|null                  $min    `min_price`.
	 * @param float|null                  $max    `max_price`.
	 * @return array<string, array<string, int>> Counts by filter key and value id.
	 */
	public function counts( array $chosen, ?float $min = null, ?float $max = null ): array {
		$counts = array();
		foreach ( $this->index['filters'] as $filter ) {
			foreach ( $filter['values'] ?? array() as $value ) {
				$with                                     = $chosen;
				$with[ $filter['key'] ]                   = array_values( array_unique( array_merge( $with[ $filter['key'] ] ?? array(), array( $value['id'] ) ) ) );
				$counts[ $filter['key'] ][ $value['id'] ] = count( $this->shown( $with, $min, $max ) );
			}
		}
		return $counts;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function cards(): array {
		return $this->index['cards'];
	}

	/**
	 * The filter keys waived under the choice: a dependent attribute whose
	 * parent has a chosen value it does not apply under.
	 *
	 * @param array<string, list<string>> $chosen Chosen ids by key.
	 * @return array<string, true>
	 */
	private function waived( array $chosen ): array {
		$waived = array();
		foreach ( $this->index['filters'] as $filter ) {
			$parent = $filter['parent'] ?? null;
			if ( isset( $chosen[ $filter['key'] ] ) && is_array( $parent ) && array() !== array_diff( $chosen[ $parent['key'] ] ?? array(), $parent['values'] ) ) {
				$waived[ $filter['key'] ] = true;
			}
		}
		return $waived;
	}

	/**
	 * @param array<string, mixed>        $row    A variation row.
	 * @param array<string, list<string>> $chosen Chosen ids by key.
	 * @param array<string, true>         $waived Waived keys.
	 * @param float|null                  $min    `min_price`.
	 * @param float|null                  $max    `max_price`.
	 */
	private function matches( array $row, array $chosen, array $waived, ?float $min, ?float $max ): bool {
		foreach ( $chosen as $key => $ids ) {
			$held = $row['values'][ $key ] ?? null;
			if ( is_array( $held ) && array() !== array_intersect( $held, $ids ) ) {
				continue;
			}
			if ( array() === $held && isset( $waived[ $key ] ) ) {
				continue;
			}
			return false;
		}
		$low  = (float) $row['price'];
		$high = (float) ( $row['priceMax'] ?? $row['price'] );
		return ( null === $min || $high >= $min ) && ( null === $max || $low <= $max );
	}

	/**
	 * @param array<string, mixed>      $card   A shown card.
	 * @param array<string, mixed>|null $opened The variation it opens on.
	 */
	private function link( array $card, ?array $opened ): string {
		$url = (string) $card['url'];
		if ( null === $opened ) {
			return $url;
		}
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $named );
		$more = array();
		foreach ( $opened['values'] as $key => $ids ) {
			if ( in_array( $key, $card['choices'], true ) && 1 === count( $ids ) && ! isset( $named[ $key ] ) ) {
				$more[ $key ] = $ids[0];
			}
		}
		return array() === $more ? $url : add_query_arg( $more, $url );
	}

	/**
	 * @param array<string, mixed>        $card   A shown card.
	 * @param array<string, mixed>|null   $opened The variation it opens on.
	 * @param array<string, list<string>> $chosen Chosen ids by key.
	 * @param array<string, true>         $waived Waived keys.
	 */
	private function image( array $card, ?array $opened, array $chosen, array $waived ): string {
		if ( null === $opened ) {
			return (string) $card['image']['src'];
		}
		$open  = isset( $opened['openImage'] ) ? (string) $this->index['images'][ $opened['openImage'] ]['src'] : (string) $card['image']['src'];
		$holds = $card['holds'];
		if ( ! isset( $opened['image'] ) ) {
			return $open;
		}
		foreach ( $chosen as $key => $ids ) {
			if ( ! in_array( $key, $card['choices'], true ) || array() !== array_intersect( $holds[ $key ] ?? array(), $ids ) ) {
				continue;
			}
			$parent = isset( $waived[ $key ] ) ? $this->parent( $key ) : null;
			if ( null !== $parent && array() !== array_intersect( $holds[ $parent['key'] ] ?? array(), array_diff( $chosen[ $parent['key'] ] ?? array(), $parent['values'] ) ) ) {
				continue;
			}
			return (string) $this->index['images'][ $opened['image'] ]['src'];
		}
		return $open;
	}

	/**
	 * The attribute a filter depends on.
	 *
	 * @param string $key A filter's key.
	 * @return array{key: string, values: list<string>}|null
	 */
	private function parent( string $key ): ?array {
		foreach ( $this->index['filters'] as $filter ) {
			if ( $filter['key'] === $key ) {
				return $filter['parent'] ?? null;
			}
		}
		return null;
	}
}
