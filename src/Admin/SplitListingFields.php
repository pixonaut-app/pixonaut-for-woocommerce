<?php
/**
 * Whether each value of an attribute is listed as its own product, on the
 * attribute screens and the product's Attributes tab.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use LogicException;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardImageCopies;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WC_Product_Attribute;

/**
 * A menu on a global attribute's add and edit screens sets how the shop
 * lists its values by default: a card per value always, only while a
 * filter chooses one, or none; a menu on each global attribute's row in the
 * product's Attributes tab follows it (Auto) or sets it for the product.
 * Under it, each of the row's values has a title and an image field for its
 * card in the shop, shown while the row's choice lists the values as their
 * own products. The row's choice and the cards' titles and images are saved
 * with the product and with WooCommerce's Save attributes button. Custom
 * attributes have no control (`SplitListing`).
 */
final class SplitListingFields {

	/**
	 * The attribute screens' menu.
	 */
	public const ATTRIBUTE_FIELD = 'pixonaut_wc_split_attribute';

	/**
	 * The product rows' choices, by the row's index in WooCommerce's `attribute_names`.
	 */
	public const PRODUCT_FIELD = 'pixonaut_wc_split_listing';

	/**
	 * Each value's card's own title and image, by term id (`SplitCardLooks`).
	 */
	public const CARD_FIELD = 'pixonaut_wc_split_card';

	/**
	 * The image size a card's image is previewed at.
	 */
	private const PREVIEW_SIZE = 'thumbnail';

	public const NONCE_ACTION = 'pixonaut_wc_save_split_listing';

	public const NONCE_FIELD = 'pixonaut_wc_split_listing_nonce';

	/**
	 * After WooCommerce saved the product's attributes and `ProductPanel` its configuration, at 10.
	 */
	private const SAVE_PRIORITY = 20;

	/**
	 * Before WooCommerce's own Save attributes handlers, at 10, which end the request.
	 */
	private const AJAX_PRIORITY = 5;

	/**
	 * What WordPress puts before an admin-ajax action's name to make its hook.
	 */
	private const AJAX_PREFIX = 'wp_ajax_';

	/**
	 * WooCommerce's admin-ajax actions that save the Attributes tab without
	 * the product, with the nonce action each checks.
	 */
	private const AJAX_SAVES = array(
		'woocommerce_save_attributes'               => 'save-attributes',
		'woocommerce_add_attributes_and_variations' => 'add-attributes-and-variations',
	);

