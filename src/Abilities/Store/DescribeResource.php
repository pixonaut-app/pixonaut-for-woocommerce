<?php
/**
 * The ability that tells which fields a kind of thing in the store has.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities\Store;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\Ability;
use WP_Error;
use WP_REST_Request;

/**
 * Reads a resource's fields from its REST route's own schema, so a caller
 * writes a create or an update with the fields this store's WordPress and
 * WooCommerce take, extensions' included, rather than ones it remembers.
 * Only the fields a create or an update may set are given.
 */
final class DescribeResource implements Ability {

	/**
	 * The ability's name, which the other store abilities point to.
	 */
	public const NAME = 'describe-store-resource';

	/**
	 * The parts of a field's schema a caller is given.
	 */
	private const KEPT = array( 'type', 'description', 'enum', 'format', 'properties', 'items', 'required', 'default' );

	/**
	 * What fills a placeholder for the schema: any value the route takes.
	 */
	private const ANY_ID = '1';

	private const ANY_NAME = 'general';

	/**
	 * @param StoreResource[] $resources The resources it describes.
	 * @phpstan-param list<StoreResource> $resources
	 */
	public function __construct( private readonly array $resources ) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Describe a kind of thing in the store', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Gives the fields a create or an update of one kind of thing in the store takes, as this store\'s REST route describes them: each field\'s type, description and allowed values.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'resource' => array(
						'type' => 'string',
						'enum' => array_map( static fn ( StoreResource $kind ): string => $kind->key, $this->resources ),
					),
				),
				'required'             => array( 'resource' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'resource' => array( 'type' => 'string' ),
					'route'    => array(
						'type'        => 'string',
						'description' => __( 'The REST route a create, or else an update, runs through.', 'pixonaut-for-woocommerce' ),
					),
					'fields'   => array(
						'type'                 => 'object',
						'description'          => __( 'Each writable field\'s JSON schema, by its name.', 'pixonaut-for-woocommerce' ),
						'additionalProperties' => array( 'type' => 'object' ),
					),
				),
			),
			'category'            => Abilities::STORE_CATEGORY,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'can_run' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Whether the user chatting may run some operation on the resource.
	 *
	 * @param mixed $input `resource`.
	 */
	public function can_run( mixed $input = null ): bool {
		$resource = $this->resource( $input );
		if ( null === $resource ) {
			return false;
		}
		foreach ( $resource->capabilities as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $input `resource`.
	 * @return array<string, mixed>|WP_Error `{resource, route, fields}`.
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$resource = $this->resource( $input );
		if ( null === $resource ) {
			return new WP_Error( 'pixonaut_wc_store_resource_unknown', __( 'The store has no such kind of thing.', 'pixonaut-for-woocommerce' ) );
		}
		$operation = in_array( StoreResource::CREATE, $resource->operations(), true ) ? StoreResource::CREATE : StoreResource::UPDATE;
		$values    = array();
		foreach ( $resource->placeholders( $operation ) as $placeholder ) {
			$values[ $placeholder ] = 'integer' === $resource->placeholder_schema( $placeholder )['type'] ? self::ANY_ID : self::ANY_NAME;
		}
		$path     = (string) $resource->path( $operation, $values );
		$response = rest_do_request( new WP_REST_Request( 'OPTIONS', $path ) );
		if ( $response->is_error() ) {
			return ResourceAbility::refusal( $response );
		}
		$data       = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		$properties = is_array( $data['schema']['properties'] ?? null ) ? $data['schema']['properties'] : array();
		$fields     = array();
		foreach ( $properties as $name => $schema ) {
			if ( is_array( $schema ) && true !== ( $schema['readonly'] ?? false ) ) {
				$fields[ $name ] = self::kept( $schema );
			}
		}
		return array(
			'resource' => $resource->key,
			'route'    => $resource->route_of( $operation ),
			'fields'   => $fields,
		);
	}

	/**
	 * @param mixed $input The call's input.
	 */
	private function resource( mixed $input ): ?StoreResource {
		$key = is_array( $input ) ? ( $input['resource'] ?? null ) : null;
		foreach ( $this->resources as $resource ) {
			if ( $resource->key === $key ) {
				return $resource;
			}
		}
		return null;
	}

	/**
	 * A field's schema without what only the REST API reads, such as its
	 * contexts, and without the fields nested in it that are read only.
	 *
	 * @param array<mixed> $schema A field's schema.
	 * @return array<mixed>
	 */
	private static function kept( array $schema ): array {
		$kept = array_intersect_key( $schema, array_flip( self::KEPT ) );
		if ( is_array( $kept['properties'] ?? null ) ) {
			$properties = array();
			foreach ( $kept['properties'] as $name => $child ) {
				if ( is_array( $child ) && true !== ( $child['readonly'] ?? false ) ) {
					$properties[ $name ] = self::kept( $child );
				}
			}
			$kept['properties'] = $properties;
		}
		if ( is_array( $kept['items'] ?? null ) ) {
			$kept['items'] = self::kept( $kept['items'] );
		}
		return $kept;
	}
}
