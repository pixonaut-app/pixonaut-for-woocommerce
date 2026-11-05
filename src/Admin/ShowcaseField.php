<?php
/**
 * The product images field: which showcase sets the product's images show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Product;

/**
 * In the Pixonaut tab, a personalised product lists its first side's
 * template's showcase sets, as saved, to tick as its images, each drawn on
 * the blank's photo or flat (`ShowcaseImages`), each choice with a thumbnail
 * of its photo or of the template. The photo is offered only while the
 * blank has one for the side's placement; without, every set is flat and
 * there is nothing to choose. A chosen set the template no
 * longer has is listed as gone: its image shows the template's defaults
 * until it is unticked. When Pixonaut cannot list the sets, the choices are
 * kept as they are. With no set to list and none chosen, as for a template
 * without sets or an answer that does not list them, there is no field.
 */
final class ShowcaseField {

	/**
	 * The posted rows: `[n][use|id|name|kind]`.
	 */
	public const FIELD = PanelFields::PRODUCT_PREFIX . 'showcase_images';

	/**
	 * Posted while the rows were listed, so a form without them keeps the choices.
	 */
	public const LISTED_FIELD = self::FIELD . '_listed';

	/**
	 * @param Catalogue      $catalogue Lists the template's showcase sets.
	 * @param CatalogueCache $cache     Keeps the list for a few minutes.
	 * @param Templates      $templates Renders the field.
	 * @param Settings       $settings  Says whether pictures are product images.
	 * @param LinkedBlank    $blank     Reads the blank's photos.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
		private readonly Templates $templates,
		private readonly Settings $settings,
		private readonly LinkedBlank $blank,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
	}

	/**
	 * The field for a product; empty while it has no template to list, or
	 * neither the template nor the product has a set.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public function html( WC_Product $product ): string {
		$template = self::template( $product );
		if ( null === $template ) {
			return '';
		}
		$chosen  = ShowcaseImages::on( $product );
		$preview = '';
		try {
			$listed  = $this->listed( $template );
			$sets    = ShowcaseImages::sets( $listed['showcases'] ?? array() );
			$preview = is_string( $listed['preview_url'] ?? null ) ? $listed['preview_url'] : '';
		} catch ( ApiError $e ) {
			$sets = null;
		}
		if ( array() === $chosen && ( null === $sets || array() === $sets ) ) {
			return '';
		}
		$config = ProductConfig::stored_on( $product );
		$photo  = $this->photo( $product, $config );
		$kinds  = null === $photo || '' !== $photo
			? array(
				ShowcaseImages::MOCKUP  => array(
					/* translators: Option of how a showcase product image is drawn: the design on the blank product's photo. Admin product page. */
					'name'        => __( 'On the product photo', 'pixonaut-for-woocommerce' ),
					'description' => __( 'The set drawn into the print area of the blank’s product photo, as customers see the product.', 'pixonaut-for-woocommerce' ),
					'thumbnail'   => $photo ?? '',
				),
				ShowcaseImages::PREVIEW => array(
					/* translators: Option of how a showcase product image is drawn: the design alone, flat. Admin product page. */
					'name'        => __( 'Flat design', 'pixonaut-for-woocommerce' ),
					'description' => __( 'The design alone, filled in with the set, without the product.', 'pixonaut-for-woocommerce' ),
					'thumbnail'   => $preview,
				),
			)
			: array();
		return $this->templates->html(
			'admin/product-showcase-images.php',
			array(
				'field'        => self::FIELD,
				'listed_field' => self::LISTED_FIELD,
				'rows'         => null === $sets ? array() : self::rows( $sets, $chosen, ProductPictures::stored( $product )['gone'], array() !== $kinds ),
				'listed'       => null !== $sets,
				'pictures_on'  => ProductPictures::enabled( $this->settings ),
				'max_images'   => ShowcaseImages::MAX_IMAGES,
				'kinds'        => $kinds,
				'has_blank'    => null !== $config->blank_id,
			)
		);
	}

	/**
	 * The template's showcase sets and preview, as Pixonaut lists them,
	 * kept for a while.
	 *
	 * @param string $template The template version.
	 * @return array<mixed> As `Catalogue::template_showcases()`.
	 *
	 * @throws ApiError When Pixonaut could not be asked.
	 */
	public function listed( string $template ): array {
		return $this->cache->remember( 'template-showcases', array( 'id' => $template ), fn (): array => $this->catalogue->template_showcases( $template ) );
	}

	/**
	 * The thumbnail of the photo the first side's sets are drawn on, as
	 * `PictureSources` picks it: the first view of its placement the product
	 * is shown in with a photo of the variant it sells, or, selling none or
	 * standing for its variations, with any photo. Empty when the product
	 * has no blank, or no such photo; null when Pixonaut cannot say.
	 *
	 * @param WC_Product    $product A simple or variable product.
	 * @param ProductConfig $config  Its configuration as saved.
	 */
	private function photo( WC_Product $product, ProductConfig $config ): ?string {
		$placement_id = $config->sides[0]->placement_id ?? null;
		if ( null === $config->blank_id || null === $placement_id ) {
			return '';
		}
		$variant = $product->is_type( 'variable' ) ? null : ProductVariant::sold_on( $product, $config );
		if ( null !== $variant ) {
			$view = $variant->first_view( $placement_id, $config );
			return null === $view ? '' : $this->blank->view_photo( $config->blank_id, $placement_id, $view, $variant->ref );
		}
		$placements = $this->blank->views( $config->blank_id );
		if ( null === $placements ) {
			return null;
		}
		foreach ( $placements as $placement ) {
			if ( $placement['id'] !== $placement_id ) {
				continue;
			}
			foreach ( $placement['views'] as $view ) {
				if ( '' !== $view['thumbnail'] && ( array() === $config->views || in_array( $view['id'], $config->views, true ) ) ) {
					return $view['thumbnail'];
				}
			}
		}
		return '';
	}

	/**
	 * Saves the ticked sets, in the order they were listed.
	 *
	 * @param int $product_id The product.
	 */
	public function save( int $product_id ): void {
		if ( ! isset( $_POST[ ProductPanel::NONCE_FIELD ], $_POST[ self::LISTED_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ ProductPanel::NONCE_FIELD ] ) ), ProductPanel::NONCE_ACTION ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return;
		}
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each row's parts are sanitised below.
		$images = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['use'] ) ) {
				continue;
			}
			$images[] = array(
				'id'   => sanitize_text_field( (string) ( $row['id'] ?? '' ) ),
				'name' => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'kind' => sanitize_key( (string) ( $row['kind'] ?? '' ) ),
			);
		}
		ShowcaseImages::save( $product, $images );
	}

	/**
	 * Each set the field lists: the template's, then the chosen ones it no
	 * longer has, or Pixonaut drew without them.
	 *
	 * @param list<array{id: string, name: string, kind: string}> $sets     The template's sets.
	 * @param list<array{id: string, name: string, kind: string}> $chosen   The product's choices.
	 * @param string[]                                            $gone     Sets Pixonaut answered it does not have.
	 * @param bool                                                $on_photo Whether a set can be drawn on the photo; when not, every set is flat.
	 * @phpstan-param list<string> $gone
	 *
	 * @return list<array{id: string, name: string, kind: string, chosen: bool, gone: bool}>
	 */
	private static function rows( array $sets, array $chosen, array $gone, bool $on_photo ): array {
		$by_id = array_column( $chosen, null, 'id' );
		$kind  = $on_photo ? null : ShowcaseImages::PREVIEW;
		$rows  = array();
		foreach ( $sets as $set ) {
			$choice = $by_id[ $set['id'] ] ?? null;
			unset( $by_id[ $set['id'] ] );
			$rows[] = array(
				'id'     => $set['id'],
				'name'   => $set['name'],
				'kind'   => $kind ?? $choice['kind'] ?? ShowcaseImages::MOCKUP,
				'chosen' => null !== $choice,
				'gone'   => null !== $choice && in_array( $set['id'], $gone, true ),
			);
		}
		foreach ( $by_id as $kept ) {
			$rows[] = array(
				'kind'   => $kind ?? $kept['kind'],
				'chosen' => true,
				'gone'   => true,
			) + $kept;
		}
		return $rows;
	}

	/**
	 * The saved template whose sets are listed: the first side's; null for none.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public static function template( WC_Product $product ): ?string {
		$config = ProductConfig::stored_on( $product );
		$side   = $config->sides[0] ?? null;
		if ( ! $config->personalise || null === $side || '' === $side->template_base_id || null === $side->template_version ) {
			return null;
		}
		return (string) new TemplateId( $side->template_base_id, $side->template_version );
	}
}
