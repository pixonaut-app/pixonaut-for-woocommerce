<?php
/**
 * An integrated printshop's catalogue, as the store browses and imports it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The products a print partner such as Gelato sells, through one of the
 * workspace's printshop connections, each with the blank Pixonaut imported
 * it as; and the import that makes a blank of one, which Pixonaut fills in
 * in the background (`importing` until it is done).
 */
final class PrintshopCatalogue {

	/**
	 * Products per search page: enough to pick from, small enough to answer fast.
	 */
	public const PAGE_SIZE = 20;

	/**
	 * The most products one read asks for, as Pixonaut bounds it.
	 */
	private const MOST_PER_READ = 100;

	public function __construct( private readonly Sdk $pixonaut ) {
	}

	/**
	 * `GET /v1/printshop-catalogue-products`: a page of the connection's
	 * products whose name holds the search.
	 *
	 * @param string $connection_id The printshop connection.
	 * @param string $search        Search text; empty for all.
	 * @param int    $page          Page, from 1.
	 * @return array{rows: list<array{id: string, name: string, image: string, variants: int|null, blank: string|null}>, more: bool} Each product's id, name, picture (empty without one), how many variants it is sold in when the printshop says, and the blank it was imported as (null when it was not); and whether a later page has more.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function products( string $connection_id, string $search, int $page = 1 ): array {
		$page   = max( 1, $page );
		$answer = $this->list( $connection_id, $search, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		$total  = $answer['total'] ?? null;
		return array(
			'rows' => self::rows( $answer ),
			'more' => is_numeric( $total ) && $page * self::PAGE_SIZE < (int) $total,
		);
	}

	/**
	 * The blank a product was imported as through the connection, found by
	 * its name; null when it was not imported.
	 *
	 * @param string $connection_id The printshop connection.
	 * @param string $product_id    The product.
	 * @param string $name          Its name, to find it by.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without the list.
	 */
	public function imported_blank( string $connection_id, string $product_id, string $name ): ?string {
		foreach ( self::rows( $this->list( $connection_id, $name, self::MOST_PER_READ, 0 ) ) as $row ) {
			if ( $row['id'] === $product_id ) {
				return $row['blank'];
			}
		}
		return null;
	}

	/**
	 * `POST /v1/printshop-catalogue-products/{productId}/blank`: imports the
	 * product as a blank of the workspace, every colour and view, under the
	 * printshop's name for it.
	 *
	 * @param string $connection_id The printshop connection.
	 * @param string $product_id    The product.
	 * @return array{id: string, name: string, status: string} The blank, `importing` while Pixonaut fills it in.
	 *
	 * @throws ApiException When Pixonaut refused (`409 duplicate_name` for a name the workspace has, `422` for a product with nothing to print on), could not be reached, or answered without the blank.
	 */
	public function import( string $connection_id, string $product_id ): array {
		$operation = 'printshopCatalogueProducts.blank.create';
		$answer    = $this->pixonaut->call( $operation, array( 'productId' => $product_id ), array( 'connectionId' => $connection_id ), array() )->object();
		$blank     = is_array( $answer['blank'] ?? null ) ? $answer['blank'] : array();
		if ( ! is_string( $blank['id'] ?? null ) || '' === $blank['id'] ) {
			throw new ApiException( $operation, 200, ApiException::UNEXPECTED_RESPONSE, false );
		}
		return array(
			'id'     => $blank['id'],
			'name'   => is_string( $blank['name'] ?? null ) ? $blank['name'] : $blank['id'],
			'status' => is_string( $blank['status'] ?? null ) ? $blank['status'] : Catalogue::BLANK_READY,
		);
	}

	/**
	 * One read of the list.
	 *
	 * @param string $connection_id The printshop connection.
	 * @param string $search        Search text; empty for all.
	 * @param int    $limit         Most products.
	 * @param int    $offset        Products skipped.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function list( string $connection_id, string $search, int $limit, int $offset ): array {
		$search = trim( $search );
		return $this->pixonaut->call(
			'printshopCatalogueProducts.list',
			array(),
			array(
				'connectionId' => $connection_id,
				'search'       => '' === $search ? null : $search,
				'limit'        => $limit,
				'offset'       => $offset,
			)
		)->object();
	}

	/**
	 * The products of a list answer; one without an id or a name is left out.
	 *
	 * @param array<string, mixed> $answer The answer.
	 * @return list<array{id: string, name: string, image: string, variants: int|null, blank: string|null}>
	 *
	 * @throws ApiException When the answer has no list.
	 */
	private static function rows( array $answer ): array {
		$products = $answer['products'] ?? null;
		if ( ! is_array( $products ) || ! array_is_list( $products ) ) {
			throw new ApiException( 'printshopCatalogueProducts.list', 200, ApiException::UNEXPECTED_RESPONSE, false );
		}
		$rows = array();
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) || ! is_string( $product['productId'] ?? null ) || '' === $product['productId'] || ! is_string( $product['name'] ?? null ) ) {
				continue;
			}
			$variants = is_array( $product['holds'] ?? null ) ? ( $product['holds']['variants'] ?? null ) : null;
			$blank    = $product['importedBlankId'] ?? null;
			$rows[]   = array(
				'id'       => $product['productId'],
				'name'     => $product['name'],
				'image'    => is_string( $product['imageUrl'] ?? null ) ? $product['imageUrl'] : '',
				'variants' => is_int( $variants ) ? $variants : null,
				'blank'    => is_string( $blank ) && '' !== $blank ? $blank : null,
			);
		}
		return $rows;
	}
}
