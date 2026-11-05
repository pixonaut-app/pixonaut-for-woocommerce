<?php
/**
 * The workspace's templates and blanks, as the store links products to them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Catalogue\PrintSize;

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

	public function __construct( private readonly Client $client ) {
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
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered without the list, as it answers a studio member (`templates`, `counts`).
	 */
	public function templates( string $query, int $page = 1, int $page_size = self::SEARCH_PAGE_SIZE ): array {
		return $this->search(
			Operations::SEARCH_TEMPLATES,
			'items',
			array(),
			array(
				'status' => self::LIVE,
				'q'      => self::query( $query ),
			),
			$page,
			$page_size
		);
	}

	/**
	 * Every live template's latest version, by base id: `GET /v1/templates?status=live`
	 * paged to the end. A template that is paused or deleted is not in it.
	 *
	 * @return array<string, int>
	 *
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function live_versions(): array {
		$versions = array();
		$page     = 1;
		do {
			$read = $this->search( Operations::SEARCH_TEMPLATES, 'items', array(), array( 'status' => self::LIVE ), $page++, self::LIST_PAGE_SIZE );
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
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function blanks( string $query, int $page = 1 ): array {
		return $this->search(
			Operations::SEARCH_BLANKS,
			'blanks',
			array(),
			array(
				'hasPlacements' => 'true',
				'q'             => self::query( $query ),
			),
			$page
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown template), could not be reached, or answered without a status.
	 */
	public function template_state( string $template_id ): array {
		$response = $this->client->call( Operations::GET_TEMPLATE, array( 'id' => $template_id ) );
		$template = $this->client->json( Operations::GET_TEMPLATE, $response );
		$status   = $template['status'] ?? null;
		if ( ! is_string( $status ) ) {
			throw ApiError::unexpected( Operations::GET_TEMPLATE, $response );
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown template), could not be reached, or answered with something else than a JSON object.
	 */
	public function template_showcases( string $template_id ): array {
		$response  = $this->client->call( Operations::GET_TEMPLATE, array( 'id' => $template_id ) );
		$template  = $this->client->json( Operations::GET_TEMPLATE, $response );
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown template), could not be reached, or answered with something else than a JSON object.
	 */
	public function template_answer( string $template_id, ?string $language = null ): string {
		return $this->answer( Operations::GET_TEMPLATE, array( 'id' => $template_id ), array( self::LANGUAGE => $language ) );
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
	 * @throws ApiError When Pixonaut refused (`422 unknown_placement`, `404`), could not be reached, or answered with something else than a JSON object.
	 */
	public function constraints_answer( string $template_id, string $blank_id, string $placement_id ): string {
		return $this->answer(
			Operations::GET_TEMPLATE_CONSTRAINTS,
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown blank), could not be reached, or answered with something else than a JSON object.
	 */
	public function blank_views_answers( string $blank_id, ?string $language = null ): array {
		return array(
			'mockups'  => $this->answer( Operations::BLANK_MOCKUPS, array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) ),
			'variants' => $this->answer( Operations::BLANK_VARIANTS, array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) ),
		);
	}

	/**
	 * `GET /v1/blanks/{id}`.
	 *
	 * @param string $blank_id The blank.
	 *
	 * @return array<string, mixed> `id`, `name`, `placements`, `defaultPrintshopId`, ….
	 *
	 * @throws ApiError When Pixonaut refused (`404` for an unknown blank) or could not be reached.
	 */
	public function blank( string $blank_id ): array {
		return $this->get( Operations::GET_BLANK, array( 'id' => $blank_id ) );
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown blank), could not be reached, or answered without the list.
	 */
	public function mockups( string $blank_id, ?string $language = null ): array {
		$response = $this->client->call( Operations::BLANK_MOCKUPS, array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) );
		return self::rows( Operations::BLANK_MOCKUPS, $response, $this->client->json( Operations::BLANK_MOCKUPS, $response ), 'placements' );
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
	 * @throws ApiError When Pixonaut refused (`404` for an unknown blank), could not be reached, or answered without the list.
	 */
	public function variants( string $blank_id, ?string $language = null ): array {
		$response = $this->client->call( Operations::BLANK_VARIANTS, array( 'id' => $blank_id ), array( self::LANGUAGE => $language ) );
		return self::rows( Operations::BLANK_VARIANTS, $response, $this->client->json( Operations::BLANK_VARIANTS, $response ), 'variants' );
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
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function fitting_templates( string $blank_id, string $placement_id, string $query, int $page = 1 ): array {
		return $this->search(
			Operations::FITTING_TEMPLATES,
			'items',
			array(
				'id'          => $blank_id,
				'placementId' => $placement_id,
			),
			array(
				'status' => self::LIVE,
				'q'      => self::query( $query ),
			),
			$page
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
	 * @throws ApiError When Pixonaut refused (`422 unknown_placement`, `404`) or could not be reached.
	 */
	public function constraints( string $template_id, string $blank_id, string $placement_id ): array {
		return $this->get(
			Operations::GET_TEMPLATE_CONSTRAINTS,
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
	 * @return list<array<string, mixed>> One verdict per size, in order: `{legal: true}` or `{legal: false, reason}`.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function check_print_sizes( string $template_id, array $sizes, ?string $blank_id, ?string $placement_id ): array {
		$body = array(
			'sizes' => array_map(
				static fn ( PrintSize $size ): array => array(
					'widthMm'  => $size->width_mm,
					'heightMm' => $size->height_mm,
				),
				$sizes
			),
		);
		if ( null !== $blank_id && null !== $placement_id ) {
			$body += array(
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
			);
		}
		$response = $this->client->call( Operations::CHECK_PRINT_SIZES, array( 'id' => $template_id ), array(), $body );
		$verdicts = self::rows( Operations::CHECK_PRINT_SIZES, $response, $this->client->json( Operations::CHECK_PRINT_SIZES, $response ), 'verdicts' );
		if ( count( $verdicts ) !== count( $sizes ) ) {
			throw ApiError::unexpected( Operations::CHECK_PRINT_SIZES, $response );
		}
		return $verdicts;
	}

	/**
	 * `GET /v1/printshop-connections`: the workspace's printshops, to name
	 * the one a blank prints at. The store only shows it; Pixonaut routes.
	 *
	 * @return list<array<string, mixed>> `id`, `name`, `adapter`, `active`, `isDefault`.
	 *
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function printshop_connections(): array {
		$response = $this->client->call( Operations::LIST_PRINTSHOP_CONNECTIONS );
		return self::rows( Operations::LIST_PRINTSHOP_CONNECTIONS, $response, $this->client->json( Operations::LIST_PRINTSHOP_CONNECTIONS, $response ), 'connections' );
	}

	/**
	 * @param string                     $operation Operation id.
	 * @param array<string, string>      $path      Path values.
	 * @param array<string, scalar|null> $query     Query parameters.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function get( string $operation, array $path = array(), array $query = array() ): array {
		return $this->client->json( $operation, $this->client->call( $operation, $path, $query ) );
	}

	/**
	 * A successful answer's body, checked to be a JSON object.
	 *
	 * @param string                     $operation Operation id.
	 * @param array<string, string>      $path      Path values.
	 * @param array<string, scalar|null> $query     Query parameters.
	 *
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered with something else than a JSON object.
	 */
	private function answer( string $operation, array $path = array(), array $query = array() ): string {
		$response = $this->client->call( $operation, $path, $query );
		$this->client->json( $operation, $response );
		return $response->body;
	}

	/**
	 * One page of a catalogue list.
	 *
	 * @param string                     $operation Operation id.
	 * @param string                     $field     The answer's list field.
	 * @param array<string, string>      $path      Path values.
	 * @param array<string, scalar|null> $query     Query parameters, paging aside.
	 * @param int                        $page      Page, from 1.
	 * @param int                        $page_size Results per page.
	 *
	 * @return array{rows: list<array<string, mixed>>, more: bool, total: int|null} The rows, whether a later page has more, and how many there are in all when Pixonaut said.
	 *
	 * @throws ApiError When Pixonaut refused, could not be reached, or answered without the list.
	 */
	private function search( string $operation, string $field, array $path, array $query, int $page, int $page_size = self::SEARCH_PAGE_SIZE ): array {
		$page     = max( 1, $page );
		$response = $this->client->call(
			$operation,
			$path,
			$query + array(
				'page'     => $page,
				'pageSize' => $page_size,
			)
		);
		$answer   = $this->client->json( $operation, $response );
		$total    = $answer['total'] ?? null;
		return array(
			'rows'  => self::rows( $operation, $response, $answer, $field ),
			'more'  => is_numeric( $total ) && $page * $page_size < (int) $total,
			'total' => is_numeric( $total ) ? (int) $total : null,
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
	 * @param string               $operation Operation id.
	 * @param Response             $response  The successful response.
	 * @param array<string, mixed> $answer    Its decoded body.
	 * @param string               $field     The list's field.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws ApiError When the answer has no such list.
	 */
	private static function rows( string $operation, Response $response, array $answer, string $field ): array {
		$list = $answer[ $field ] ?? null;
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			throw ApiError::unexpected( $operation, $response );
		}
		$rows = array();
		foreach ( $list as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}
}
