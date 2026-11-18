<?php
/**
 * The rule one price follows, part by part, and where each part comes from.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * Each part of the rule is looked for on its own, the most specific level
 * first: the variation, its product, the values of the global attributes
 * the variation has, the store. A part a level leaves empty follows the
 * level after it. Two attribute values that set the same part conflict:
 * the attribute first in the store's attribute order wins.
 *
 * @phpstan-type Conflict array{part: string, winner: RuleSource, loser: RuleSource}
 */
final class ItemRule {

	/**
	 * @param PriceRule                 $rule      The rule its parts make.
	 * @param array<string, string>     $parts     Each part in force as written, by part; empty where no level sets it.
	 * @param array<string, RuleSource> $sources   Where each part that is set comes from, by part.
	 * @param array[]                   $conflicts The parts two attribute values set, and which one wins.
	 * @phpstan-param list<Conflict> $conflicts
	 */
	public function __construct(
		public readonly PriceRule $rule,
		public readonly array $parts,
		public readonly array $sources,
		public readonly array $conflicts = array(),
	) {
	}

	/**
	 * The rule its levels make.
	 *
	 * @param list<array{0: RuleSource, 1: array<string, mixed>}> $layers Each level's source and its parts as written, the most specific first, attribute values in the store's attribute order.
	 */
	public static function resolve( array $layers ): self {
		$parts     = array();
		$sources   = array();
		$conflicts = array();
		foreach ( PriceRule::PARTS as $part ) {
			$parts[ $part ] = '';
			foreach ( $layers as list( $source, $written ) ) {
				$value = $written[ $part ] ?? '';
				if ( ! PriceRule::is_part( $value ) || '' === PriceRule::part( $value ) ) {
					continue;
				}
				$winner = $sources[ $part ] ?? null;
				if ( null === $winner ) {
					$parts[ $part ]   = PriceRule::part( $value );
					$sources[ $part ] = $source;
					continue;
				}
				// A part the variation or the product sets decides it, whatever its attribute values say.
				if ( RuleSource::ATTRIBUTE === $winner->level && RuleSource::ATTRIBUTE === $source->level && ! $winner->is( $source ) ) {
					$conflicts[] = array(
						'part'   => $part,
						'winner' => $winner,
						'loser'  => $source,
					);
				}
			}
		}
		return new self( PriceRule::from_parts( $parts ), $parts, $sources, $conflicts );
	}

	/**
	 * The levels the rule's parts come from, each once, the most specific
	 * first; attribute values in the order of the parts they set.
	 *
	 * @return list<RuleSource>
	 */
	public function levels(): array {
		$levels = array();
		foreach ( RuleSource::LEVELS as $level ) {
			foreach ( $this->sources as $source ) {
				if ( $source->level !== $level ) {
					continue;
				}
				$known = array_filter( $levels, static fn ( RuleSource $seen ): bool => $seen->is( $source ) );
				if ( array() === $known ) {
					$levels[] = $source;
				}
			}
		}
		return $levels;
	}
}
