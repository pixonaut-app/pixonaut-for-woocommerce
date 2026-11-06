<?php
/**
 * The store's designs in Pixonaut: what the plugin adds to the SDK's calls.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsApproveRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsApproveRequestConsent;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsFindOrCreateStandingRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Binary;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Model\Omitted;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * Committing, deleting and erasing a design the way the store needs it, and
 * its proof and mockup images, checked before they are kept. Everything else
 * about a design is the SDK's `designs()`.
 */
final class Designs {

	/**
	 * The bytes every JPEG starts with: its start-of-image marker and the
	 * first byte of the next marker.
	 */
	private const JPEG_START = "\xFF\xD8\xFF";

	/**
	 * The signature every PNG starts with.
	 */
	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	private const JPEG = 'image/jpeg';

	private const PNG = 'image/png';

	/*
	 * The fields of a design (`GET /v1/designs/{id}`, as its model's `raw()`) the store reads.
	 */
	public const TEMPLATE_BASE_ID        = 'templateBaseId';
	public const TEMPLATE_VERSION        = 'templateVersion';
	public const PROOF_ID                = 'proofId';
	public const EXTERNAL_REF            = 'externalRef';
	public const BLANK_ID                = 'blankId';
	public const PLACEMENT_ID            = 'placementId';
	public const PRINT_SIZE              = 'printSize';
	public const PERSONAL_DATA_ERASED_AT = 'personalDataErasedAt';

	public function __construct( private readonly Sdk $pixonaut ) {
	}

	/**
	 * `POST /v1/designs`: commits the proof a customer approved. A proof
	 * committed before (`409 proof_already_committed`) is adopted: its
	 * design's id is answered as if this call had made it. The fulfilment
	 * payload carries the printshop keys known at commit; those only an
	 * order can carry (a recipient) are checked when the order is submitted.
	 *
	 * @param string               $proof_token        The proof token the storefront sent.
	 * @param string               $external_ref       The store's reference for the design.
	 * @param string               $idempotency_key    Key for the commit; the same token always gets the same key.
	 * @param array<string, mixed> $fulfilment_payload Fulfilment keys known at commit, such as `placement`; none is sent when empty.
	 * @param bool|null            $preview_opened     Whether the customer opened the preview before ticking the approval box; null, and left out, where no box asked.
	 *
	 * @return string The design's id.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function commit( string $proof_token, string $external_ref, string $idempotency_key, array $fulfilment_payload = array(), ?bool $preview_opened = null ): string {
		$body = new DesignsCommitRequestOption1(
			proofToken: $proof_token,
			fulfilmentPayload: array() === $fulfilment_payload ? Omitted::Field : $fulfilment_payload,
			externalRef: $external_ref,
			previewOpened: $preview_opened,
		);
		try {
			$id = $this->pixonaut->designs()->commit( $body, idempotencyKey: $idempotency_key )->designId;
		} catch ( ApiException $error ) {
			$adopted = $error->details['designId'] ?? null;
			if ( ErrorCode::PROOF_ALREADY_COMMITTED === $error->errorCode && is_string( $adopted ) && '' !== $adopted ) {
				return $adopted;
			}
			throw $error;
		}
		return self::id( 'designs.commit', $id );
	}

	/**
	 * `POST /v1/designs/standing`: the store's standing design for a
	 * template version on a blank's placement at a print size, found or
	 * created, and approved. Pixonaut keeps one per (store, version, blank,
	 * placement, size), so the same call again answers the same design; it
	 * takes no idempotency key. The store's integration key makes it, and
	 * only that key's store finds it again. Ordered any number of times, it
	 * reuses its print. On a blank whose print areas change with an option,
	 * Pixonaut keeps one per row of those options, and refuses a call that
	 * names no row with `variant_required`.
	 *
	 * With a layout adjustment's proof (`proofToken`), Pixonaut makes a
	 * standing design of its own from the proof's document, found again by
	 * the same proof; the design the proof was laid out from is unchanged.
	 * The proof must adjust the standing design of the same version, blank,
	 * placement, size and row.
	 *
	 * @param string                     $template_id  `<baseId>@<version>`.
	 * @param string|null                $blank_id     The blank; null for a design on none.
	 * @param string|null                $placement_id The placement, with the blank.
	 * @param PrintSize|null             $size         The print size; null for the placement's default, or the row's usual size.
	 * @param array<string, string>|null $variant_row  The row the design is for, `Size => XL` (`variantRow`); null for none.
	 * @param string|null                $proof_token  A layout adjustment's proof, from a correction link minted with `adjustLayout`; null for the design as the template's author left it.
	 *
	 * @return string The design's id.
	 *
	 * @throws ApiException When Pixonaut refused (`forbidden`, `constraint_violation`, `unknown_placement`, `variant_required`, `not_a_layout_proof`, `layout_proof_mismatch`, `unknown_proof`, …) or could not be reached.
	 */
	public function standing( string $template_id, ?string $blank_id, ?string $placement_id, ?PrintSize $size, ?array $variant_row = null, ?string $proof_token = null ): string {
		$on_blank = null !== $blank_id && null !== $placement_id;
		$body     = new DesignsFindOrCreateStandingRequest(
			templateId: $template_id,
			approve: true,
			blankId: $on_blank ? $blank_id : null,
			heightMm: $size?->height_mm,
			placementId: $on_blank ? $placement_id : null,
			proofToken: $proof_token,
			variantRow: $variant_row,
			widthMm: $size?->width_mm,
		);
		return self::id( 'designs.findOrCreateStanding', $this->pixonaut->designs()->findOrCreateStanding( $body )->designId );
	}

