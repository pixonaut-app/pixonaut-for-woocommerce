<?php
/**
 * Checks exchanges with Pixonaut against the pinned OpenAPI 3.1 spec and its overlay.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;

/**
 * The spec is loaded as pinned, with only what `spec-overlay.json` adds where
 * the pin lacks it, and each operation's schemas are addressed by JSON
 * Pointer into it, so `$ref`s resolve as Pixonaut wrote them. OpenAPI
 * 3.1 schemas are JSON Schema 2020-12; `x-extensible-enum` is not a keyword,
 * so open enums stay open.
 *
 * Requests are also checked against `consumed.json`: a call to an operation
 * the plugin has not declared it consumes fails the test.
 */
final class OpenApiAssert {

	private const CONTRACTS_DIR = __DIR__ . '/../../../contracts/pixonaut';

	private const OVERLAY_FILE = __DIR__ . '/../../fake-pixonaut/spec-overlay.json';

	/**
	 * The id the spec is registered under; only used to address it.
	 */
	private const SPEC_ID = 'https://contract.pixonaut.test/openapi.json';

	private const JSON = 'application/json';

	private const HTTP_METHODS = array( 'get', 'put', 'post', 'delete', 'patch', 'head', 'options' );

	/**
	 * Fields the spec requires of an answer and leaves out of its example,
	 * by operation, with a value that says nothing.
	 */
	private const FIELDS_EXAMPLES_LEAVE_OUT = array(
		'GET /v1/templates/{id}' => array( 'backgroundSeeThrough' => null ),
	);

	private static ?Validator $validator = null;

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $spec = null;

	/**
	 * @var array{operations: list<string>, webhooks: list<string>, keys: array<string, string|list<string>>, pending: list<string>}|null
	 */
	private static ?array $consumed = null;

