<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Pixonaut\WooCommerce\Api\Answers\ConnectedStore;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStoreExchange;
use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Api\Answers\DesignerLink;
use Pixonaut\WooCommerce\Api\Answers\EventsPage;
use Pixonaut\WooCommerce\Api\Answers\LowResolutionImage;
use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Api\Answers\OrderDetail;
use Pixonaut\WooCommerce\Api\Answers\OrderList;
use Pixonaut\WooCommerce\Api\Answers\PrintSizeVerdicts;
use Pixonaut\WooCommerce\Api\Answers\ShareLinkPage;
use Pixonaut\WooCommerce\Api\Answers\WebhookEndpoint;
use Pixonaut\WooCommerce\Api\Answers\WebhookPing;
use Pixonaut\WooCommerce\Api\Answers\Read;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeResponseOption1;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreGetResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ConnectedStoreUpdateResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\DesignsGetResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\EventsListResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersCancelResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersCreateResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersDeliveryUpdateResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersGetResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersListResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersRetryResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersUpdateResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ShareLinksListResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\TemplatesDesignerLinksCreateResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\TemplatesPrintSizeValidateResponseOption3;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\WebhookEndpointsListResponseItem;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\WebhookEndpointsPingResponseFalse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\WebhookEndpointsPingResponseTrue;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Each answer model reads a few fields of an answer the SDK has a model of.
 * Every field it reads must be one the SDK's model has, under the same name
 * and of the same type, so moving to an SDK release that renames or retypes
 * a field the store reads fails here, rather than as a failed read in a
 * shop.
 */
final class AnswerModelsMatchSdkTest extends TestCase {

	private const ANSWERS_NAMESPACE = 'Pixonaut\\WooCommerce\\Api\\Answers\\';

	private const SDK_MODEL_NAMESPACE = 'Pixonaut\\WooCommerce\\Vendor\\Pixonaut\\Sdk\\Generated\\Model\\';

	/**
	 * Each top-level answer model, and the answers it reads: each answer as
	 * the SDK models of its variants, one for an answer of one shape. A
	 * field the model reads must be in every answer; within one answer, a
	 * field a variant lacks must allow null. Nested answer models are
	 * matched with the SDK classes their field holds in each answer.
	 *
	 * @var array<class-string, list<list<class-string>>>
	 */
	private const ANSWERS = array(
		Design::class                 => array( array( DesignsGetResponse::class ) ),
		ConnectedStore::class         => array( array( ConnectedStoreGetResponse::class ), array( ConnectedStoreUpdateResponse::class ) ),
		ConnectedStoreExchange::class => array( array( ConnectedStoreExchangeCodeResponseOption1::class ) ),
		WebhookEndpoint::class        => array( array( WebhookEndpointsListResponseItem::class ) ),
		WebhookPing::class            => array( array( WebhookEndpointsPingResponseTrue::class, WebhookEndpointsPingResponseFalse::class ) ),
		EventsPage::class             => array( array( EventsListResponse::class ) ),
		DesignerLink::class           => array( array( TemplatesDesignerLinksCreateResponse::class ) ),
		ShareLinkPage::class          => array( array( ShareLinksListResponse::class ) ),
		PrintSizeVerdicts::class      => array( array( TemplatesPrintSizeValidateResponseOption3::class ) ),
		// OrderDetail holds these fields of orders.get as its $order, so Order is matched with that answer too.
		Order::class                  => array(
			array( OrdersCreateResponse::class ),
			array( OrdersRetryResponse::class ),
			array( OrdersUpdateResponse::class ),
			array( OrdersCancelResponse::class ),
			array( OrdersDeliveryUpdateResponse::class ),
			array( OrdersGetResponse::class ),
		),
		OrderDetail::class            => array( array( OrdersGetResponse::class ) ),
		OrderList::class              => array( array( OrdersListResponse::class ) ),
	);

	/**
	 * Fields an answer model holds that are not fields of the answer, by model.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const NOT_FIELDS = array(
		// Stored as Pixonaut sent them, to be read later as the SDK's webhook event; the SDK types the list as a union of every event.
		EventsPage::class  => array( 'events' ),
		// The order as Pixonaut sent it, which the public line actions filter hands on.
		Order::class       => array( 'as_sent' ),
		// The fields every answer about an order has, matched as Order.
		OrderDetail::class => array( 'order' ),
	);

	/**
	 * Fields every answer sends that a model still lets be null, by model:
	 * the model is also read back from what the store kept, and a value kept
	 * before Pixonaut sent the field has none. Matched as never null.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const NULL_ONLY_WHEN_STORED = array(
		// An image kept with a product's size before Pixonaut named images.
		LowResolutionImage::class => array( 'label' ),
	);

	/**
	 * Classes in the folder that are not answer models.
	 *
	 * @var list<class-string>
	 */
	private const NOT_MODELS = array( Read::class );

	/**
	 * Answer models matched so far, nested ones included.
	 *
	 * @var array<string, true>
	 */
	private array $matched = array();

