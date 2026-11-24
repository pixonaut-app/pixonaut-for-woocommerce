<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Cart;

use Pixonaut\WooCommerce\Api\Answers\ProofSet;
use Pixonaut\WooCommerce\Api\Answers\ProofSetProof;
use Pixonaut\WooCommerce\Cart\ProofTokens;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ProofTokensTest extends TestCase {

	private static function config( string ...$keys ): ProductConfig {
		return new ProductConfig( true, false, 'blk_notebook', null, array_values( array_map( static fn ( string $key ): Side => new Side( $key, ucfirst( $key ), $key, null, 'notebook', 1, null, null ), $keys ) ) );
	}

	private static function set( ProofSetProof ...$proofs ): ProofSet {
		return new ProofSet( array_values( $proofs ) );
	}

	public function test_a_proof_set_reads_as_each_sides_token_and_a_book_sides_tokens_by_page(): void {
		$set = self::set(
			new ProofSetProof( 'cover', null, 'prf.cover' ),
			new ProofSetProof( 'pages', 1, 'prf.p1' ),
			new ProofSetProof( 'pages', 2, 'prf.p2' ),
		);

		$this->assertSame(
			array(
				'cover' => 'prf.cover',
				'pages' => array(
					1 => 'prf.p1',
					2 => 'prf.p2',
				),
			),
			ProofTokens::of_set( self::config( 'cover', 'pages' ), $set )
		);
	}

	public function test_a_set_naming_no_side_is_the_only_sides(): void {
		$set = self::set( new ProofSetProof( null, 2, 'prf.p2' ), new ProofSetProof( null, 1, 'prf.p1' ) );

		$this->assertSame(
			array(
				'pages' => array(
					1 => 'prf.p1',
					2 => 'prf.p2',
				),
			),
			ProofTokens::of_set( self::config( 'pages' ), $set )
		);
		$this->assertSame( array(), ProofTokens::of_set( self::config( 'cover', 'pages' ), $set ) );
	}

	/**
	 * @return array<string, array{0: list<ProofSetProof>}>
	 */
	public static function unusable_sets(): array {
		return array(
			'a page missing'         => array( array( new ProofSetProof( 'pages', 1, 'prf.p1' ), new ProofSetProof( 'pages', 3, 'prf.p3' ) ) ),
			'a page repeated'        => array( array( new ProofSetProof( 'pages', 1, 'prf.p1' ), new ProofSetProof( 'pages', 1, 'prf.again' ) ) ),
			'no first page'          => array( array( new ProofSetProof( 'pages', 2, 'prf.p2' ) ) ),
			'a side repeated'        => array( array( new ProofSetProof( 'cover', null, 'prf.a' ), new ProofSetProof( 'cover', null, 'prf.b' ) ) ),
			'a side both ways'       => array( array( new ProofSetProof( 'pages', null, 'prf.a' ), new ProofSetProof( 'pages', 1, 'prf.p1' ) ) ),
			'an empty token'         => array( array( new ProofSetProof( 'cover', null, '' ) ) ),
			'a page before its side' => array( array( new ProofSetProof( 'pages', 1, 'prf.p1' ), new ProofSetProof( 'pages', null, 'prf.a' ) ) ),
		);
	}

	/**
	 * @dataProvider unusable_sets
	 *
	 * @param ProofSetProof[] $proofs The set's proofs.
	 * @phpstan-param list<ProofSetProof> $proofs
	 */
	public function test_a_set_whose_pages_do_not_run_from_one_without_a_gap_is_refused( array $proofs ): void {
		$this->assertSame( array(), ProofTokens::of_set( self::config( 'cover', 'pages' ), new ProofSet( $proofs ) ) );
	}

	public function test_a_return_address_carries_a_token_a_side_or_a_page(): void {
		$this->assertSame( array( 'pages' => 'prf.one' ), ProofTokens::returned( self::config( 'pages' ), 'prf.one' ) );
		$this->assertSame( array(), ProofTokens::returned( self::config( 'cover', 'pages' ), 'prf.one' ) );
		$this->assertSame(
			array(
				'pages' => array(
					1 => 'prf.p1',
					2 => 'prf.p2',
				),
			),
			ProofTokens::returned(
				self::config( 'pages' ),
				array(
					'2' => 'prf.p2',
					'1' => 'prf.p1',
				)
			)
		);
		$this->assertSame(
			array(
				'cover' => 'prf.cover',
				'pages' => array( 1 => 'prf.p1' ),
			),
			ProofTokens::returned(
				self::config( 'cover', 'pages' ),
				array(
					'cover' => 'prf.cover',
					'pages' => array( '1' => 'prf.p1' ),
					'back'  => array( '2' => 'prf.gap' ),
				)
			)
		);
	}

	public function test_each_token_goes_by_the_slot_of_the_design_it_commits(): void {
		$this->assertSame(
			array(
				'cover'   => 'prf.cover',
				'pages#1' => 'prf.p1',
				'pages#2' => 'prf.p2',
			),
			ProofTokens::by_slot(
				array(
					'cover' => 'prf.cover',
					'pages' => array(
						1 => 'prf.p1',
						2 => 'prf.p2',
					),
				)
			)
		);
	}
}