	/**
	 * Fails unless the request matches a consumed operation in the spec.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $target  Path and query, e.g. `/v1/designs?externalRef=x`.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Raw body.
	 *
	 * @return string The operation id, e.g. `GET /v1/connect`.
	 *
	 * @throws AssertionFailedError When it does not.
	 */
	public static function request( string $method, string $target, array $headers, ?string $body ): string {
		[ $template, $path_values ] = self::match( $target );
		$id                         = strtoupper( $method ) . ' ' . $template;
		$operation                  = self::operation( $method, $template );

		if ( ! in_array( $id, self::consumed()['operations'], true ) ) {
			throw new AssertionFailedError( sprintf( '%s is not in contracts/pixonaut/consumed.json. Add it (with its keys entry) before calling it.', $id ) );
		}

		$query = array();
		parse_str( (string) parse_url( $target, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress in the unit suite.
		$headers = array_change_key_case( $headers, CASE_LOWER );
		$values  = array(
			'path'   => $path_values,
			'query'  => $query,
			'header' => $headers,
		);
		$params  = array_merge( self::spec()['paths'][ $template ]['parameters'] ?? array(), $operation['parameters'] ?? array() );
		foreach ( $params as $param ) {
			$in   = $param['in'];
			$name = 'header' === $in ? strtolower( $param['name'] ) : $param['name'];
			if ( ! isset( $values[ $in ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $name, $values[ $in ] ) ) {
				if ( ! empty( $param['required'] ) ) {
					throw new AssertionFailedError( sprintf( '%s: required %s parameter "%s" is missing.', $id, $in, $name ) );
				}
				continue;
			}
			self::validate( "{$id} {$in} parameter {$name}", self::coerce( $values[ $in ][ $name ], $param['schema'] ?? array() ), $param['schema'] ?? array() );
		}

		$request_body = $operation['requestBody'] ?? null;
		if ( null === $request_body ) {
			if ( null !== $body && '' !== $body ) {
				throw new AssertionFailedError( sprintf( '%s takes no request body.', $id ) );
			}
			return $id;
		}
		if ( null === $body || '' === $body ) {
			if ( ! empty( $request_body['required'] ) ) {
				throw new AssertionFailedError( sprintf( '%s requires a request body.', $id ) );
			}
			return $id;
		}
		if ( ! isset( $request_body['content']['application/json'] ) ) {
			throw new AssertionFailedError( sprintf( '%s takes no JSON body.', $id ) );
		}
		self::assert_pointer(
			"{$id} request body",
			self::decode( "{$id} request body", $body ),
			array( 'paths', $template, strtolower( $method ), 'requestBody', 'content', 'application/json', 'schema' )
		);
		return $id;
	}

	/**
	 * Fails unless the response is one the spec declares for the operation.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $target Path and query.
	 * @param int         $status HTTP status.
	 * @param string      $body         Raw body.
	 * @param string|null $content_type `Content-Type` header; JSON is assumed when null.
	 *
	 * @throws AssertionFailedError When it is not.
	 */
	public static function response( string $method, string $target, int $status, string $body, ?string $content_type = null ): void {
		[ $template ] = self::match( $target );
		$id           = strtoupper( $method ) . ' ' . $template;
		$responses    = self::operation( $method, $template )['responses'] ?? array();

		$key = null;
		foreach ( array( (string) $status, intdiv( $status, 100 ) . 'XX', 'default' ) as $candidate ) {
			if ( isset( $responses[ $candidate ] ) ) {
				$key = $candidate;
				break;
			}
		}
		if ( null === $key ) {
			throw new AssertionFailedError( sprintf( '%s declares no %d response.', $id, $status ) );
		}
		$content = $responses[ $key ]['content'] ?? array();
		if ( array() === $content ) {
			return;
		}
		$media = null === $content_type ? self::JSON : strtolower( trim( explode( ';', $content_type )[0] ) );
		if ( ! isset( $content[ $media ] ) ) {
			throw new AssertionFailedError( sprintf( '%s declares no %s body for %d.', $id, $media, $status ) );
		}
		if ( ! isset( $content[ $media ]['schema'] ) ) {
			return;
		}
		if ( self::JSON === $media ) {
			$data = self::decode( "{$id} {$status} response", $body );
		} elseif ( str_starts_with( $media, 'text/' ) ) {
			$data = $body;
		} else {
			return; // Binary bodies (images, PDFs) carry no schema worth checking.
		}
		self::assert_pointer(
			"{$id} {$status} {$media} response",
			$data,
			array( 'paths', $template, strtolower( $method ), 'responses', $key, 'content', $media, 'schema' )
		);
	}

	/**
	 * Fails unless the payload matches the webhook event's schema.
	 *
	 * @param string $type Event type, e.g. `design.approved`.
	 * @param string $body Raw delivery body.
	 *
	 * @throws AssertionFailedError When it does not.
	 */
	public static function webhook( string $type, string $body ): void {
		if ( ! isset( self::spec()['webhooks'][ $type ]['post']['requestBody']['content']['application/json']['schema'] ) ) {
			throw new AssertionFailedError( sprintf( 'The spec has no webhook "%s".', $type ) );
		}
		self::assert_pointer(
			"webhook {$type}",
			self::decode( "webhook {$type}", $body ),
			array( 'webhooks', $type, 'post', 'requestBody', 'content', 'application/json', 'schema' )
		);
	}

	/**
	 * Fails unless the data matches the schema at a point in the spec.
	 *
	 * @param string   $label    What is checked, for the failure message.
	 * @param mixed    $data     Decoded JSON, objects as `stdClass`.
	 * @param string[] $segments Unescaped JSON Pointer segments, e.g. `['components', 'schemas', 'ResolvedTemplate']`.
	 *
	 * @throws AssertionFailedError When it does not.
	 */
	public static function assert_pointer( string $label, mixed $data, array $segments ): void {
		$fragment = implode(
			'/',
			array_map( static fn ( string $segment ): string => rawurlencode( str_replace( array( '~', '/' ), array( '~0', '~1' ), $segment ) ), $segments )
		);
		$result   = self::validator()->validate( $data, self::SPEC_ID . '#/' . $fragment );
		$error    = $result->error();
		if ( null !== $error ) {
			throw new AssertionFailedError( sprintf( "%s does not match the spec:\n%s", $label, self::encode( ( new ErrorFormatter() )->format( $error ), JSON_PRETTY_PRINT ) ) );
		}
	}

	/**
	 * @return array{operations: list<string>, webhooks: list<string>, keys: array<string, string|list<string>>, pending: list<string>}
	 */
	public static function consumed(): array {
		if ( null === self::$consumed ) {
			/** @var array{operations: list<string>, webhooks: list<string>, keys: array<string, string|list<string>>, pending: list<string>} $consumed */
			$consumed       = json_decode( (string) file_get_contents( self::CONTRACTS_DIR . '/consumed.json' ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			self::$consumed = $consumed;
		}
		return self::$consumed;
	}

	/**
	 * A parameterless GET operation the spec has and `consumed.json` does not
	 * list, for tests of the consumed check.
	 *
	 * @return string Its path, e.g. `/v1/designs`.
	 *
	 * @throws AssertionFailedError When every such operation is consumed.
	 */
	public static function unconsumed_get_path(): string {
		foreach ( self::spec()['paths'] as $path => $operations ) {
			$path = (string) $path;
			if ( str_contains( $path, '{' ) || ! isset( $operations['get'] ) || in_array( 'GET ' . $path, self::consumed()['operations'], true ) ) {
				continue;
			}
			$required = array_filter( $operations['get']['parameters'] ?? array(), static fn ( array $param ): bool => ! empty( $param['required'] ) );
			if ( array() === $required ) {
				return $path;
			}
		}
		throw new AssertionFailedError( 'Every parameterless GET in the spec is consumed.' );
	}

	/**
	 * Finds the spec path a request target falls under. A literal segment
	 * beats a `{param}`, as in OpenAPI.
	 *
	 * @param string $target Path and query.
	 *
	 * @return array{0: string, 1: array<string, string>} Template and path values.
	 *
	 * @throws AssertionFailedError When no path matches.
	 */
	private static function match( string $target ): array {
		$path     = (string) parse_url( $target, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress in the unit suite.
		$segments = explode( '/', $path );
		$best     = null;
		foreach ( array_keys( self::spec()['paths'] ) as $template ) {
			$template = (string) $template;
			$parts    = explode( '/', $template );
			if ( count( $parts ) !== count( $segments ) ) {
				continue;
			}
			$values = array();
			$params = 0;
			foreach ( $parts as $i => $part ) {
				if ( 1 === preg_match( '/^\{(.+)\}$/', $part, $m ) ) {
					$values[ $m[1] ] = rawurldecode( $segments[ $i ] );
					++$params;
				} elseif ( $part !== $segments[ $i ] ) {
					continue 2;
				}
			}
			if ( null === $best || $params < $best[2] ) {
				$best = array( $template, $values, $params );
			}
		}
		if ( null === $best ) {
			throw new AssertionFailedError( sprintf( 'No path in the spec matches %s.', $path ) );
		}
		return array( $best[0], $best[1] );
	}

	/**
	 * @param string $method   HTTP method.
	 * @param string $template Spec path.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AssertionFailedError When the spec has no such operation.
	 */
	private static function operation( string $method, string $template ): array {
		$operation = self::spec()['paths'][ $template ][ strtolower( $method ) ] ?? null;
		if ( ! in_array( strtolower( $method ), self::HTTP_METHODS, true ) || ! is_array( $operation ) ) {
			throw new AssertionFailedError( sprintf( 'The spec has no operation %s %s.', strtoupper( $method ), $template ) );
		}
		return $operation;
	}

	/**
	 * Query and header values arrive as strings; the spec types some as numbers.
	 *
	 * @param mixed                $value  Raw value.
	 * @param array<string, mixed> $schema Parameter schema.
	 */
	private static function coerce( mixed $value, array $schema ): mixed {
		$type = $schema['type'] ?? null;
		if ( is_string( $value ) && in_array( $type, array( 'integer', 'number' ), true ) && is_numeric( $value ) ) {
			return 'integer' === $type && ctype_digit( ltrim( $value, '-' ) ) ? (int) $value : (float) $value;
		}
		if ( 'boolean' === $type && in_array( $value, array( 'true', 'false' ), true ) ) {
			return 'true' === $value;
		}
		return $value;
	}

	/**
	 * @param string               $label  What is checked.
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Inline schema (parameters carry no `$ref` in the spec).
	 *
	 * @throws AssertionFailedError When it does not match.
	 */
	private static function validate( string $label, mixed $value, array $schema ): void {
		$result = self::validator()->validate( $value, (object) json_decode( self::encode( $schema ) ) );
		$error  = $result->error();
		if ( null !== $error ) {
			throw new AssertionFailedError( sprintf( '%s does not match the spec: %s', $label, self::encode( ( new ErrorFormatter() )->format( $error ) ) ) );
		}
	}

	/**
	 * The unit suite runs without WordPress, so no `wp_json_encode()`.
	 *
	 * @param mixed $value Value.
	 * @param int   $flags Extra `json_encode()` flags.
	 */
	private static function encode( mixed $value, int $flags = 0 ): string {
		return (string) json_encode( $value, $flags | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @param string $label What is decoded.
	 * @param string $json  JSON.
	 *
	 * @throws AssertionFailedError When it is not JSON.
	 */
	private static function decode( string $label, string $json ): mixed {
		$data = json_decode( $json );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new AssertionFailedError( sprintf( '%s is not JSON: %s', $label, json_last_error_msg() ) );
		}
		return $data;
	}

	/**
	 * @throws AssertionFailedError When the spec is not a JSON object.
	 */
	private static function validator(): Validator {
		if ( null === self::$validator ) {
			$validator = new Validator();
			$validator->parser()->setOption( 'defaultDraft', '2020-12' );
			$document = self::document( false );
			if ( ! $document instanceof stdClass ) {
				throw new AssertionFailedError( 'openapi.json is not a JSON object.' );
			}
			$resolver = $validator->resolver();
			if ( null === $resolver ) {
				throw new AssertionFailedError( 'Opis has no schema resolver.' );
			}
			$resolver->registerRaw( $document, self::SPEC_ID );
			self::$validator = $validator;
		}
		return self::$validator;
	}

	/**
	 * The example the spec gives for an operation's JSON answer, encoded,
	 * with some top-level fields replaced. An empty object in the example
	 * stays one, as it would not through an array.
	 *
	 * @param string               $method   `GET`, `POST`, ….
	 * @param string               $template The path as the spec names it, e.g. `/v1/templates/{id}`.
	 * @param int                  $status   The answer's status.
	 * @param array<string, mixed> $fields   Top-level fields to replace.
	 *
	 * @throws AssertionFailedError When the spec gives no such example.
	 */
	public static function example( string $method, string $template, int $status, array $fields = array() ): string {
		self::operation( $method, $template );
		$document = self::document( false );
		$examples = $document->paths->{$template}->{strtolower( $method )}->responses->{ (string) $status}->content->{'application/json'}->examples ?? null;
		$example  = $examples instanceof stdClass ? current( get_object_vars( $examples ) ) : null;
		if ( ! $example instanceof stdClass || ! ( $example->value ?? null ) instanceof stdClass ) {
			throw new AssertionFailedError( sprintf( 'The spec gives no example for %s %s %d.', $method, $template, $status ) );
		}
		foreach ( $fields + ( self::FIELDS_EXAMPLES_LEAVE_OUT[ $method . ' ' . $template ] ?? array() ) as $field => $value ) {
			$example->value->{$field} = $value;
		}
		return self::encode( $example->value );
	}

	/**
	 * What the spec, with its overlay, holds at a path.
	 *
	 * @param string[] $segments E.g. `array( 'paths', '/v1/share-links', 'post' )`.
	 * @return mixed Null when it holds nothing there.
	 */
	public static function at( array $segments ): mixed {
		$node = self::spec();
		foreach ( $segments as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return null;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec(): array {
		if ( null === self::$spec ) {
			/** @var array<string, mixed> $spec */
			$spec       = self::document( true );
			self::$spec = $spec;
		}
		return self::$spec;
	}

	/**
	 * The pinned spec with what `spec-overlay.json` adds where the pin lacks
	 * it, as `bin/contracts/overlay.mjs` merges it for the fake.
	 *
	 * @param bool $assoc Decode objects as arrays rather than `stdClass`.
	 */
	private static function document( bool $assoc ): mixed {
		$document = json_decode( (string) file_get_contents( self::CONTRACTS_DIR . '/openapi.json' ), $assoc, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$overlay  = json_decode( (string) file_get_contents( self::OVERLAY_FILE ), $assoc, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$entries  = $assoc ? $overlay['entries'] : $overlay->entries;
		foreach ( $entries as $entry ) {
			$pointer = $assoc ? $entry['pointer'] : $entry->pointer;
			$tokens  = array_map( static fn ( string $token ): string => str_replace( array( '~1', '~0' ), array( '/', '~' ), $token ), explode( '/', substr( $pointer, 1 ) ) );
			$key     = array_pop( $tokens );
			$parent  = &$document;
			// A JSON list decodes to an array either way, so a pointer may index one, e.g. to add to a `oneOf`.
			foreach ( $tokens as $token ) {
				if ( is_array( $parent ) && array_key_exists( $token, $parent ) ) {
					$parent = &$parent[ $token ];
				} elseif ( ! $assoc && $parent instanceof stdClass && property_exists( $parent, $token ) ) {
					$parent = &$parent->{$token};
				} else {
					continue 2;
				}
			}
			$value = $assoc ? $entry['value'] : $entry->value;
			if ( is_array( $parent ) && ! array_key_exists( $key, $parent ) ) {
				$parent[ $key ] = $value;
			} elseif ( ! $assoc && $parent instanceof stdClass && ! property_exists( $parent, $key ) ) {
				$parent->{$key} = $value;
			}
			unset( $parent );
		}
		return $document;
	}
}