	/**
	 * `GET /v1/designs/{id}/proof?v&width`: the proof image the customer was
	 * shown. `v` names its bytes, so an answer never changes.
	 *
	 * @param string $id       Design id.
	 * @param string $proof_id The design's `proofId`; another one is refused `proof_changed`.
	 * @param int    $width    Width in pixels the image is drawn at.
	 *
	 * @return string The JPEG's bytes.
	 *
	 * @throws ApiException When Pixonaut refused (`proof_changed`, `proof_not_found`, `not_found`, …) or could not be reached.
	 */
	public function proof( string $id, string $proof_id, int $width ): string {
		$proof = $this->pixonaut->designs()->getProof( $id, $proof_id, (string) $width );
		// A proxy's error page answered 200 must not be kept and served as the proof.
		return self::image( 'designs.proof.get', $proof, self::JPEG, self::JPEG_START );
	}

	/**
	 * `GET /v1/designs/{id}/mockup.png?v&viewId&size`: the design drawn on its
	 * blank's photo, in its own placement and on the variant its proof was
	 * previewed on, as `GET /t/{id}/mockup.png` draws a template. `v` names
	 * the picture, so an answer never changes.
	 *
	 * @param string      $id       Design id.
	 * @param string      $proof_id The design's `proofId`; another one is refused `proof_changed`.
	 * @param string|null $view_id  One of the placement's views; null for the view the proof was previewed in, else the placement's first.
	 * @param int         $size     The long edge in pixels.
	 * @param string|null $variant  The variant whose photo it is drawn on, `v_…`; null for the one the proof was previewed on, else the view's default photo.
	 *
	 * @return string The PNG's bytes.
	 *
	 * @throws ApiException When Pixonaut refused (`proof_changed`, `proof_not_found`, `not_found`, …) or could not be reached.
	 */
	public function mockup( string $id, string $proof_id, ?string $view_id, int $size, ?string $variant = null ): string {
		$mockup = $this->pixonaut->designs()->getMockup( $id, viewId: $view_id, variant: $variant, size: (string) $size, v: $proof_id );
		// A proxy's error page answered 200 must not be kept and served as the picture.
		return self::image( 'designs.mockup.get', $mockup, self::PNG, self::PNG_SIGNATURE );
	}

	/**
	 * `DELETE /v1/designs/{id}`. A design already gone counts as deleted.
	 *
	 * @param string $id Design id.
	 *
	 * @throws ApiException When Pixonaut refused (`design_approved`, `design_printed`, …) or could not be reached.
	 */
	public function delete( string $id ): void {
		try {
			$this->pixonaut->designs()->delete( $id );
		} catch ( ApiException $error ) {
			if ( ErrorCode::NOT_FOUND !== $error->errorCode ) {
				throw $error;
			}
		}
	}

	/**
	 * `POST /v1/designs/{id}/erase-personal-data`: erases what the customer
	 * put into the design, whatever its status. Erasing it again answers it
	 * unchanged; a deleted design is erased too (`204`).
	 *
	 * @param string $id Design id.
	 *
	 * @return int|null When its personal data was erased, epoch milliseconds; null when the design is deleted, or unknown to Pixonaut.
	 *
	 * @throws ApiException When Pixonaut refused (`lock_timeout`, …) or could not be reached.
	 */
	public function erase_personal_data( string $id ): ?int {
		try {
			$design = $this->pixonaut->designs()->erasePersonalData( $id );
		} catch ( ApiException $error ) {
			if ( ErrorCode::NOT_FOUND === $error->errorCode ) {
				return null;
			}
			throw $error;
		}
		if ( null === $design ) {
			return null;
		}
		if ( null === $design->personalDataErasedAt ) {
			throw new ApiException( 'designs.erasePersonalData', 200, ApiException::UNEXPECTED_RESPONSE, false );
		}
		return (int) $design->personalDataErasedAt;
	}

	/**
	 * The body of `POST /v1/designs/{id}/approve` for a customer's approval
	 * of a design at a revision. Approving it again at the same revision
	 * answers `200` again; another revision is refused.
	 *
	 * @param array{kind: string, at: int, ref?: string} $consent           How and when the customer agreed (`at` in epoch milliseconds), and the store's reference for it.
	 * @param int                                        $expected_revision The revision the customer approved.
	 */
	public static function approval( array $consent, int $expected_revision ): DesignsApproveRequest {
		return new DesignsApproveRequest(
			consent: new DesignsApproveRequestConsent(
				at: $consent['at'],
				kind: $consent['kind'],
				ref: $consent['ref'] ?? Omitted::Field,
			),
			expectedRevision: $expected_revision,
		);
	}

	/**
	 * A design id Pixonaut answered, once it is one.
	 *
	 * @param string $operation SDK operation id, for the error.
	 * @param string $id        The `designId` answered.
	 *
	 * @throws ApiException `unexpected_response` when it is empty.
	 */
	private static function id( string $operation, string $id ): string {
		if ( '' === $id ) {
			throw new ApiException( $operation, 200, ApiException::UNEXPECTED_RESPONSE, false );
		}
		return $id;
	}

	/**
	 * An image Pixonaut answered, once it is what the operation draws.
	 *
	 * @param string $operation SDK operation id, for the error.
	 * @param Binary $image     The answer.
	 * @param string $media     The media type it must be.
	 * @param string $signature The bytes it must start with.
	 *
	 * @throws ApiException `unexpected_response` when it is not.
	 */
	private static function image( string $operation, Binary $image, string $media, string $signature ): string {
		$type = strtolower( trim( explode( ';', $image->contentType )[0] ) );
		if ( $media !== $type || ! str_starts_with( $image->bytes, $signature ) ) {
			throw new ApiException( $operation, 200, ApiException::UNEXPECTED_RESPONSE, false, null, $image->requestId );
		}
		return $image->bytes;
	}
}
