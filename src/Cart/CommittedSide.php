<?php
/**
 * One side's committed design on a cart line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

final class CommittedSide {

	/**
	 * @param string  $key              The side's key in the product configuration.
	 * @param string  $design_id        Pixonaut's design id.
	 * @param int     $revision         The design's revision when it was added.
	 * @param string  $template_base_id The template it was made from.
	 * @param int     $template_version That template's version.
	 * @param string  $commit_key       The `Idempotency-Key` the commit used.
	 * @param ?string $proof_id         The design's `proof_id` when it was added, which names its proof image; null on a line added before it was kept.
	 * @param array   $fields           Its form's values as the customer chose them; empty on a line added before they were kept, or from the hosted designer.
	 * @phpstan-param list<FieldValue> $fields
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $design_id,
		public readonly int $revision,
		public readonly string $template_base_id,
		public readonly int $template_version,
		public readonly string $commit_key,
		public readonly ?string $proof_id = null,
		public readonly array $fields = array(),
	) {
	}

	/**
	 * The side after a correction: the design's revision, proof and values
	 * now. Values the correction did not report are not kept, as they may
	 * no longer be the design's.
	 *
	 * @param int         $revision The design's revision.
	 * @param string|null $proof_id Its proof's id; null keeps the one the side has.
	 * @param array       $fields   Its form's values now.
	 * @phpstan-param list<FieldValue> $fields
	 */
	public function corrected( int $revision, ?string $proof_id, array $fields = array() ): self {
		return new self( $this->key, $this->design_id, $revision, $this->template_base_id, $this->template_version, $this->commit_key, $proof_id ?? $this->proof_id, $fields );
	}

	/**
	 * The side without its field values, once its design's personal data
	 * was erased.
	 */
	public function without_fields(): self {
		return new self( $this->key, $this->design_id, $this->revision, $this->template_base_id, $this->template_version, $this->commit_key, $this->proof_id );
	}

	/**
	 * The side naming its template by another id, as Pixonaut changed it.
	 *
	 * @param string $base_id The template's base id now.
	 */
	public function on_template( string $base_id ): self {
		return new self( $this->key, $this->design_id, $this->revision, $base_id, $this->template_version, $this->commit_key, $this->proof_id, $this->fields );
	}

	/**
	 * @return array{key: string, designId: string, revision: int, templateBaseId: string, templateVersion: int, commitKey: string, proofId: string|null, fields: list<array{label: string, type: string, value: string}>}
	 */
	public function to_array(): array {
		return array(
			'key'             => $this->key,
			'designId'        => $this->design_id,
			'revision'        => $this->revision,
			'templateBaseId'  => $this->template_base_id,
			'templateVersion' => $this->template_version,
			'commitKey'       => $this->commit_key,
			'proofId'         => $this->proof_id,
			'fields'          => FieldValue::list_to_array( $this->fields ),
		);
	}

	/**
	 * @param mixed $data Shape of to_array().
	 * @return self|null Null when a field is missing or of the wrong type.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'key', 'designId', 'templateBaseId', 'commitKey' ) as $text ) {
			if ( ! is_string( $data[ $text ] ?? null ) || '' === $data[ $text ] ) {
				return null;
			}
		}
		if ( ! is_int( $data['revision'] ?? null ) || ! is_int( $data['templateVersion'] ?? null ) ) {
			return null;
		}
		$proof_id = is_string( $data['proofId'] ?? null ) && '' !== $data['proofId'] ? $data['proofId'] : null;
		return new self( $data['key'], $data['designId'], $data['revision'], $data['templateBaseId'], $data['templateVersion'], $data['commitKey'], $proof_id, FieldValue::list_from( $data['fields'] ?? null ) );
	}
}