	public function test_every_field_an_answer_model_reads_is_the_sdk_models_field_of_the_same_type(): void {
		foreach ( self::ANSWERS as $answer => $answers ) {
			$this->match( $answer, $answers );
		}

		$unmatched = array_diff( self::models_in_folder(), array_keys( $this->matched ), self::NOT_MODELS );
		$this->assertSame( array(), array_values( $unmatched ), 'Every answer model is matched with an SDK model: list a new top-level one in ANSWERS.' );
	}

	public function test_a_field_of_another_type_fails_the_match(): void {
		try {
			$this->match_field( 'Probe', 'revision', 'string', false, array(), array( array( DesignsGetResponse::class ) ) );
		} catch ( AssertionFailedError $failure ) {
			$this->assertStringContainsString( 'DesignsGetResponse::$revision: another type', $failure->getMessage() );
			return;
		}
		$this->fail( 'A string matched the SDK\'s integer revision.' );
	}

	public function test_a_field_one_variant_lacks_must_allow_null(): void {
		try {
			$this->match_field( 'Probe', 'error', 'string', false, array(), array( array( WebhookEndpointsPingResponseTrue::class, WebhookEndpointsPingResponseFalse::class ) ) );
		} catch ( AssertionFailedError $failure ) {
			$this->assertStringContainsString( 'one allows null and the other does not', $failure->getMessage() );
			return;
		}
		$this->fail( 'A field one variant lacks matched a type without null.' );
	}

	public function test_a_field_one_answer_lacks_fails_the_match_even_allowing_null(): void {
		try {
			$this->match_field( 'Probe', 'error', 'string', true, array(), array( array( WebhookEndpointsPingResponseFalse::class ), array( WebhookEndpointsPingResponseTrue::class ) ) );
		} catch ( AssertionFailedError $failure ) {
			$this->assertStringContainsString( 'WebhookEndpointsPingResponseTrue::$error: the SDK model has no such field', $failure->getMessage() );
			return;
		}
		$this->fail( 'A field one of two answers lacks matched, as if they were variants of one.' );
	}

	/**
	 * Matches an answer model with the answers it reads.
	 *
	 * @param string $answer  The answer model.
	 * @param array  $answers Each answer, as the SDK models of its variants.
	 * @phpstan-param list<list<string>> $answers
	 */
	private function match( string $answer, array $answers ): void {
		$this->matched[ $answer ] = true;
		$skip                     = self::NOT_FIELDS[ $answer ] ?? array();
		foreach ( self::constructor( $answer )->getParameters() as $parameter ) {
			if ( in_array( $parameter->getName(), $skip, true ) ) {
				continue;
			}
			$type = $parameter->getType();
			$this->assertInstanceOf( ReflectionNamedType::class, $type, "{$answer}::\${$parameter->getName()} has one type." );
			$nullable = $type->allowsNull();
			if ( in_array( $parameter->getName(), self::NULL_ONLY_WHEN_STORED[ $answer ] ?? array(), true ) ) {
				$this->assertTrue( $nullable, "{$answer}::\${$parameter->getName()} never is null: drop it from NULL_ONLY_WHEN_STORED." );
				$nullable = false;
			}
			$this->match_field( $answer, $parameter->getName(), $type->getName(), $nullable, self::list_items( $parameter, self::ANSWERS_NAMESPACE ), $answers );
		}
	}

