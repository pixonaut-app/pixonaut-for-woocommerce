<?php
/**
 * The store's designs in Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Catalogue\PrintSize;

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

	/**
	 * What an erasure of a deleted design answers.
	 */
	private const NO_CONTENT = 204;

	/*
	 * The fields of a design (`GET /v1/designs/{id}`, and the erasure's answer) the store reads.
	 */
	public const TEMPLATE_BASE_ID        = 'templateBaseId';
	public const TEMPLATE_VERSION        = 'templateVersion';
	public const PROOF_ID                = 'proofId';
	public const EXTERNAL_REF            = 'externalRef';
	public const BLANK_ID                = 'blankId';
	public const PLACEMENT_ID            = 'placementId';
	public const PRINT_SIZE              = 'printSize';
	public const PERSONAL_DATA_ERASED_AT = 'personalDataErasedAt';

	public function __construct( private readonly Client $client ) {
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
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function commit( string $proof_token, string $external_ref, string $idempotency_key, array $fulfilment_payload = array(), ?bool $preview_opened = null ): string {
		$body = array(
			'proofToken'  => $proof_token,
			'externalRef' => $external_ref,
		);
		if ( array() !== $fulfilment_payload ) {
			$body['fulfilmentPayload'] = $fulfilment_payload;
		}
		if ( null !== $preview_opened ) {
			$body['previewOpened'] = $preview_opened;
		}
		try {
			$response = $this->client->call( Operations::COMMIT_DESIGN, array(), array(), $body, $idempotency_key );
		} catch ( ApiError $error ) {
			$adopted = $error->details['designId'] ?? null;
			if ( ErrorCodes::PROOF_ALREADY_COMMITTED === $error->error_code && is_string( $adopted ) && '' !== $adopted ) {
				return $adopted;
			}
			throw $error;
		}
		$id = $this->client->json( Operations::COMMIT_DESIGN, $response )['designId'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw ApiError::unexpected( Operations::COMMIT_DESIGN, $response );
		}
		return $id;
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
	 * @throws ApiError When Pixonaut refused (`forbidden`, `constraint_violation`, `unknown_placement`, `variant_required`, `not_a_layout_proof`, `layout_proof_mismatch`, `unknown_proof`, …) or could not be reached.
	 */
	public function standing( string $template_id, ?string $blank_id, ?string $placement_id, ?PrintSize $size, ?array $variant_row = null, ?string $proof_token = null ): string {
		$body = array(
			'templateId' => $template_id,
			'approve'    => true,
		);
		if ( null !== $blank_id && null !== $placement_id ) {
			$body += array(
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
			);
		}
		if ( null !== $size ) {
			$body += array(
				'widthMm'  => $size->width_mm,
				'heightMm' => $size->height_mm,
			);
		}
		if ( null !== $variant_row ) {
			$body['variantRow'] = $variant_row;
		}
		if ( null !== $proof_token ) {
			$body['proofToken'] = $proof_token;
		}
		$response = $this->client->call( Operations::FIND_OR_CREATE_STANDING_DESIGN, array(), array(), $body );
		$id       = $this->client->json( Operations::FIND_OR_CREATE_STANDING_DESIGN, $response )['designId'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw ApiError::unexpected( Operations::FIND_OR_CREATE_STANDING_DESIGN, $response );
		}
		return $id;
	}

	/**
	 * `GET /v1/designs/{id}`.
	 *
	 * @param string $id Design id.
	 *
	 * @return array<string, mixed> The design: `printSize`, `templateBaseId`, `templateVersion`, `blankId`, `placementId`, `revision`, `status`, `externalRef`, ….
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function get( string $id ): array {
		return $this->client->json( Operations::GET_DESIGN, $this->client->call( Operations::GET_DESIGN, array( 'id' => $id ) ) );
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
	 * @throws ApiError When Pixonaut refused (`proof_changed`, `proof_not_found`, `not_found`, …) or could not be reached.
	 */
	public function proof( string $id, string $proof_id, int $width ): string {
		$response = $this->client->call(
			Operations::GET_DESIGN_PROOF,
			array( 'id' => $id ),
			array(
				'v'     => $proof_id,
				'width' => $width,
			)
		);
		// A proxy's error page answered 200 must not be kept and served as the proof.
		$media = strtolower( trim( explode( ';', (string) $response->header( 'Content-Type' ) )[0] ) );
		if ( Operation::JPEG !== $media || ! str_starts_with( $response->body, self::JPEG_START ) ) {
			throw ApiError::unexpected( Operations::GET_DESIGN_PROOF, $response );
		}
		return $response->body;
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
	 * @throws ApiError When Pixonaut refused (`proof_changed`, `proof_not_found`, `not_found`, …) or could not be reached.
	 */
	public function mockup( string $id, string $proof_id, ?string $view_id, int $size, ?string $variant = null ): string {
		$response = $this->client->call(
			Operations::DESIGN_MOCKUP,
			array( 'id' => $id ),
			array(
				'v'       => $proof_id,
				'viewId'  => $view_id,
				'size'    => $size,
				'variant' => $variant,
			)
		);
		// A proxy's error page answered 200 must not be kept and served as the picture.
		$media = strtolower( trim( explode( ';', (string) $response->header( 'Content-Type' ) )[0] ) );
		if ( Operation::PNG !== $media || ! str_starts_with( $response->body, self::PNG_SIGNATURE ) ) {
			throw ApiError::unexpected( Operations::DESIGN_MOCKUP, $response );
		}
		return $response->body;
	}

	/**
	 * `DELETE /v1/designs/{id}`. A design already gone counts as deleted.
	 *
	 * @param string $id Design id.
	 *
	 * @throws ApiError When Pixonaut refused (`design_approved`, `design_printed`, …) or could not be reached.
	 */
	public function delete( string $id ): void {
		try {
			$this->client->call( Operations::DELETE_DESIGN, array( 'id' => $id ) );
		} catch ( ApiError $error ) {
			if ( ErrorCodes::NOT_FOUND !== $error->error_code ) {
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
	 * @throws ApiError When Pixonaut refused (`lock_timeout`, …) or could not be reached.
	 */
	public function erase_personal_data( string $id ): ?int {
		try {
			$response = $this->client->call( Operations::ERASE_DESIGN, array( 'id' => $id ) );
		} catch ( ApiError $error ) {
			if ( ErrorCodes::NOT_FOUND === $error->error_code ) {
				return null;
			}
			throw $error;
		}
		if ( self::NO_CONTENT === $response->status ) {
			return null;
		}
		$erased = $this->client->json( Operations::ERASE_DESIGN, $response )[ self::PERSONAL_DATA_ERASED_AT ] ?? null;
		if ( ! is_int( $erased ) && ! is_float( $erased ) ) {
			throw ApiError::unexpected( Operations::ERASE_DESIGN, $response );
		}
		return (int) $erased;
	}

	/**
	 * `PUT /v1/designs/{id}/external-ref`: replaces the store's reference.
	 *
	 * @param string $id           Design id.
	 * @param string $external_ref The new reference.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function set_external_ref( string $id, string $external_ref ): void {
		$this->client->call( Operations::SET_DESIGN_EXTERNAL_REF, array( 'id' => $id ), array(), array( 'externalRef' => $external_ref ) );
	}

	/**
	 * `POST /v1/designs/{id}/approve`: approves a committed design for print.
	 * Approving it again at the same revision answers `200` again.
	 *
	 * @param string                                     $design_id         The design.
	 * @param array{kind: string, at: int, ref?: string} $consent           How and when the customer agreed (`at` in epoch milliseconds), and the store's reference for it.
	 * @param int                                        $expected_revision The revision the customer approved; another one is refused.
	 *
	 * @return array<string, mixed> `status`: `approved`, or `scheduled` for a deferred approval; an open set.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function approve( string $design_id, array $consent, int $expected_revision ): array {
		$response = $this->client->call(
			Operations::APPROVE_DESIGN,
			array( 'id' => $design_id ),
			array(),
			array(
				'consent'          => $consent,
				'expectedRevision' => $expected_revision,
			)
		);
		return $this->client->json( Operations::APPROVE_DESIGN, $response );
	}

	/**
	 * `POST /v1/designs/{id}/correction-links`: a link that lets the
	 * customer's browser reopen this one design in the designer, for 2 hours.
	 * With a `returnUrl` it also opens Pixonaut's hosted correction page,
	 * which sends the customer back there with `state`, and the correction's
	 * `proofToken` once they approve it. With `$adjust_layout`, the link opens
	 * a standing design for its layout alone (`adjustLayout`): its printed
	 * layers moved and scaled, nothing else; its proof makes a standing
	 * design of its own (`standing()`), never a correction.
	 *
	 * @param string      $design_id     The design.
	 * @param string|null $return_url    Where the hosted correction page returns to; null for the SDK's credential only.
	 * @param string|null $state         Carried back to `$return_url` untouched; only with it.
	 * @param bool        $adjust_layout Whether the link adjusts a standing design's layout rather than correcting the design.
	 *
	 * @return array{token: string, expiresAt: int, url: string|null} The SDK's `correctionLink`, when it stops working in epoch milliseconds, and the hosted page when a return address was given.
	 *
	 * @throws ApiError When Pixonaut refused (`not_editable`, `not_standing`, `return_url_off_store`, …) or could not be reached.
	 */
	public function correction_link( string $design_id, ?string $return_url = null, ?string $state = null, bool $adjust_layout = false ): array {
		$body = $adjust_layout ? array( 'adjustLayout' => true ) : array();
		if ( null !== $return_url ) {
			$body['returnUrl'] = $return_url;
			if ( null !== $state ) {
				$body['state'] = $state;
			}
		}
		$response = $this->client->call( Operations::CREATE_CORRECTION_LINK, array( 'id' => $design_id ), array(), $body );
		$link     = $this->client->json( Operations::CREATE_CORRECTION_LINK, $response );
		$url      = $link['url'] ?? null;
		if ( ! is_string( $link['token'] ?? null ) || '' === $link['token'] || ! is_numeric( $link['expiresAt'] ?? null ) || ! ( null === $url || is_string( $url ) ) || ( null !== $return_url && ! is_string( $url ) ) ) {
			throw ApiError::unexpected( Operations::CREATE_CORRECTION_LINK, $response );
		}
		return array(
			'token'     => $link['token'],
			'expiresAt' => (int) $link['expiresAt'],
			'url'       => $url,
		);
	}

	/**
	 * `PATCH /v1/designs/{id}` with a correction's proof token: makes the
	 * correction the design's next revision, which clears any approval.
	 *
	 * @param string $design_id   The design.
	 * @param string $proof_token The token the correction mount's `approve()` resolved.
	 *
	 * @return array{revision: int, edited: bool, proofId: string|null} The design's revision and proof now; `edited` is false when the correction changed nothing.
	 *
	 * @throws ApiError When Pixonaut refused (`revision_changed`, `proof_expired`, `not_editable`, …) or could not be reached.
	 */
	public function correct( string $design_id, string $proof_token ): array {
		$response = $this->client->call( Operations::CORRECT_DESIGN, array( 'id' => $design_id ), array(), array( 'proofToken' => $proof_token ) );
		$answer   = $this->client->json( Operations::CORRECT_DESIGN, $response );
		$proof_id = $answer['proofId'] ?? null;
		if ( ! is_int( $answer['revision'] ?? null ) || ! is_bool( $answer['edited'] ?? null ) || ! ( null === $proof_id || is_string( $proof_id ) ) ) {
			throw ApiError::unexpected( Operations::CORRECT_DESIGN, $response );
		}
		return array(
			'revision' => $answer['revision'],
			'edited'   => $answer['edited'],
			'proofId'  => $proof_id,
		);
	}

	/**
	 * `POST /v1/designs/{id}/reject`: rejects a committed design, which then
	 * takes no correction and is never printed. Rejecting it again answers
	 * `200` again; one approved meanwhile is refused `not_committed`.
	 *
	 * @param string $design_id The design.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function reject( string $design_id ): void {
		$this->client->call( Operations::REJECT_DESIGN, array( 'id' => $design_id ) );
	}

	/**
	 * `POST /v1/designs/{id}/duplicate`: a new design from this one, awaiting
	 * approval on its own, with the same template version, document, blank
	 * and fulfilment payload, and no `externalRef`. The source is left as it
	 * was.
	 *
	 * @param string $design_id The design to copy.
	 *
	 * @return string The new design's id.
	 *
	 * @throws ApiError When Pixonaut refused (`not_found`, `design_erased`, …) or could not be reached.
	 */
	public function duplicate( string $design_id ): string {
		$response = $this->client->call( Operations::DUPLICATE_DESIGN, array( 'id' => $design_id ) );
		$id       = $this->client->json( Operations::DUPLICATE_DESIGN, $response )['designId'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw ApiError::unexpected( Operations::DUPLICATE_DESIGN, $response );
		}
		return $id;
	}
}
