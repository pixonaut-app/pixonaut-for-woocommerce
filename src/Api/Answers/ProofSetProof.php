<?php
/**
 * One proof of a proof set.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An entry of a proof set's `proofs`: a side's proof, or one page's.
 */
final class ProofSetProof {

	/**
	 * @param string|null $key         `key`, the side's key; null on a link of one side.
	 * @param int|null    $page        `page`, from 1 on a side printed as pages; null on any other side.
	 * @param string      $proof_token `proofToken`, committed as a side's token is.
	 */
	public function __construct(
		public readonly ?string $key,
		public readonly ?int $page,
		public readonly string $proof_token,
	) {
	}

	/**
	 * @param array<string, mixed> $data The entry.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			key: Read::nullable_string( $data, 'key', $path ),
			page: Read::nullable_int( $data, 'page', $path ),
			proof_token: Read::string( $data, 'proofToken', $path ),
		);
	}
}
