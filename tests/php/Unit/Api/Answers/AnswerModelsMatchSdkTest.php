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
	 * Each top-level answer model, and the SDK models of every answer it reads.
	 * Nested answer models are matched with the SDK class their field holds.
	 *
	 * @var array<class-string, list<class-string>>
	 */
	private const ANSWERS = array(
		Design::class                 => array( DesignsGetResponse::class ),
		ConnectedStore::class         => array( ConnectedStoreGetResponse::class, ConnectedStoreUpdateResponse::class ),
		ConnectedStoreExchange::class => array( ConnectedStoreExchangeCodeResponseOption1::class ),
		WebhookEndpoint::class        => array( WebhookEndpointsListResponseItem::class ),
		WebhookPing::class            => array( WebhookEndpointsPingResponseTrue::class, WebhookEndpointsPingResponseFalse::class ),
		EventsPage::class             => array( EventsListResponse::class ),
		DesignerLink::class           => array( TemplatesDesignerLinksCreateResponse::class ),
		ShareLinkPage::class          => array( ShareLinksListResponse::class ),
		PrintSizeVerdicts::class      => array( TemplatesPrintSizeValidateResponseOption3::class ),
		// OrderDetail holds these fields of orders.get as its $order, so Order is matched with that answer too.
		Order::class                  => array( OrdersCreateResponse::class, OrdersRetryResponse::class, OrdersUpdateResponse::class, OrdersCancelResponse::class, OrdersDeliveryUpdateResponse::class, OrdersGetResponse::class ),
		OrderDetail::class            => array( OrdersGetResponse::class ),
		OrderList::class              => array( OrdersListResponse::class ),
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
		foreach ( self::ANSWERS as $answer => $sdk_models ) {
			$this->match( $answer, $sdk_models );
		}

		$unmatched = array_diff( self::models_in_folder(), array_keys( $this->matched ), self::NOT_MODELS );
		$this->assertSame( array(), array_values( $unmatched ), 'Every answer model is matched with an SDK model: list a new top-level one in ANSWERS.' );
	}

	public function test_a_field_of_another_type_fails_the_match(): void {
		try {
			$this->match_field( 'Probe', 'revision', 'string', false, array(), array( DesignsGetResponse::class ) );
		} catch ( AssertionFailedError $failure ) {
			$this->assertStringContainsString( 'DesignsGetResponse::$revision: another type', $failure->getMessage() );
			return;
		}
		$this->fail( 'A string matched the SDK\'s integer revision.' );
	}

	public function test_a_field_one_variant_lacks_must_allow_null(): void {
		try {
			$this->match_field( 'Probe', 'error', 'string', false, array(), array( WebhookEndpointsPingResponseTrue::class, WebhookEndpointsPingResponseFalse::class ) );
		} catch ( AssertionFailedError $failure ) {
			$this->assertStringContainsString( 'one allows null and the other does not', $failure->getMessage() );
			return;
		}
		$this->fail( 'A field one variant lacks matched a type without null.' );
	}

	/**
	 * Matches an answer model with the SDK models of the answers it reads: one
	 * model, or each variant of an answer that is one of several.
	 *
	 * @param string   $answer     The answer model.
	 * @param string[] $sdk_models The SDK models.
	 * @phpstan-param list<string> $sdk_models
	 */
	private function match( string $answer, array $sdk_models ): void {
		$this->matched[ $answer ] = true;
		$skip                     = self::NOT_FIELDS[ $answer ] ?? array();
		foreach ( self::constructor( $answer )->getParameters() as $parameter ) {
			if ( in_array( $parameter->getName(), $skip, true ) ) {
				continue;
			}
			$type = $parameter->getType();
			$this->assertInstanceOf( ReflectionNamedType::class, $type, "{$answer}::\${$parameter->getName()} has one type." );
			$this->match_field( $answer, $parameter->getName(), $type->getName(), $type->allowsNull(), self::list_items( $parameter, self::ANSWERS_NAMESPACE ), $sdk_models );
		}
	}

	/**
	 * A field matches when the SDK models that have it type it the same way,
	 * and it allows null exactly when one of them does or one lacks it.
	 *
	 * @param string   $answer     The answer model, for messages.
	 * @param string   $property   Its property.
	 * @param string   $type       The property's type.
	 * @param bool     $nullable   Whether it may be null.
	 * @param string[] $items      A list's item types, from the docblock.
	 * @param string[] $sdk_models The SDK models.
	 * @phpstan-param list<string> $items
	 * @phpstan-param list<string> $sdk_models
	 */
	private function match_field( string $answer, string $property, string $type, bool $nullable, array $items, array $sdk_models ): void {
		$field     = lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $property ) ) ) );
		$where     = "{$answer}::\${$property} against " . implode( ', ', array_map( static fn ( string $model ): string => "{$model}::\${$field}", $sdk_models ) );
		$found     = array();
		$sdk_null  = false;
		$sdk_names = array();
		foreach ( $sdk_models as $sdk_model ) {
			$parameter = self::parameter( $sdk_model, $field );
			if ( null === $parameter ) {
				$sdk_null = true;
				continue;
			}
			$sdk_type = $parameter->getType();
			$this->assertNotNull( $sdk_type, $where );
			$found[]  = $parameter;
			$sdk_null = $sdk_null || $sdk_type->allowsNull();
			if ( self::null_only( $parameter ) ) {
				// A variant that always sends null, e.g. a fill print's `designId`: the SDK types it `mixed`.
				continue;
			}
			$sdk_names = array_values( array_unique( array_merge( $sdk_names, self::names( $sdk_type ) ) ) );
		}
		$this->assertNotSame( array(), $found, "{$where}: the SDK model has no such field." );
		$this->assertSame( $sdk_null, $nullable, "{$where}: one allows null and the other does not." );

		if ( str_starts_with( $type, self::ANSWERS_NAMESPACE ) ) {
			// Each SDK model has a class of its own for the same nested object.
			foreach ( $sdk_names as $sdk_name ) {
				$this->assertTrue( class_exists( $sdk_name ), "{$where}: the SDK field is not an object." );
			}
			$this->match( $type, $sdk_names );
			return;
		}
		$this->assertSame( array( $type ), $sdk_names, "{$where}: another type." );
		if ( 'array' !== $type ) {
			return;
		}
		$this->assertNotSame( array(), $items, "{$answer}::\${$property} says what its list holds." );
		$sdk_items = array();
		foreach ( $found as $parameter ) {
			$sdk_items = array_values( array_unique( array_merge( $sdk_items, self::list_items( $parameter, self::SDK_MODEL_NAMESPACE ) ) ) );
		}
		$this->assertNotSame( array(), $sdk_items, "{$where}: the SDK field is not a list." );
		if ( 1 === count( $items ) && str_starts_with( $items[0], self::ANSWERS_NAMESPACE ) ) {
			foreach ( $sdk_items as $sdk_item ) {
				$this->assertTrue( class_exists( $sdk_item ), "{$where}: the SDK list does not hold objects." );
			}
			$this->match( $items[0], $sdk_items );
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
