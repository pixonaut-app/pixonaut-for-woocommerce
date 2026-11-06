<?php
/**
 * Pictures of a template, as a store shows its product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;

/**
 * Template pictures, checked to be PNGs before the store keeps them, and
 * drawn several at once.
 */
final class Pictures {

	/**
	 * The signature every PNG starts with.
	 */
	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	private const PNG = 'image/png';

	/**
	 * @param Pixonaut $pixonaut The SDK, and its batched reads.
	 */
	public function __construct( private readonly Pixonaut $pixonaut ) {
	}

	/**
	 * `GET /t/{id}/mockup.png?blankId&placementId&viewId&variant&size&showcase`:
	 * the template drawn into the print area of a view's photo, the one of
	 * `$variant` when the view has one.
	 *
	 * @param string      $template_id  `<baseId>@<version>`.
	 * @param string      $blank_id     The blank.
	 * @param string      $placement_id One of its placements.
	 * @param string      $view_id      One of the placement's views.
	 * @param string|null $variant      A variant ref of the blank; null for the view's default photo.
	 * @param int         $size         The long edge in pixels.
	 * @param string|null $showcase     One of the template's showcase sets to draw it with; null for its default values.
	 *
	 * @return string The PNG's bytes.
	 *
	 * @throws ApiException When Pixonaut refused (`404` for any unknown id, `showcase_not_found` for an unknown set), could not be reached, or answered with no PNG.
	 */
	public function mockup( string $template_id, string $blank_id, string $placement_id, string $view_id, ?string $variant, int $size, ?string $showcase = null ): string {
		return $this->one( $this->mockup_of( $template_id, $blank_id, $placement_id, $view_id, $variant, $size, $showcase ) );
	}

	/**
	 * The call `mockup()` makes, for `all()`.
	 *
	 * @param string      $template_id  `<baseId>@<version>`.
	 * @param string      $blank_id     The blank.
	 * @param string      $placement_id One of its placements.
	 * @param string      $view_id      One of the placement's views.
	 * @param string|null $variant      A variant ref of the blank; null for the view's default photo.
	 * @param int         $size         The long edge in pixels.
	 * @param string|null $showcase     One of the template's showcase sets to draw it with; null for its default values.
	 */
	public function mockup_of( string $template_id, string $blank_id, string $placement_id, string $view_id, ?string $variant, int $size, ?string $showcase = null ): PreparedRequest {
		return $this->pixonaut->sdk()->prepare(
			'templates.mockup.get',
			array( 'id' => $template_id ),
			array(
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
				'viewId'      => $view_id,
				'variant'     => $variant,
				'size'        => $size,
				'showcase'    => $showcase,
			)
		);
	}

	/**
	 * `GET /t/{id}/preview.png?size&showcase`: the template drawn with its
	 * own default values, or a showcase set's, clipped to its shape.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param int         $size        The long edge in pixels.
	 * @param string|null $showcase    One of the template's showcase sets to draw it with; null for its default values.
	 *
	 * @return string The PNG's bytes.
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown template, `showcase_not_found` for an unknown set), could not be reached, or answered with no PNG.
	 */
	public function preview( string $template_id, int $size, ?string $showcase = null ): string {
		return $this->one( $this->preview_of( $template_id, $size, $showcase ) );
	}

	/**
	 * The call `preview()` makes, for `all()`.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param int         $size        The long edge in pixels.
	 * @param string|null $showcase    One of the template's showcase sets to draw it with; null for its default values.
	 */
	public function preview_of( string $template_id, int $size, ?string $showcase = null ): PreparedRequest {
		return $this->pixonaut->sdk()->prepare(
			'templates.preview.get',
			array( 'id' => $template_id ),
			array(
				'size'     => $size,
				'showcase' => $showcase,
			)
		);
	}

	/**
	 * Draws several pictures at once (`Pixonaut::all()`).
	 *
	 * @param PreparedRequest[] $calls From `mockup_of()` and `preview_of()`.
	 * @phpstan-param list<PreparedRequest> $calls
	 *
	 * @return list<string|ApiException> Each picture's PNG bytes, or why Pixonaut did not draw it, in the order of `$calls`.
	 */
	public function all( array $calls ): array {
		return array_map(
			static fn ( Result|ApiException $answer ): string|ApiException => $answer instanceof ApiException ? $answer : self::png( $answer ),
			$this->pixonaut->all( $calls )
		);
	}

	/**
	 * @param PreparedRequest $call From `mockup_of()` or `preview_of()`.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered with no PNG.
	 */
	private function one( PreparedRequest $call ): string {
		$png = self::png( $this->pixonaut->sdk()->send( $call ) );
		if ( $png instanceof ApiException ) {
			throw $png;
		}
		return $png;
	}

	/**
	 * The PNG's bytes, or why the answer holds none.
	 *
	 * @param Result $result Pixonaut's successful answer.
	 */
	private static function png( Result $result ): string|ApiException {
		// A proxy's error page answered 200 must not become the product's picture.
		$media = strtolower( trim( explode( ';', (string) $result->header( 'Content-Type' ) )[0] ) );
		if ( self::PNG !== $media || ! str_starts_with( $result->body, self::PNG_SIGNATURE ) ) {
			return $result->unexpected();
		}
		return $result->body;
	}
}
