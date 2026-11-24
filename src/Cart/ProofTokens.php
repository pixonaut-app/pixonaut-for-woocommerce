<?php
/**
 * The proof tokens a hosted designer's approval returned.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Api\Answers\ProofSet;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;

/**
 * Proof tokens by side key: a side's one token, or for a side printed as
 * pages, its pages' tokens by page, from 1 with none missing. A return holds
 * either kind of side; each page is committed as a design of its own.
 *
 * @phpstan-type Tokens array<string, string|array<int, string>>
 */
final class ProofTokens {

	/**
	 * The first page of a side printed as pages.
	 */
	private const FIRST_PAGE = 1;

	/**
	 * What the hosted designer's return address carried: `proofToken`, the
	 * only side's; `proofToken[<side key>]`, a side's; or
	 * `proofToken[<side key>][<page>]`, a page's. Also reads the tokens a
	 * visit kept in the session.
	 *
	 * @param ProductConfig $config   The product's configuration, for the side a bare token or page is for.
	 * @param mixed         $returned What was returned.
	 *
	 * @return array<string, string|array<int, string>> Empty when the return names no side, or a side's pages do not run from 1 without a gap.
	 */
	public static function returned( ProductConfig $config, mixed $returned ): array {
		$only = 1 === count( $config->sides ) ? $config->sides[0]->key : null;
		if ( is_string( $returned ) ) {
			return null === $only ? array() : array( $only => $returned );
		}
		if ( ! is_array( $returned ) ) {
			return array();
		}
		// `proofToken[<page>]` on a link of one side: PHP reads the pages as integer keys.
		if ( array() !== $returned && array_filter( array_keys( $returned ), 'is_int' ) === array_keys( $returned ) ) {
			$pages = self::pages( $returned );
			return null === $only || null === $pages ? array() : array( $only => $pages );
		}
		return self::stored( $returned );
	}

	/**
	 * The tokens of a proof set, by side; a set naming no side's key is the
	 * only side's.
	 *
	 * @param ProductConfig $config The product's configuration.
	 * @param ProofSet      $set    The set Pixonaut answered.
	 *
	 * @return array<string, string|array<int, string>> Empty when a proof is for no side the product can name, a side repeats, or a side's pages do not run from 1 without a gap or repeat.
	 */
	public static function of_set( ProductConfig $config, ProofSet $set ): array {
		$only   = 1 === count( $config->sides ) ? $config->sides[0]->key : null;
		$tokens = array();
		foreach ( $set->proofs as $proof ) {
			$key = $proof->key ?? $only;
			if ( null === $key || '' === $proof->proof_token ) {
				return array();
			}
			if ( null === $proof->page ) {
				if ( isset( $tokens[ $key ] ) ) {
					return array();
				}
				$tokens[ $key ] = $proof->proof_token;
				continue;
			}
			$pages = $tokens[ $key ] ?? array();
			if ( ! is_array( $pages ) || isset( $pages[ $proof->page ] ) ) {
				return array();
			}
			$pages[ $proof->page ] = $proof->proof_token;
			$tokens[ $key ]        = $pages;
		}
		foreach ( $tokens as $key => $token ) {
			if ( is_array( $token ) ) {
				$pages = self::pages( $token );
				if ( null === $pages ) {
					return array();
				}
				$tokens[ $key ] = $pages;
			}
		}
		return $tokens;
	}

	/**
	 * Tokens as a visit kept them: anything else is left out.
	 *
	 * @param mixed $stored The kept tokens.
	 *
	 * @return array<string, string|array<int, string>>
	 */
	public static function stored( mixed $stored ): array {
		$tokens = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $token ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( is_string( $token ) ) {
				$tokens[ $key ] = $token;
			} elseif ( is_array( $token ) ) {
				$pages = self::pages( $token );
				if ( null !== $pages ) {
					$tokens[ $key ] = $pages;
				}
			}
		}
		return $tokens;
	}

	/**
	 * Each token by the slot of the design it commits: a side's key, or its
	 * key and a page (`CommittedSide::slot()`), in the order given.
	 *
	 * @param array<string, string|array<int, string>> $tokens Tokens by side key.
	 *
	 * @return array<string, string>
	 */
	public static function by_slot( array $tokens ): array {
		$slots = array();
		foreach ( $tokens as $key => $token ) {
			if ( is_string( $token ) ) {
				$slots[ CommittedSide::slot( $key, null ) ] = $token;
				continue;
			}
			foreach ( $token as $page => $page_token ) {
				$slots[ CommittedSide::slot( $key, $page ) ] = $page_token;
			}
		}
		return $slots;
	}

	/**
	 * A side's pages, by page from 1.
	 *
	 * @param array<mixed, mixed> $pages Tokens by page.
	 *
	 * @return array<int, string>|null Null when a page is not a positive whole number, a token is not a string, or the pages skip one.
	 */
	private static function pages( array $pages ): ?array {
		$read = array();
		foreach ( $pages as $page => $token ) {
			$number = is_int( $page ) || ctype_digit( $page ) ? (int) $page : 0;
			if ( $number < self::FIRST_PAGE || ! is_string( $token ) || '' === $token ) {
				return null;
			}
			$read[ $number ] = $token;
		}
		ksort( $read );
		return array() !== $read && array_keys( $read ) === range( self::FIRST_PAGE, count( $read ) ) ? $read : null;
	}
}