	/**
	 * @param Templates $templates Renders the fields.
	 */
	public function __construct( private readonly Templates $templates ) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_add_attribute_fields', array( $this, 'render_add' ) );
		add_action( 'woocommerce_after_edit_attribute_fields', array( $this, 'render_edit' ) );
		add_action( 'woocommerce_attribute_added', array( $this, 'save_attribute' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'save_attribute' ) );
		add_action( 'woocommerce_after_product_attribute_settings', array( $this, 'render_row' ), 10, 2 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ), self::SAVE_PRIORITY );
		foreach ( array_keys( self::AJAX_SAVES ) as $action ) {
			add_action( self::AJAX_PREFIX . $action, array( $this, 'save_attributes_tab' ), self::AJAX_PRIORITY );
		}
	}

	public function render_add(): void {
		$this->render_attribute_field( false, SplitListing::OFF );
	}

	public function render_edit(): void {
		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which attribute the screen edits.
		$this->render_attribute_field( true, $attribute_id > 0 ? SplitListing::attribute_mode( $attribute_id ) : SplitListing::OFF );
	}

	/**
	 * Saves the menu when the attribute screen posted it.
	 *
	 * @param int $attribute_id The attribute added or updated.
	 */
	public function save_attribute( int $attribute_id ): void {
		if ( ! self::posted_nonce_valid( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is what is checked.
			return;
		}
		$mode = isset( $_POST[ self::ATTRIBUTE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ATTRIBUTE_FIELD ] ) ) : SplitListing::OFF; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		SplitListing::set_attribute_mode( $attribute_id, $mode );
	}

	/**
	 * Prints the control on a global attribute's row.
	 *
	 * @param mixed $attribute The row's attribute.
	 * @param mixed $index     The row's index in the posted rows.
	 */
	public function render_row( mixed $attribute, mixed $index ): void {
		if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->is_taxonomy() || $attribute->get_id() < 1 ) {
			return;
		}
		$product = self::product_on_screen();
		$config  = null === $product ? new ProductConfig() : ProductConfig::stored_on( $product );
		$row     = (string) ( is_int( $index ) || is_string( $index ) ? $index : '' );
		$this->templates->render(
			'admin/split-listing-control.php',
			array(
				'field'       => self::PRODUCT_FIELD . '[' . $row . ']',
				'id'          => 'pixonaut-split-listing-' . $row,
				'choice'      => SplitListing::choice( $config, $attribute->get_id() ),
				'options'     => self::choices( SplitListing::attribute_mode( $attribute->get_id() ) ),
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
		$cards = self::cards( $attribute, $product );
		if ( array() === $cards ) {
			return;
		}
		$this->templates->render(
			'admin/split-card-looks.php',
			array(
				'field'          => self::CARD_FIELD,
				'id'             => 'pixonaut-split-card-' . $row,
				'shown'          => SplitListing::splits( $config, $attribute->get_id() ),
				'attribute_mode' => SplitListing::attribute_mode( $attribute->get_id() ),
				'product_title'  => null === $product ? '' : $product->get_name(),
				'cards'          => $cards,
			)
		);
	}

	/**
	 * The row's cards, each with its own title and image: one per value, or,
	 * for a product listed by several attributes, the combinations that end
	 * with the value, so each of the product's cards is on one row. A value
	 * whose every card goes on with another attribute's value has none here.
	 *
	 * @param WC_Product_Attribute $attribute A global attribute's row.
	 * @param WC_Product|null      $product   The product on screen; null before it is saved.
	 * @return list<array{key: string, name: string, title: string, image: int, image_url: string}>
	 */
	private static function cards( WC_Product_Attribute $attribute, ?WC_Product $product ): array {
		$looks  = null === $product ? array() : SplitCardLooks::stored_on( $product->get_id() );
		$listed = null === $product ? array() : array_map( static fn ( string $key ): array => SplitCards::card_terms( $product, $key ), SplitCards::keys( $product ) );
		$cards  = array();
		foreach ( $attribute->get_terms() ?? array() as $term ) {
			$holding = array_values( array_filter( $listed, static fn ( array $term_ids ): bool => in_array( $term->term_id, $term_ids, true ) ) );
			$ending  = array_values( array_filter( $holding, static fn ( array $term_ids ): bool => end( $term_ids ) === $term->term_id ) );
			// A value in no card now, as while the product is listed once, keeps the fields of its own card.
			foreach ( array() === $holding ? array( array( $term->term_id ) ) : $ending as $term_ids ) {
				$key     = SplitCards::key( $term_ids );
				$look    = $looks[ $key ] ?? array(
					'title' => '',
					'image' => 0,
				);
				$url     = $look['image'] > 0 ? wp_get_attachment_image_url( $look['image'], self::PREVIEW_SIZE ) : false;
				$cards[] = array(
					'key'       => $key,
					'name'      => SplitCards::name( $term_ids ),
					'title'     => $look['title'],
					'image'     => $look['image'],
					'image_url' => is_string( $url ) ? $url : '',
				);
			}
		}
		return $cards;
	}

	/**
	 * Saves the rows' choices with the product, then drops those of the
	 * attributes it no longer has, and the cards' titles and images of the
	 * values it no longer has: with its last global attribute removed, no
	 * row, and so no choice, is posted.
	 *
	 * @param int $product_id The product.
	 */
	public function save_product( int $product_id ): void {
		$this->save_choices( $product_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_choices() checks the rows' nonce.
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product ) {
			SplitListing::forget_removed_attributes( $product );
			SplitCardLooks::alone(
				$product_id,
				static function () use ( $product ): void {
					SplitCardLooks::forget_removed_values( $product );
					SplitCardImageCopies::forget_removed_values( $product );
				}
			);
		}
	}

	/**
	 * Saves the rows' choices from WooCommerce's Save attributes request,
	 * which then answers with the rows drawn again.
	 */
	public function save_attributes_tab(): void {
		$action = (string) current_action();
		$nonce  = self::AJAX_SAVES[ substr( $action, strlen( self::AJAX_PREFIX ) ) ] ?? null;
		// save_choices() checks the user may edit the product.
		if ( null === $nonce || false === check_ajax_referer( $nonce, 'security', false ) ) {
			return;
		}
		if ( ! isset( $_POST['data'], $_POST['post_id'] ) || ! is_string( $_POST['data'] ) ) {
			return;
		}
		parse_str( wp_unslash( $_POST['data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_choices() keeps only known names and choices.
		// Slashed again, as a posted product form is, which save_choices() unslashes.
		$this->save_choices( absint( $_POST['post_id'] ), wp_slash( $data ) );
	}

	/**
	 * Stores the posted rows' choices and cards' titles and images on the
	 * product; an attribute no row names loses its choice, and a card no row
	 * drew keeps its title and image. Nothing is stored when the rows were
	 * not posted, or when they are what is stored.
	 *
	 * @param int                 $product_id The product.
	 * @param array<mixed, mixed> $posted    The posted form, slashed.
	 */
	public function save_choices( int $product_id, array $posted ): void {
		if ( ! self::posted_nonce_valid( $posted ) || ! is_array( $posted[ self::PRODUCT_FIELD ] ?? null ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}
		$names   = is_array( $posted['attribute_names'] ?? null ) ? $posted['attribute_names'] : array();
		$choices = array();
		foreach ( $posted[ self::PRODUCT_FIELD ] as $row => $choice ) {
			$name         = $names[ $row ] ?? null;
			$attribute_id = is_string( $name ) ? wc_attribute_taxonomy_id_by_name( sanitize_text_field( wp_unslash( $name ) ) ) : 0;
			if ( $attribute_id > 0 ) {
				$choices[ $attribute_id ] = $choice;
			}
		}
		$looks = is_array( $posted[ self::CARD_FIELD ] ?? null ) ? $posted[ self::CARD_FIELD ] : array();
		// Only the cards the rows drew are posted: a card the product is not listed by now keeps its own.
		SplitCardLooks::alone(
			$product_id,
			static function () use ( $product_id, $looks ): void {
				$before = SplitCardLooks::stored_on( $product_id );
				SplitCardLooks::change( $product_id, wp_unslash( $looks ) );
				$after = SplitCardLooks::stored_on( $product_id );
				// A card whose image the store manager changed no longer waits for the copy of another.
				$changed = array();
				foreach ( array_keys( $before + $after ) as $key ) {
					if ( ( $before[ $key ]['image'] ?? 0 ) !== ( $after[ $key ]['image'] ?? 0 ) ) {
						$changed[ $key ] = null;
					}
				}
				SplitCardImageCopies::change( $product_id, $changed );
			}
		);
		$config = ProductConfig::stored_on( $product );
		if ( SplitListing::choices_from( $choices ) === $config->split_listing ) {
			return;
		}
		try {
			$config->with_split_listing( $choices )->save_to( $product );
		} catch ( LogicException $e ) {
			// A newer plugin version's configuration is kept as it is.
			return;
		}
	}

	/**
	 * What each way of listing an attribute's values reads as.
	 *
	 * @return array<string, string> By mode, in order.
	 */
	private static function modes(): array {
		return array(
			/* translators: Option of the "Cards in the shop" menu of a product attribute: the shop lists a product once, whatever this attribute's value. */
			SplitListing::OFF      => __( 'One card for all values', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the "Cards in the shop" menu of a product attribute: the shop always lists each of the attribute's values as its own card. */
			SplitListing::ON       => __( 'A card per value', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the "Cards in the shop" menu of a product attribute: the shop lists each of the attribute's values as its own card only while a customer filters by the attribute. */
			SplitListing::FILTERED => __( 'A card per value while filtered by it', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * What each of a product's choices reads as in its row's menu.
	 *
	 * @param string $attribute_mode How the attribute lists its values by default.
	 * @return array<string, string> By choice, in order.
	 */
	private static function choices( string $attribute_mode ): array {
		$modes = self::modes();
		return array(
			/* translators: Option of the "Cards in the shop" menu on an attribute's row in a product's Attributes tab, following the attribute's own setting. %s: that setting, such as "A card per value". */
			SplitListing::AUTO     => sprintf( __( 'Auto: %s, as the attribute', 'pixonaut-for-woocommerce' ), $modes[ $attribute_mode ] ?? $modes[ SplitListing::OFF ] ),
			SplitListing::ON       => $modes[ SplitListing::ON ],
			SplitListing::FILTERED => $modes[ SplitListing::FILTERED ],
			SplitListing::OFF      => $modes[ SplitListing::OFF ],
		);
	}

	/**
	 * @param bool   $editing Whether the edit screen shows it; else the add form.
	 * @param string $mode    The attribute's setting.
	 */
	private function render_attribute_field( bool $editing, string $mode ): void {
		$this->templates->render(
			'admin/split-attribute-field.php',
			array(
				'editing'     => $editing,
				'field'       => self::ATTRIBUTE_FIELD,
				'mode'        => $mode,
				'options'     => self::modes(),
				'nonce_field' => wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false ),
			)
		);
	}

	/**
	 * Whether a posted form carries a valid nonce of these fields.
	 *
	 * @param array<mixed, mixed> $posted The posted form, slashed.
	 */
	private static function posted_nonce_valid( array $posted ): bool {
		$nonce = $posted[ self::NONCE_FIELD ] ?? null;
		return is_string( $nonce ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $nonce ) ), self::NONCE_ACTION );
	}

	/**
	 * The product whose Attributes tab is drawn: the product screen's, or
	 * the one WooCommerce's Save attributes request names.
	 */
	private static function product_on_screen(): ?WC_Product {
		global $product_object;
		if ( $product_object instanceof WC_Product ) {
			return $product_object;
		}
		$product_id = wp_doing_ajax() && isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked its nonce; only reads which product is drawn.
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
		return $product instanceof WC_Product ? $product : null;
	}
}
