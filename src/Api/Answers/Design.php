<?php
/**
 * A design, as the store reads it from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/designs/{id}` the store reads.
 */
final class Design {

	/**
	 * @param string               $id                      `id`.
	 * @param string               $status                  `status`: `committed`, `approved`, `rejected`, ….
	 * @param int                  $revision                `revision`, raised by every change to the design.
	 * @param string               $template_base_id        `templateBaseId`.
	 * @param int|null             $template_version        `templateVersion`; null for a design of no published version.
	 * @param string|null          $blank_id                `blankId`; null for a design on no blank.
	 * @param string|null          $placement_id            `placementId`.
	 * @param DesignPrintSize|null $print_size              `printSize`.
	 * @param string|null          $proof_id                `proofId`, naming the proof's bytes.
	 * @param string|null          $external_ref            `externalRef`, the store's reference for it.
	 * @param float|null           $personal_data_erased_at `personalDataErasedAt`, epoch milliseconds; null while not erased.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly int $revision,
		public readonly string $template_base_id,
		public readonly ?int $template_version,
		public readonly ?string $blank_id,
		public readonly ?string $placement_id,
		public readonly ?DesignPrintSize $print_size,
		public readonly ?string $proof_id,
		public readonly ?string $external_ref,
		public readonly ?float $personal_data_erased_at,
	) {
	}

	/**
	 * `GET /v1/designs/{id}`.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       Design id.
	 *
	 * @throws ApiException When Pixonaut refused (`not_found`, …), could not be reached, or answered without a field the store reads.
	 */
	public static function get( Sdk $pixonaut, string $id ): self {
		return Read::answer( $pixonaut->call( 'designs.get', array( 'id' => $id ) ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			status: Read::string( $data, 'status', $path ),
			revision: Read::int( $data, 'revision', $path ),
			template_base_id: Read::string( $data, 'templateBaseId', $path ),
			template_version: Read::nullable_int( $data, 'templateVersion', $path ),
			blank_id: Read::nullable_string( $data, 'blankId', $path ),
			placement_id: Read::nullable_string( $data, 'placementId', $path ),
			print_size: Read::nullable_object( $data, 'printSize', $path, DesignPrintSize::from_answer( ... ) ),
			proof_id: Read::nullable_string( $data, 'proofId', $path ),
			external_ref: Read::nullable_string( $data, 'externalRef', $path ),
			personal_data_erased_at: Read::nullable_float( $data, 'personalDataErasedAt', $path ),
		);
	}
}
