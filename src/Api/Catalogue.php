<?php
/**
 * The workspace's templates and blanks, as the store links products to them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Api\Answers\PrintSizeVerdict;
use Pixonaut\WooCommerce\Api\Answers\PrintSizeVerdicts;
use Pixonaut\WooCommerce\Api\Answers\Read;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateRequestOption2;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateRequestOption2SizesItem;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * Search paging, live versions and the rows the store keeps, on top of the
 * SDK's template, blank and printshop operations. Answers are read untyped
 * and kept as Pixonaut sent them, as arrays, since they are cached and stored
 * that way.
 */
final class Catalogue {

	/**
	 * Results per search: enough to pick from, small enough to answer fast.
	 */
	public const SEARCH_PAGE_SIZE = 20;

	/**
	 * Templates per page when every live one is read: the most Pixonaut pages.
	 */
	public const LIST_PAGE_SIZE = 200;

	/**
	 * A template's `status` while customers can personalise it, and the
	 * filter listing such templates alone.
	 */
	public const LIVE = 'live';

	/**
	 * A blank's `status` once its import is done. While it is `importing`
	 * it has a name and nothing else, and a `failed` one never gets more.
	 */
	public const BLANK_READY = 'ready';

	/**
	 * The query parameter naming the language a read is answered in: a
	 * blank's option names, option values and view labels, each falling back
	 * to the name as written, or a template's dynamic values and overrides.
	 */
	private const LANGUAGE = 'lang';

	public function __construct( private readonly Sdk $pixonaut ) {
	}

	/**
	 * `GET /v1/templates?status=live&q`: live templates whose name holds `$query`.
	 *
	 * @param string $query     Search text; empty for all.
	 * @param int    $page      Page, from 1.
	 * @param int    $page_size Templates per page.
	 *
	 * @return array{rows: list<array<string, mixed>>, more: bool, total: int|null} `items` (`baseId`, `name`, `latestVersion`, `ratio`, `ratioTolerance`, `previewUrl`), whether a later page has more, and how many there are in all when Pixonaut said.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list, as it answers a studio member (`templates`, `counts`).
	 */
	public function templates( string $query, int $page = 1, int $page_size = self::SEARCH_PAGE_SIZE ): array {
		$page = max( 1, $page );
		return self::page( 'templates.list', $this->read( 'templates.list', array(), array( 'status' => self::LIVE ) + self::template_listing( $page, $page_size, $query ) ), 'items', $page, $page_size );
	}

	/**
	 * Every live template's latest version, by base id: `GET /v1/templates?status=live`
	 * paged to the end. A template that is paused or deleted is not in it.
	 *
	 * @return array<string, int>
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function live_versions(): array {
		$versions = array();
		$page     = 1;
		do {
			$read = self::page( 'templates.list', $this->read( 'templates.list', array(), array( 'status' => self::LIVE ) + self::template_listing( $page, self::LIST_PAGE_SIZE, '' ) ), 'items', $page++, self::LIST_PAGE_SIZE );
			foreach ( $read['rows'] as $row ) {
				if ( is_string( $row['baseId'] ?? null ) && is_numeric( $row['latestVersion'] ?? null ) ) {
					$versions[ $row['baseId'] ] = (int) $row['latestVersion'];
				}
			}
		} while ( $read['more'] && array() !== $read['rows'] );
		return $versions;
	}

	/**
	 * `GET /v1/blanks?hasPlacements=true&q`: blanks with somewhere to print.
	 *
	 * @param string $query Search text; empty for all.
	 * @param int    $page  Page, from 1.
	 *
	 * @return array{rows: list<array<string, mixed>>, more: bool, total: int|null} `blanks` (`id`, `name`, `placements` with `id`, `name`, `shopName`, sizes), and whether a later page has more.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function blanks( string $query, int $page = 1 ): array {
		$page = max( 1, $page );
		return self::page(
			'blanks.list',
			$this->read(
				'blanks.list',
				array(),
				array(
					'page'          => $page,
					'pageSize'      => self::SEARCH_PAGE_SIZE,
					'q'             => self::query( $query ),
					'hasPlacements' => 'true',
				)
			),
			'blanks',
			$page,
			self::SEARCH_PAGE_SIZE
		);
	}

	/**
	 * `GET /v1/templates/{id}`: whether the template is live or paused now,
	 * whichever of its versions is read, whether this version's customer
	 * form offers a field, whether its background shows through, the link to
	 * its preview, and its name.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 *
	 * @return array{status: string, offers_properties: bool|null, background_see_through: bool|null, preview_url: string|null, name: string|null} `status` is `live`, `paused`, or another state Pixonaut adds; `offers_properties` and `background_see_through` are null when Pixonaut does not say; `preview_url` works for an hour or two; `name` is null when Pixonaut sent none.
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown template), could not be reached, or answered without a status.
	 */
	public function template_state( string $template_id ): array {
		// Read as sent: a Pixonaut that predates a field the spec now requires still answers the store.
		$template = $this->pixonaut->call( 'templates.get', array( 'id' => $template_id ) )->object();
		$status   = $template['status'] ?? null;
		if ( ! is_string( $status ) ) {
			throw self::unexpected( 'templates.get' );
		}
		$offers     = $template['offersProperties'] ?? null;
		$background = $template['backgroundSeeThrough'] ?? null;
		$preview    = $template['previewUrl'] ?? null;
		$name       = is_array( $template['template'] ?? null ) ? ( $template['template']['name'] ?? null ) : null;
		return array(
			'status'                 => $status,
			'offers_properties'      => is_bool( $offers ) ? $offers : null,
			'background_see_through' => is_bool( $background ) ? $background : null,
			'preview_url'            => is_string( $preview ) && '' !== $preview ? $preview : null,
			'name'                   => is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : null,
		);
	}

