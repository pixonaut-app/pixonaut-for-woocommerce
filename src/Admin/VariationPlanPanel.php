<?php
/**
 * Variations from the blank, in a variable product's Variations tab.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Closure;
use Pixonaut\WooCommerce\Abilities\GenerateProductVariations;
use Pixonaut\WooCommerce\Abilities\VariationPlanRows;
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Catalogue\VariationChanges;
use Pixonaut\WooCommerce\Catalogue\VariationPlan;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WP_Error;
use WP_Http;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * **Variations from the blank**, above a variable product's variations:
 * the blanks its variations are printed on, the attribute value that picks
 * each, and the options no attribute offers, with Preview, which lists
 * what generating would add, change and disable, and Generate, which does
 * it, as `generate-product-variations` does. The values offered are the
 * product's attributes, set in its Attributes tab.
 *
 * Both buttons post to `ROUTE`, which answers the section drawn again
 * with what was found or done.
 */
final class VariationPlanPanel {

	public const ROUTE = '/products/(?P<product>\d+)/variation-plan';

	/**
	 * @param Closure   $generate  Gives the ability that generates the variations, once it is needed.
	 * @param Templates $templates Renders the section.
	 * @param Closure   $connected `fn(): bool`, whether the store can ask Pixonaut.
	 * @phpstan-param Closure(): GenerateProductVariations $generate
	 * @phpstan-param Closure(): bool $connected
	 */
	public function __construct(
		private readonly Closure $generate,
		private readonly Templates $templates,
		private readonly Closure $connected,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'woocommerce_variable_product_before_variations', array( $this, 'render' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'act' ),
				'permission_callback' => static fn ( WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['product'] ),
				'args'                => array(
					'entries' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => VariationPlan::MAX_ENTRIES,
						'items'    => VariationPlanRows::entry_schema(),
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * POST ROUTE: lists what generating the variations from the plan sent
	 * would change (`dry_run`, the default), or saves the plan and does it.
	 * Answers `{html, message, changed}`: the section drawn again with the
	 * changes listed, what to say, and whether any variation changed.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function act( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product = wc_get_product( (int) $request['product'] );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'pixonaut_wc_ability_not_variable', __( 'No variable product has this id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		$dry_run = false !== $request['dry_run'];
		$plan    = VariationPlan::from_input( $request['entries'] );
		$answer  = ( $this->generate )()->run( $product, $plan, $dry_run, ! $dry_run );
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}
		$fresh   = wc_get_product( $product->get_id() );
		$changed = ! $dry_run && ( array() !== $answer['add'] || array() !== $answer['change'] || array() !== $answer['disable'] );
		return new WP_REST_Response(
			array(
				'html'    => $this->html( $fresh instanceof WC_Product ? $fresh : $product, $dry_run ? $plan : null, $answer ),
				'message' => self::message( $answer ),
				'changed' => $changed,
			)
		);
	}

	/**
	 * Prints the section above the variations.
	 */
	public function render(): void {
		global $product_object;
		if ( $product_object instanceof WC_Product && $product_object->is_type( 'variable' ) && ( $this->connected )() ) {
			echo $this->html( $product_object ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in admin/product-variation-plan.php.
		}
	}

	/**
	 * The section, as `admin/product-variation-plan.php` prints it.
	 *
	 * @param WC_Product                $product A variable product.
	 * @param VariationPlan|null        $plan    The plan to show; null for the one stored.
	 * @param array<string, mixed>|null $answer  What a preview or a run answered; null for none.
	 */
	public function html( WC_Product $product, ?VariationPlan $plan = null, ?array $answer = null ): string {
		$plan     ??= VariationPlan::stored_on( $product );
		$attributes = VariationPlanRows::attributes( $product, $plan );
		return $this->templates->html(
			'admin/product-variation-plan.php',
			array(
				'url'        => rest_url( Plugin::REST_NAMESPACE . '/products/' . $product->get_id() . '/variation-plan' ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
				'entries'    => null === $plan || array() === $plan->entries ? array( self::first_entry( $product ) ) : $plan->entries,
				'blanks'     => VariationPlanRows::blanks( $product ),
				// An attribute linked to a blank option names it; any other can pick a blank.
				'pickers'    => array_values( array_filter( $attributes, static fn ( array $row ): bool => null === BlankOptionLinks::option_of( (string) $row['attribute'] ) ) ),
				'linked'     => array_values( array_filter( $attributes, static fn ( array $row ): bool => null !== $row['option'] ) ),
				'answer'     => null === $answer ? null : self::listed( $answer ),
				'dry_run'    => null !== $answer && true === $answer['dry_run'],
				'why_texts'  => self::why_texts(),
			)
		);
	}

	/**
	 * The entry a product without a plan starts from: its own blank.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array{blank_id: string, when: array<string, string>, fixed: array<string, string>}
	 */
	private static function first_entry( WC_Product $product ): array {
		return array(
			'blank_id' => VariationPlanRows::blanks( $product )[0]['blank_id'] ?? '',
			'when'     => array(),
			'fixed'    => array(),
		);
	}

	/**
	 * The lists the section shows of an answer.
	 *
	 * @param array<string, mixed> $answer What `generate-product-variations` answered.
	 * @return array{add: list<array<string, mixed>>, change: list<array<string, mixed>>, disable: list<array<string, mixed>>, skipped: list<array<string, mixed>>, kept: int, remaining: int, unpriced: int}
	 */
	private static function listed( array $answer ): array {
		$list = static fn ( string $key ): array => is_array( $answer[ $key ] ?? null ) ? array_values( $answer[ $key ] ) : array();
		return array(
			'add'       => $list( 'add' ),
			'change'    => $list( 'change' ),
			'disable'   => $list( 'disable' ),
			'skipped'   => $list( 'skipped' ),
			'kept'      => (int) ( $answer['kept'] ?? 0 ),
			'remaining' => (int) ( $answer['remaining'] ?? 0 ),
			'unpriced'  => count( $list( 'unpriced' ) ),
		);
	}

	/**
	 * What the section says once a preview or a run answered.
	 *
	 * @param array<string, mixed> $answer What `generate-product-variations` answered.
	 */
	private static function message( array $answer ): string {
		$add     = is_array( $answer['add'] ?? null ) ? count( $answer['add'] ) : 0;
		$change  = is_array( $answer['change'] ?? null ) ? count( $answer['change'] ) : 0;
		$disable = is_array( $answer['disable'] ?? null ) ? count( $answer['disable'] ) : 0;
		if ( 0 === $add + $change + $disable ) {
			return __( 'The variations already match the plan.', 'pixonaut-for-woocommerce' );
		}
		return true === $answer['dry_run']
			/* translators: 1: variations to add, 2: to change, 3: to disable. Said in the Variations tab of the product edit page after Preview. */
			? sprintf( __( 'Generating would add %1$d, change %2$d and disable %3$d variations.', 'pixonaut-for-woocommerce' ), $add, $change, $disable )
			/* translators: 1: variations added, 2: changed, 3: disabled. Said in the Variations tab of the product edit page after Generate. */
			: sprintf( __( 'Added %1$d, changed %2$d and disabled %3$d variations.', 'pixonaut-for-woocommerce' ), $add, $change, $disable );
	}

	/**
	 * What each reason in the lists says, by reason.
	 *
	 * @return array<string, string>
	 */
	private static function why_texts(): array {
		return array(
			VariationChanges::ENABLE           => __( 'offered again', 'pixonaut-for-woocommerce' ),
			VariationChanges::VARIANT          => __( 'sold no blank variant', 'pixonaut-for-woocommerce' ),
			VariationChanges::NOT_OFFERED      => __( 'no longer offered', 'pixonaut-for-woocommerce' ),
			VariationChanges::RETIRED          => __( 'its blank variant is retired', 'pixonaut-for-woocommerce' ),
			VariationChanges::NO_VARIANT       => __( 'the blank has no such variant', 'pixonaut-for-woocommerce' ),
			VariationChanges::SEVERAL_VARIANTS => __( 'an option of the blank is left open', 'pixonaut-for-woocommerce' ),
		);
	}
}