	/**
	 * A field matches when every answer has it in one of its variants, the
	 * SDK models that have it type it the same way, and it allows null
	 * exactly when one of them does or a variant lacks it. Answers are not
	 * pooled: a field one answer lacks fails the read of that answer, however
	 * many others send it.
	 *
	 * @param string   $answer   The answer model, for messages.
	 * @param string   $property Its property.
	 * @param string   $type     The property's type.
	 * @param bool     $nullable Whether it may be null.
	 * @param string[] $items    A list's item types, from the docblock.
	 * @param array    $answers  Each answer, as the SDK models of its variants.
	 * @phpstan-param list<string> $items
	 * @phpstan-param list<list<string>> $answers
	 */
	private function match_field( string $answer, string $property, string $type, bool $nullable, array $items, array $answers ): void {
		$field        = lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $property ) ) ) );
		$sdk_null     = false;
		$sdk_names    = array();
		$nested       = array();
		$nested_items = array();
		$sdk_items    = array();
		foreach ( $answers as $variants ) {
			$where  = "{$answer}::\${$property} against " . implode( ', ', array_map( static fn ( string $model ): string => "{$model}::\${$field}", $variants ) );
			$found  = array();
			$names  = array();
			$listed = array();
			foreach ( $variants as $sdk_model ) {
				$parameter = self::parameter( $sdk_model, $field );
				if ( null === $parameter ) {
					$sdk_null = true;
					continue;
				}
				$sdk_type = $parameter->getType();
				$this->assertNotNull( $sdk_type, $where );
				$found[]  = $parameter;
				$sdk_null = $sdk_null || $sdk_type->allowsNull();
				$listed   = array_values( array_unique( array_merge( $listed, self::list_items( $parameter, self::SDK_MODEL_NAMESPACE ) ) ) );
				if ( self::null_only( $parameter ) ) {
					// A variant that always sends null, e.g. a fill print's `designId`: the SDK types it `mixed`.
					continue;
				}
				$names = array_values( array_unique( array_merge( $names, self::names( $sdk_type ) ) ) );
			}
			$this->assertNotSame( array(), $found, "{$where}: the SDK model has no such field." );
			$sdk_names = array_values( array_unique( array_merge( $sdk_names, $names ) ) );
			$sdk_items = array_values( array_unique( array_merge( $sdk_items, $listed ) ) );
			if ( array() !== $names ) {
				$nested[] = $names;
			}
			if ( array() !== $listed ) {
				$nested_items[] = $listed;
			}
		}
		$where = "{$answer}::\${$property} against " . implode( ', ', array_map( static fn ( string $model ): string => "{$model}::\${$field}", array_merge( ...$answers ) ) );
		$this->assertSame( $sdk_null, $nullable, "{$where}: one allows null and the other does not." );

		if ( str_starts_with( $type, self::ANSWERS_NAMESPACE ) ) {
			// Each SDK model has a class of its own for the same nested object.
			foreach ( $sdk_names as $sdk_name ) {
				$this->assertTrue( class_exists( $sdk_name ), "{$where}: the SDK field is not an object." );
			}
			$this->match( $type, $nested );
			return;
		}
		$this->assertSame( array( $type ), $sdk_names, "{$where}: another type." );
		if ( 'array' !== $type ) {
			return;
		}
		$this->assertNotSame( array(), $items, "{$answer}::\${$property} says what its list holds." );
		$this->assertNotSame( array(), $sdk_items, "{$where}: the SDK field is not a list." );
		if ( 1 === count( $items ) && str_starts_with( $items[0], self::ANSWERS_NAMESPACE ) ) {
			foreach ( $sdk_items as $sdk_item ) {
				$this->assertTrue( class_exists( $sdk_item ), "{$where}: the SDK list does not hold objects." );
			}
			$this->match( $items[0], $nested_items );
			return;
		}
		$this->assertSame( $items, $sdk_items, "{$where}: a list of another type." );
	}

	/**
	 * Whether the SDK documents a parameter as always `null`.
	 *
	 * @param ReflectionParameter $parameter An SDK model's constructor parameter.
	 */
	private static function null_only( ReflectionParameter $parameter ): bool {
		$doc = $parameter->getDeclaringFunction()->getDocComment();
		return false !== $doc && 1 === preg_match( '/@param\s+null\s+\$' . preg_quote( $parameter->getName(), '/' ) . '\b/', $doc );
	}

	/**
	 * @param string $model A model.
	 * @param string $name  One of its constructor's parameters.
	 */
	private static function parameter( string $model, string $name ): ?ReflectionParameter {
		foreach ( self::constructor( $model )->getParameters() as $parameter ) {
			if ( $parameter->getName() === $name ) {
				return $parameter;
			}
		}
		return null;
	}

	/**
	 * @param ReflectionType $type A type.
	 *
	 * @return list<string> Its names, without null.
	 */
	private static function names( ReflectionType $type ): array {
		$types = $type instanceof ReflectionUnionType ? $type->getTypes() : array( $type );
		$names = array();
		foreach ( $types as $one ) {
			if ( $one instanceof ReflectionNamedType && 'null' !== $one->getName() ) {
				$names[] = $one->getName();
			}
		}
		return $names;
	}

	/**
	 * The item types of a `list<…>` parameter, from its constructor's
	 * docblock (`@param` or `@phpstan-param`); a list of one of several
	 * models names each.
	 *
	 * @param ReflectionParameter $parameter The parameter.
	 * @param string              $space     The namespace its short class names are in.
	 *
	 * @return list<string>
	 */
	private static function list_items( ReflectionParameter $parameter, string $space ): array {
		$doc     = $parameter->getDeclaringFunction()->getDocComment();
		$pattern = '/@(?:phpstan-)?param\s+list<([A-Za-z0-9_\\\\|]+)>(?:\|null)?\s+\$' . preg_quote( $parameter->getName(), '/' ) . '\b/';
		if ( false === $doc || 1 !== preg_match( $pattern, $doc, $match ) ) {
			return array();
		}
		$items = array();
		foreach ( explode( '|', $match[1] ) as $item ) {
			$item    = ltrim( $item, '\\' );
			$items[] = class_exists( $space . $item ) ? $space . $item : $item;
		}
		return $items;
	}

	/**
	 * @param string $model A model.
	 */
	private static function constructor( string $model ): ReflectionMethod {
		$reflected   = class_exists( $model ) ? new ReflectionClass( $model ) : null;
		$constructor = $reflected?->getConstructor();
		if ( null === $constructor ) {
			self::fail( "{$model} is not a class with a constructor." );
		}
		return $constructor;
	}

	/**
	 * @return list<string> The classes in `src/Api/Answers/`.
	 */
	private static function models_in_folder(): array {
		$files = glob( dirname( __DIR__, 5 ) . '/src/Api/Answers/*.php' );
		$found = false === $files ? array() : $files;
		return array_map( static fn ( string $file ): string => self::ANSWERS_NAMESPACE . basename( $file, '.php' ), $found );
	}
}