	/**
	 * `GET /v1/templates/{id}`: the template's showcase sets, named sets of
	 * example values its pictures can be drawn with, in the template's order,
	 * and its preview. A template that lists none, or an answer without the
	 * list, has none.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 *
	 * @return array{showcases: list<array{id: string, name: string}>, preview_url: string|null} `preview_url` works for an hour or two; null when Pixonaut sent none.
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown template), could not be reached, or answered with something else than a JSON object.
	 */
	public function template_showcases( string $template_id ): array {
		$template  = $this->pixonaut->call( 'templates.get', array( 'id' => $template_id ) )->object();
		$showcases = array();
		foreach ( is_array( $template['showcases'] ?? null ) ? $template['showcases'] : array() as $showcase ) {
			$id = is_array( $showcase ) ? ( $showcase['id'] ?? null ) : null;
			if ( is_string( $id ) && '' !== $id ) {
				$name        = $showcase['name'] ?? null;
				$showcases[] = array(
					'id'   => $id,
					'name' => is_string( $name ) && '' !== $name ? $name : $id,
				);
			}
		}
		$preview = $template['previewUrl'] ?? null;
		return array(
			'showcases'   => $showcases,
			'preview_url' => is_string( $preview ) && '' !== $preview ? $preview : null,
		);
	}

	/**
	 * `GET /v1/templates/{id}`, as Pixonaut sent it: what the designer opens
	 * the template from, passed to the SDK as the SDK would have read it.
	 * Kept as JSON text, since decoding it into PHP arrays loses the
	 * difference between an empty object and an empty list.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param string|null $language    One of `DesignerLanguage::languages()` to read the template in; null for the workspace's default language.
	 *
	 * @return string A JSON object: `template`, `formSchema`, `status`, `language`, ….
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown template), could not be reached, or answered with something else than a JSON object.
	 */
	public function template_answer( string $template_id, ?string $language = null ): string {
		return $this->answer( 'templates.get', array( 'id' => $template_id ), array( self::LANGUAGE => $language ) );
	}

	/**
	 * `GET /v1/templates/{id}/constraints?blankId&placementId`, as Pixonaut
	 * sent it (see `template_answer()`).
	 *
	 * @param string $template_id  `<baseId>@<version>`.
	 * @param string $blank_id     The blank.
	 * @param string $placement_id One of its placements.
	 *
	 * @return string A JSON object.
	 *
	 * @throws ApiException When Pixonaut refused (`422 unknown_placement`, `404`), could not be reached, or answered with something else than a JSON object.
	 */
	public function constraints_answer( string $template_id, string $blank_id, string $placement_id ): string {
		return $this->answer(
			'templates.constraints.get',
			array( 'id' => $template_id ),
			array(
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
			)
		);
	}

