<?php
/**
 * The proofs a hosted designer's approval returned as one set.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/proof-sets/{proofSet}` the store reads. An approval
 * holding a side printed as pages returns a set rather than its tokens: one
 * proof for each side, and one for each page of a side of pages.
 */
final class ProofSet {

	/**
	 * @param ProofSetProof[] $proofs `proofs`, by side in the link's order, each side's pages from 1.
	 * @phpstan-param list<ProofSetProof> $proofs
	 */
	public function __construct(
		public readonly array $proofs,
	) {
	}

	/**
	 * `GET /v1/proof-sets/{proofSet}`.
	 *
	 * @param Sdk    $pixonaut  The SDK.
	 * @param string $proof_set The set's reference, as the hosted designer returned it.
	 *
	 * @throws ApiException When Pixonaut refused (`unknown_proof_set`, `proof_set_expired`, …), could not be reached, or answered without a field the store reads.
	 */
	public static function get( Sdk $pixonaut, string $proof_set ): self {
		return Read::answer( $pixonaut->call( 'proofSets.get', array( 'proofSet' => $proof_set ) ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self( Read::objects( $data, 'proofs', $path, ProofSetProof::from_answer( ... ) ) );
	}
}
