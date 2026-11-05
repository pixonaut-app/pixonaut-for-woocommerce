<?php
/**
 * Pictures of a template, as a store shows its product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * @phpstan-type PictureCall array{0: string, 1: array<string, string>, 2: array<string, scalar|null>}
 */
final class Pictures {

	/**
	 * The signature every PNG starts with.
	 */
	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	public function __construct( private readonly Client $client ) {
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
	 * @throws ApiError When Pixonaut refused (`404` for any unknown id, `showcase_not_found` for an unknown set), could not be reached, or answered with no PNG.
	 */
	public function mockup( string $template_id, string $blank_id, string $placement_id, string $view_id, ?string $variant, int $size, ?string $showcase = null ): string {
		return $this->one( self::mockup_of( $template_id, $blank_id, $placement_id, $view_id, $variant, $size, $showcase ) );
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
	 *
	 * @phpstan-return PictureCall
	 */
	public static function mockup_of( string $template_id, string $blank_id, string $placement_id, string $view_id, ?string $variant, int $size, ?string $showcase = null ): array {
		return array(
			Operations::TEMPLATE_MOCKUP,
			array( 'id' => $template_id ),
			array(
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
				'viewId'      => $view_id,
				'variant'     => $variant,
				'size'        => $size,
				'showcase'    => $showcase,
			),
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown template, `showcase_not_found` for an unknown set), could not be reached, or answered with no PNG.
	 */
	public function preview( string $template_id, int $size, ?string $showcase = null ): string {
		return $this->one( self::preview_of( $template_id, $size, $showcase ) );
	}

	/**
	 * The call `preview()` makes, for `all()`.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param int         $size        The long edge in pixels.
	 * @param string|null $showcase    One of the template's showcase sets to draw it with; null for its default values.
	 *
	 * @phpstan-return PictureCall
	 */
	public static function preview_of( string $template_id, int $size, ?string $showcase = null ): array {
		return array(
			Operations::TEMPLATE_PREVIEW,
			array( 'id' => $template_id ),
			array(
				'size'     => $size,
				'showcase' => $showcase,
			),
		);
	}

	/**
	 * Draws several pictures at once (`Client::call_all()`).
	 *
	 * @param list<array> $calls From `mockup_of()` and `preview_of()`.
	 * @phpstan-param list<PictureCall> $calls
	 *
	 * @return list<string|ApiError> Each picture's PNG bytes, or why Pixonaut did not draw it, in the order of `$calls`.
	 */
	public function all( array $calls ): array {
		$pngs = array();
		foreach ( $this->client->call_all( $calls ) as $index => $answer ) {
			$pngs[] = $answer instanceof ApiError ? $answer : $this->png( $calls[ $index ][0], $answer );
		}
		return $pngs;
	}

	/**
	 * @param array $call From `mockup_of()` or `preview_of()`.
	 * @phpstan-param PictureCall $call
	 *
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered with no PNG.
	 */
	private function one( array $call ): string {
		$png = $this->png( $call[0], $this->client->call( $call[0], $call[1], $call[2] ) );
		if ( $png instanceof ApiError ) {
			throw $png;
		}
		return $png;
	}

	/**
	 * The PNG's bytes, or why the answer holds none.
	 *
	 * @param string   $operation Operation id.
	 * @param Response $response  Pixonaut's successful answer.
	 */
	private function png( string $operation, Response $response ): string|ApiError {
		// A proxy's error page answered 200 must not become the product's picture.
		$media = strtolower( trim( explode( ';', (string) $response->header( 'Content-Type' ) )[0] ) );
		if ( Operation::PNG !== $media || ! str_starts_with( $response->body, self::PNG_SIGNATURE ) ) {
			return ApiError::unexpected( $operation, $response );
		}
		return $response->body;
	}
}
