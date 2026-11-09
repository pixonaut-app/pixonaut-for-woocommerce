<?php
/**
 * Reading the fields of a Pixonaut answer the store uses.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Closure;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Model\Decode;

/**
 * The SDK's checks, field by field, for the answer models in this folder.
 *
 * An answer model holds only the fields the store reads, so a field
 * Pixonaut stops sending that the store never reads does not fail the read,
 * as it fails the SDK's own model, which checks every field its release
 * knows. A field the store reads must be there with its type, `null`
 * included where the SDK allows it; otherwise the read throws
 * `UnexpectedResponse` naming its JSON path, which `answer()` turns into
 * `ApiException` `unexpected_response`.
 */
final class Read {

	/**
	 * An answer as one of the answer models.
	 *
	 * @template T
	 *
	 * @param Result  $result      The answer.
	 * @param Closure $from_answer The model's `from_answer()`.
	 * @phpstan-param Closure(array<string, mixed>, string): T $from_answer
	 *
	 * @return T
	 *
	 * @throws ApiException `unexpected_response` when a field the model reads is missing or of another type.
	 */
	public static function answer( Result $result, Closure $from_answer ): mixed {
		return $result->decode( static fn ( mixed $json ): mixed => $from_answer( Decode::object( $json, '$' ), '$' ) );
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or not a string.
	 */
	public static function string( array $data, string $key, string $path ): string {
		return Decode::string( Decode::required( $data, $key, $path ), Decode::field( $path, $key ) );
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field, which may be `null`.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or neither a string nor `null`.
	 */
	public static function nullable_string( array $data, string $key, string $path ): ?string {
		return self::nullable( $data, $key, $path, static fn ( mixed $value, string $at ): string => Decode::string( $value, $at ) );
	}

	/**
	 * A field Pixonaut leaves out rather than send as `null`.
	 *
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field, which may be left out.
	 * @param string               $path Where the object is.
	 *
	 * @return string|null Null when it is left out.
	 *
	 * @throws UnexpectedResponse When the field is there and not a string.
	 */
	public static function optional_string( array $data, string $key, string $path ): ?string {
		return array_key_exists( $key, $data ) ? Decode::string( $data[ $key ], Decode::field( $path, $key ) ) : null;
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or not an integer.
	 */
	public static function int( array $data, string $key, string $path ): int {
		return Decode::int( Decode::required( $data, $key, $path ), Decode::field( $path, $key ) );
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field, which may be `null`.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or neither an integer nor `null`.
	 */
	public static function nullable_int( array $data, string $key, string $path ): ?int {
		return self::nullable( $data, $key, $path, static fn ( mixed $value, string $at ): int => Decode::int( $value, $at ) );
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or not a number.
	 */
	public static function float( array $data, string $key, string $path ): float {
		return Decode::float( Decode::required( $data, $key, $path ), Decode::field( $path, $key ) );
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field, which may be `null`.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or neither a number nor `null`.
	 */
	public static function nullable_float( array $data, string $key, string $path ): ?float {
		return self::nullable( $data, $key, $path, static fn ( mixed $value, string $at ): float => Decode::float( $value, $at ) );
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field.
	 * @param string               $path Where the object is.
	 *
	 * @throws UnexpectedResponse When the field is missing or not a boolean.
	 */
	public static function bool( array $data, string $key, string $path ): bool {
		return Decode::bool( Decode::required( $data, $key, $path ), Decode::field( $path, $key ) );
	}

	/**
	 * A nested object, as its own answer model.
	 *
	 * @template T
	 *
	 * @param array<string, mixed> $data        The object.
	 * @param string               $key         The field.
	 * @param string               $path        Where the object is.
	 * @param Closure              $from_answer The nested model's `from_answer()`.
	 * @phpstan-param Closure(array<string, mixed>, string): T $from_answer
	 *
	 * @return T
	 *
	 * @throws UnexpectedResponse When the field is missing or not an object, or the nested model's fields are not readable.
	 */
	public static function object( array $data, string $key, string $path, Closure $from_answer ): mixed {
		$at = Decode::field( $path, $key );
		return $from_answer( Decode::object( Decode::required( $data, $key, $path ), $at ), $at );
	}

	/**
	 * A nested object that may be `null`, as its own answer model.
	 *
	 * @template T
	 *
	 * @param array<string, mixed> $data        The object.
	 * @param string               $key         The field.
	 * @param string               $path        Where the object is.
	 * @param Closure              $from_answer The nested model's `from_answer()`.
	 * @phpstan-param Closure(array<string, mixed>, string): T $from_answer
	 *
	 * @return T|null
	 *
	 * @throws UnexpectedResponse When the field is missing or neither an object nor `null`, or the nested model's fields are not readable.
	 */
	public static function nullable_object( array $data, string $key, string $path, Closure $from_answer ): mixed {
		return self::nullable( $data, $key, $path, static fn ( mixed $value, string $at ): mixed => $from_answer( Decode::object( $value, $at ), $at ) );
	}

	/**
	 * A list of objects, each as an answer model.
	 *
	 * @template T
	 *
	 * @param array<string, mixed> $data        The object.
	 * @param string               $key         The field.
	 * @param string               $path        Where the object is.
	 * @param Closure              $from_answer The item model's `from_answer()`.
	 * @phpstan-param Closure(array<string, mixed>, string): T $from_answer
	 *
	 * @return list<T>
	 *
	 * @throws UnexpectedResponse When the field is missing or not a list of readable objects.
	 */
	public static function objects( array $data, string $key, string $path, Closure $from_answer ): array {
		return Decode::list(
			Decode::required( $data, $key, $path ),
			Decode::field( $path, $key ),
			static fn ( mixed $value, string $at ): mixed => $from_answer( Decode::object( $value, $at ), $at )
		);
	}

	/**
	 * A list of strings.
	 *
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field.
	 * @param string               $path Where the object is.
	 *
	 * @return list<string>
	 *
	 * @throws UnexpectedResponse When the field is missing or not a list of strings.
	 */
	public static function strings( array $data, string $key, string $path ): array {
		return Decode::list(
			Decode::required( $data, $key, $path ),
			Decode::field( $path, $key ),
			static fn ( mixed $value, string $at ): string => Decode::string( $value, $at )
		);
	}

	/**
	 * @template T
	 *
	 * @param array<string, mixed> $data The object.
	 * @param string               $key  The field, which may be `null`.
	 * @param string               $path Where the object is.
	 * @param Closure              $item How a value that is not `null` is read.
	 * @phpstan-param Closure(mixed, string): T $item
	 *
	 * @return T|null
	 *
	 * @throws UnexpectedResponse When the field is missing or not readable.
	 */
	private static function nullable( array $data, string $key, string $path, Closure $item ): mixed {
		return Decode::nullable( Decode::required( $data, $key, $path ), Decode::field( $path, $key ), $item );
	}
}