	/**
	 * `GET /v1/blanks/{id}/mockups` and `GET /v1/blanks/{id}/variants`, as
	 * Pixonaut sent them (see `template_answer()`): the blank's views and
	 * variants.
	 *
	 * @param string      $blank_id The blank.
	 * @param string|null $language One of `DesignerLanguage::languages()` to name the options and views in; null for their names as written.
	 *
	 * @return array{mockups: string, variants: string} JSON objects.
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown blank), could not be reached, or answered with something else than a JSON object.
	 */
	public function blank_views_answers( string $blank_id, ?string $language = null ): array {
		return array(
			'mockups'  => $this->answer( 'blanks.mockups.list', array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) ),
			'variants' => $this->answer( 'blanks.variants.list', array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) ),
		);
	}

	/**
	 * `GET /v1/blanks/{id}`.
	 *
	 * @param string $blank_id The blank.
	 *
	 * @return array<string, mixed> `id`, `name`, `placements`, `defaultPrintshopId`, ….
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown blank) or could not be reached.
	 */
	public function blank( string $blank_id ): array {
		return $this->read( 'blanks.get', array( 'id' => $blank_id ) );
	}

	/**
	 * `GET /v1/blanks/{id}/mockups`: the blank's placements, each with its
	 * views (a `label` and `photos`); each photo has its id (the mockup id),
	 * a short-lived `thumbnailUrl` and the `mount` a storefront passes to the
	 * designer (null while the photo is uploading).
	 *
	 * @param string      $blank_id The blank.
	 * @param string|null $language One of `DesignerLanguage::languages()` to name the views in; null for their labels as written.
	 *
	 * @return list<array<string, mixed>> `placements`: `id`, `name`, `views` (`id`, `label`, `photos`: `id`, `thumbnailUrl`, `mount`), ….
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown blank), could not be reached, or answered without the list.
	 */
	public function mockups( string $blank_id, ?string $language = null ): array {
		return self::rows( 'blanks.mockups.list', $this->read( 'blanks.mockups.list', array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) ), 'placements' );
	}

	/**
	 * `GET /v1/blanks/{id}/variants`: every variant of the blank, retired ones
	 * included, each with its `ref`, its `options` (axis to label), `retired`
	 * (null, or its `reason`) and the `views` it has a photo in.
	 *
	 * @param string      $blank_id The blank.
	 * @param string|null $language One of `DesignerLanguage::languages()` to name the options and views in; null for their names as written.
	 *
	 * @return list<array<string, mixed>> `variants`: `ref`, `options`, `retired`, `views` (`placementId`, `viewId`, `label`).
	 *
	 * @throws ApiException When Pixonaut refused (`404` for an unknown blank), could not be reached, or answered without the list.
	 */
	public function variants( string $blank_id, ?string $language = null ): array {
		return self::rows( 'blanks.variants.list', $this->read( 'blanks.variants.list', array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) ), 'variants' );
	}

	/**
	 * `GET /v1/blanks/{id}/placements/{placementId}/fitting-templates?status=live&q`:
	 * live templates with the verdict for their artwork on the placement.
	 *
	 * @param string $blank_id     The blank.
	 * @param string $placement_id One of its placements.
	 * @param string $query        Search text; empty for all.
	 * @param int    $page         Page, from 1.
	 *
	 * @return array{rows: list<array<string, mixed>>, more: bool, total: int|null} `items` (`templateId` as `<baseId>@<version>`, `name`, `printWidthMm`, `printHeightMm`, `verdict`, …), and whether a later page has more.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function fitting_templates( string $blank_id, string $placement_id, string $query, int $page = 1 ): array {
		$page = max( 1, $page );
		return self::page(
			'blanks.placements.fittingTemplates.list',
			$this->read(
				'blanks.placements.fittingTemplates.list',
				array(
					'id'          => $blank_id,
					'placementId' => $placement_id,
				),
				array(
					'page'     => $page,
					'pageSize' => self::SEARCH_PAGE_SIZE,
					'status'   => self::LIVE,
					'q'        => self::query( $query ),
				)
			),
			'items',
			$page,
			self::SEARCH_PAGE_SIZE
		);
	}

	/**
	 * `GET /v1/templates/{id}/constraints?blankId&placementId`: the template's
	 * ratio and, on a placement, the box it prints at when no size is named
	 * (`placement.printWidthMm` × `placement.printHeightMm`).
	 *
	 * @param string $template_id  `<baseId>@<version>`.
	 * @param string $blank_id     The blank.
	 * @param string $placement_id One of its placements.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When Pixonaut refused (`422 unknown_placement`, `404`) or could not be reached.
	 */
	public function constraints( string $template_id, string $blank_id, string $placement_id ): array {
		return $this->read(
			'templates.constraints.get',
			array( 'id' => $template_id ),
			array(
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
			)
		);
	}

	/**
	 * `POST /v1/templates/{id}/print-size {sizes[], blankId, placementId}`:
	 * judges every size against the template's ratio and, with a blank, the
	 * placement's largest print area, in one call.
	 *
	 * @param string      $template_id  `<baseId>@<version>`.
	 * @param PrintSize[] $sizes        Sizes to judge, at least one.
	 * @param string|null $blank_id     The blank; null to judge against the template alone.
	 * @param string|null $placement_id One of its placements; null without a blank.
	 * @phpstan-param non-empty-list<PrintSize> $sizes
	 *
	 * @return list<PrintSizeVerdict> One verdict per size, in order.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function check_print_sizes( string $template_id, array $sizes, ?string $blank_id, ?string $placement_id ): array {
		$on_blank = null !== $blank_id && null !== $placement_id;
		$body     = new TemplatesPrintSizeValidateRequestOption2(
			sizes: array_map(
				static fn ( PrintSize $size ): TemplatesPrintSizeValidateRequestOption2SizesItem => new TemplatesPrintSizeValidateRequestOption2SizesItem( heightMm: $size->height_mm, widthMm: $size->width_mm ),
				$sizes
			),
			blankId: $on_blank ? $blank_id : null,
			placementId: $on_blank ? $placement_id : null,
		);
		$verdicts = Read::answer( $this->pixonaut->call( 'templates.printSize.validate', array( 'id' => $template_id ), body: $body->toArray() ), PrintSizeVerdicts::from_answer( ... ) )->verdicts;
		if ( count( $verdicts ) !== count( $sizes ) ) {
			throw self::unexpected( 'templates.printSize.validate' );
		}
		return $verdicts;
	}

	/**
	 * `GET /v1/printshop-connections`: the workspace's printshops, to name
	 * the one a blank prints at. The store only shows it; Pixonaut routes.
	 *
	 * @return list<array<string, mixed>> `id`, `name`, `adapter`, `active`, `isDefault`.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function printshop_connections(): array {
		return self::rows( 'printshopConnections.list', $this->read( 'printshopConnections.list' ), 'connections' );
	}

	/**
	 * A successful answer's body, checked to be a JSON object.
	 *
	 * @param string                     $operation SDK operation id.
	 * @param array<string, string>      $path      Path values.
	 * @param array<string, scalar|null> $query     Query parameters.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered with something else than a JSON object.
	 */
	private function answer( string $operation, array $path = array(), array $query = array() ): string {
		$result = $this->pixonaut->call( $operation, $path, $query );
		$result->object();
		return $result->body;
	}

	/**
	 * One page of a catalogue list.
	 *
	 * @param string               $operation SDK operation id, for the error.
	 * @param array<string, mixed> $answer    The page Pixonaut answered.
	 * @param string               $field     The answer's list field.
	 * @param int                  $page      Page, from 1.
	 * @param int                  $page_size Results per page.
	 *
	 * @return array{rows: list<array<string, mixed>>, more: bool, total: int|null} The rows, whether a later page has more, and how many there are in all when Pixonaut said.
	 *
	 * @throws ApiException When the answer has no such list.
	 */
	private static function page( string $operation, array $answer, string $field, int $page, int $page_size ): array {
		$total = $answer['total'] ?? null;
		return array(
			'rows'  => self::rows( $operation, $answer, $field ),
			'more'  => is_numeric( $total ) && $page * $page_size < (int) $total,
			'total' => is_numeric( $total ) ? (int) $total : null,
		);
	}

	/**
	 * A read's answer as Pixonaut sent it, a JSON object. Catalogue reads go
	 * through the SDK untyped: the store keeps answers as arrays, and a field
	 * the SDK's release requires but Pixonaut no longer sends, or has not
	 * sent yet, must not fail a read the store does not need it for.
	 *
	 * @param string                     $operation SDK operation id.
	 * @param array<string, string>      $path      Path values.
	 * @param array<string, scalar|null> $query     Query parameters; null ones are left out.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered with something else than a JSON object.
	 */
	private function read( string $operation, array $path = array(), array $query = array() ): array {
		return $this->pixonaut->call( $operation, $path, $query )->object();
	}

	/**
	 * A template list's search, page and page size.
	 *
	 * @param int    $page      Page, from 1.
	 * @param int    $page_size Results per page.
	 * @param string $query     Search text; empty for all.
	 * @return array<string, scalar|null>
	 */
	private static function template_listing( int $page, int $page_size, string $query ): array {
		return array(
			'q'        => self::query( $query ),
			'page'     => $page,
			'pageSize' => $page_size,
		);
	}

	/**
	 * An empty search is left out rather than sent as `q=`.
	 *
	 * @param string $query Search text.
	 */
	private static function query( string $query ): ?string {
		$query = trim( $query );
		return '' === $query ? null : $query;
	}

	/**
	 * The objects in one of the answer's lists. An answer without the list
	 * is an error, not an empty result, so it is never cached as one.
	 *
	 * @param string               $operation SDK operation id, for the error.
	 * @param array<string, mixed> $answer    The answer, as Pixonaut sent it.
	 * @param string               $field     The list's field.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws ApiException When the answer has no such list.
	 */
	private static function rows( string $operation, array $answer, string $field ): array {
		$list = $answer[ $field ] ?? null;
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			throw self::unexpected( $operation );
		}
		$rows = array();
		foreach ( $list as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * An answer that lacks what the store reads from it.
	 *
	 * @param string $operation SDK operation id.
	 */
	private static function unexpected( string $operation ): ApiException {
		return new ApiException( $operation, 200, ApiException::UNEXPECTED_RESPONSE, false );
	}
}
