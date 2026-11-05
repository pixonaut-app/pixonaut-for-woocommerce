<?php
/**
 * One kind of thing in the store the abilities manage.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities\Store;

/**
 * A kind of thing WordPress or WooCommerce keeps, such as product categories
 * or coupons, as its REST API serves it: the routes, which operations it
 * takes, and the capability each operation needs. `ResourceAbility` makes an
 * ability of each operation, run through the route as the user chatting, so
 * the route's own checks apply on top of the capability.
 */
final class StoreResource {

	public const LIST   = 'list';
	public const GET    = 'get';
	public const CREATE = 'create';
	public const UPDATE = 'update';
	public const DELETE = 'delete';

	/**
	 * The operations in the order their abilities are registered.
	 */
	public const OPERATIONS = array( self::LIST, self::GET, self::CREATE, self::UPDATE, self::DELETE );

	/**
	 * A placeholder in a route, e.g. `{product_id}`.
	 */
	private const PLACEHOLDER_PATTERN = '/\{([a-z_]+)\}/';

	/**
	 * The item's own placeholder in a route.
	 */
	public const ID = 'id';

	/**
	 * The lowest id WordPress gives a thing it stores.
	 */
	private const FIRST_ID = 1;

	/**
	 * The id WooCommerce gives the one thing of a kind it keeps without
	 * storing, as the shipping zone for "Locations not covered by your other
	 * zones" is.
	 */
	private const BUILT_IN_ID = 0;

	/**
	 * A number as a placeholder takes it: a stored thing's id.
	 */
	private const ID_PATTERN = '/^[1-9][0-9]*$/';

	/**
	 * A number as a placeholder that may be `BUILT_IN_ID` takes it.
	 */
	private const ID_OR_BUILT_IN_PATTERN = '/^(0|[1-9][0-9]*)$/';

	/**
	 * @param string                $key          Singular, e.g. `product-category`.
	 * @param string                $plural       Plural, e.g. `product-categories`.
	 * @param string                $noun         What one is called, lowercase, e.g. "product category".
	 * @param string                $nouns        What several are called, lowercase.
	 * @param string                $route        The collection's REST route, without a leading slash; a parent's id as a placeholder, e.g. `wc/v3/products/{product_id}/variations`.
	 * @param array<string, string> $capabilities The capability each operation the resource takes needs, by operation.
	 * @param string                $fields       A few of the fields a create or an update takes, e.g. `name, slug, parent`.
	 * @param array<string, string> $routes       A route for an operation other than the collection's (list, create) or the item's (get, update, delete).
	 * @param string                $id_type      `integer`, or `string` for a resource whose ids are names.
	 * @param bool                  $create_asks  Whether creating one asks the merchant first: a coupon gives money away, an order may bill a customer.
	 * @param bool                  $replaces     Whether an update replaces the whole collection with a list (`items`), as a shipping zone's locations are.
	 * @param string[]              $built_in     The placeholders that may be `BUILT_IN_ID` (0) too, as a shipping zone's may for the zone of the locations no other zone covers.
	 * @phpstan-param list<string> $built_in
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $plural,
		public readonly string $noun,
		public readonly string $nouns,
		public readonly string $route,
		public readonly array $capabilities,
		public readonly string $fields = '',
		private readonly array $routes = array(),
		public readonly string $id_type = 'integer',
		public readonly bool $create_asks = false,
		public readonly bool $replaces = false,
		private readonly array $built_in = array(),
	) {
	}

	/**
	 * The operations the resource takes, in order.
	 *
	 * @return list<string>
	 */
	public function operations(): array {
		return array_values( array_intersect( self::OPERATIONS, array_keys( $this->capabilities ) ) );
	}

	/**
	 * The route an operation runs on, its placeholders unfilled.
	 *
	 * @param string $operation The operation.
	 */
	public function route_of( string $operation ): string {
		if ( isset( $this->routes[ $operation ] ) ) {
			return $this->routes[ $operation ];
		}
		$collection = self::LIST === $operation || self::CREATE === $operation || ( self::UPDATE === $operation && $this->replaces );
		return $collection ? $this->route : $this->route . '/{' . self::ID . '}';
	}

	/**
	 * The placeholders an operation's route needs, the item's own last.
	 *
	 * @param string $operation The operation.
	 * @return list<string>
	 */
	public function placeholders( string $operation ): array {
		preg_match_all( self::PLACEHOLDER_PATTERN, $this->route_of( $operation ), $found );
		$names = array_values( array_diff( $found[1], array( self::ID ) ) );
		return in_array( self::ID, $found[1], true ) ? array_merge( $names, array( self::ID ) ) : $names;
	}

	/**
	 * The schema of a placeholder's value.
	 *
	 * @param string $placeholder The placeholder.
	 * @return array<string, mixed>
	 */
	public function placeholder_schema( string $placeholder ): array {
		if ( self::ID === $placeholder && 'string' === $this->id_type ) {
			return array(
				'type'      => 'string',
				'minLength' => 1,
			);
		}
		// A settings group's id names the group; every other parent is a number.
		if ( 'group_id' === $placeholder ) {
			return array(
				'type'      => 'string',
				'minLength' => 1,
			);
		}
		return array(
			'type'    => 'integer',
			'minimum' => $this->allows_built_in( $placeholder ) ? self::BUILT_IN_ID : self::FIRST_ID,
		);
	}

	/**
	 * Whether a placeholder may be `BUILT_IN_ID`.
	 *
	 * @param string $placeholder The placeholder.
	 */
	private function allows_built_in( string $placeholder ): bool {
		return in_array( $placeholder, $this->built_in, true );
	}

	/**
	 * The route with each placeholder filled from the input, or null when
	 * one is missing or not of its kind.
	 *
	 * @param string $operation The operation.
	 * @param mixed  $input     The call's input.
	 */
	public function path( string $operation, mixed $input ): ?string {
		$input   = is_array( $input ) ? $input : array();
		$missing = false;
		$path    = (string) preg_replace_callback(
			self::PLACEHOLDER_PATTERN,
			function ( array $found ) use ( $input, &$missing ): string {
				$value  = $input[ $found[1] ] ?? null;
				$schema = $this->placeholder_schema( $found[1] );
				$number = $this->allows_built_in( $found[1] ) ? self::ID_OR_BUILT_IN_PATTERN : self::ID_PATTERN;
				$valid  = 'integer' === $schema['type']
					? ( is_int( $value ) && $value >= $schema['minimum'] ) || ( is_string( $value ) && 1 === preg_match( $number, $value ) )
					: is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $value );
				if ( ! $valid ) {
					$missing = true;
					return '';
				}
				return (string) $value;
			},
			$this->route_of( $operation )
		);
		return $missing ? null : '/' . $path;
	}
}
