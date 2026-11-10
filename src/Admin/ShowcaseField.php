<?php
/**
 * The product images field: which showcase sets the product's images show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PictureView;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
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
	 * The posted view and photo of the image drawn without a set.
	 */
	public const VIEW_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::PICTURE_VIEW;

	/**
	 * Posted while the views were listed, so a form without them keeps the choice.
	 */
	public const VIEW_LISTED_FIELD = PanelFields::PRODUCT_PREFIX . PanelFields::PICTURE_VIEW_LISTED;

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
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_view' ) );
	}

	/**
	 * The field for a product; empty while it has no template to list, or
	 * neither a set to list nor a photo to choose.
	 *
	 * @param WC_Product $product A simple or variable product.
	 */
	public function html( WC_Product $product ): string {
		$template = self::template( $product );
		if ( null === $template ) {
			return '';
		}
		$chosen   = ShowcaseImages::on( $product );
		$preview  = '';
		$previews = array();
		try {
			$listed   = $this->listed( $template );
			$sets     = ShowcaseImages::sets( $listed['showcases'] ?? array() );
			$preview  = is_string( $listed['preview_url'] ?? null ) ? $listed['preview_url'] : '';
			$previews = self::previews( $listed['showcases'] ?? array() );
		} catch ( ApiException $e ) {
			$sets = null;
		}
		$config = ProductConfig::stored_on( $product );
		$photo  = $this->photo( $product, $config );
		// Every photo of the placement can be chosen, whether or not the product page starts on one.
		$views    = $this->views( $config );
		$own_view = PictureView::on( $product );
		$viewed   = null !== $own_view || array() !== array_filter( array_column( $chosen, 'view' ) );
		// A choice made before keeps its controls, so it can be seen and changed.
		$kinds = null === $photo || '' !== $photo || null === $views || array() !== $views || $viewed
			? array(
				ShowcaseImages::MOCKUP  => array(
					/* translators: Short name of a choice of how a product image is drawn: the design on the blank product's photo, the one the product page starts on. Admin product page. */
					'name'        => __( 'Main photo', 'pixonaut-for-woocommerce' ),
					'description' => __( 'Drawn into the print area of the blank’s photo the product page starts on.', 'pixonaut-for-woocommerce' ),
					'thumbnail'   => $photo ?? '',
				),
				ShowcaseImages::PREVIEW => array(
					/* translators: Short name of a choice of how a product image is drawn: the design alone, without the product. Admin product page. */
					'name'        => __( 'Design', 'pixonaut-for-woocommerce' ),
					'description' => __( 'The design alone, without the product.', 'pixonaut-for-woocommerce' ),
					'thumbnail'   => $preview,
					'flat'        => true,
				),
			)
			: array();
		$tiles = array() === $kinds ? array() : array_merge( array( $kinds[ ShowcaseImages::MOCKUP ] + array( 'value' => ShowcaseImages::MOCKUP ) ), self::photo_choices( $views ?? array() ), array( $kinds[ ShowcaseImages::PREVIEW ] + array( 'value' => ShowcaseImages::PREVIEW ) ) );
		$rows  = array();
		foreach ( null === $sets ? array() : self::rows( $sets, $chosen, ProductPictures::stored( $product )['gone'], array() !== $kinds, $views ) as $index => $row ) {
			// A set the template no longer has is drawn with its defaults, as the template's own preview shows.
			$set_preview = $row['gone'] ? $preview : $previews[ $row['id'] ] ?? '';
			// The set's own preview is what its picture looks like drawn as the design alone.
			$set_tiles = array_map( static fn ( array $tile ): array => ( $tile['flat'] ?? false ) && '' !== $set_preview ? array_replace( $tile, array( 'thumbnail' => $set_preview ) ) : $tile, $tiles );
			$rows[]    = $row + array(
				'preview'      => $set_preview,
				'photo'        => self::drawn_on( PictureView::from_field( $row['value'] ), $views, $photo ?? '' ),
				'flat'         => ShowcaseImages::PREVIEW === $row['kind'],
				'choices_html' => array() === $tiles ? '' : $this->choices_html(
					self::FIELD . '[' . $index . '][kind]',
					$row,
					$set_tiles,
					/* translators: %s: name of a showcase set of the template. Admin product page. */
					sprintf( __( 'How %s is drawn', 'pixonaut-for-woocommerce' ), $row['name'] ),
					null !== $views
				),
			);
		}
		$own_choice = $own_view ?? array( 'kind' => PictureView::MOCKUP );
		$own_choice = array() === $kinds ? array( 'kind' => PictureView::PREVIEW ) : $own_choice;
		$own_view   = self::choice( $own_choice, $views );
		return $this->templates->html(
			'admin/product-showcase-images.php',
			array(
				'field'        => self::FIELD,
				'listed_field' => self::LISTED_FIELD,
				'rows'         => $rows,
				'listed'       => null !== $sets,
				'pictures_on'  => ProductPictures::enabled( $this->settings ),
				'settings_url' => SettingsPage::url( SettingsPage::STOREFRONT ),
				'max_images'   => ShowcaseImages::MAX_IMAGES,
				'on_photo'     => array() !== $kinds,
				'has_blank'    => null !== $config->blank_id,
				'view_listed'  => self::VIEW_LISTED_FIELD,
				'own_preview'  => $preview,
				'own_photo'    => self::drawn_on( $own_choice, $views, $photo ?? '' ),
				'own_flat'     => PictureView::PREVIEW === $own_choice['kind'],
				'saved_sets'   => count( $chosen ),
				'view_html'    => array() === $tiles ? '' : $this->choices_html(
					self::VIEW_FIELD,
					$own_view,
					$tiles,
					/* translators: Accessible name of the choices of how the product image is drawn while no showcase set is ticked. Admin product page. */
					__( 'How the product image is drawn without a showcase set', 'pixonaut-for-woocommerce' ),
					null !== $views
				),
			)
		);
	}

	/**
	 * The choices of what a picture is drawn on, the one shown checked. A
	 * choice the tiles do not offer is added: named as no longer on the
	 * blank when Pixonaut listed its views, as chosen before when it could
	 * not.
	 *
	 * @param string                                                                                        $name   The radio buttons' posted name.
	 * @param array{value: string, gone?: bool, view_gone?: bool, view_label?: string}                      $shown  The choice as `choice()` or `rows()` shows it.
	 * @param list<array{value: string, name: string, description: string, thumbnail: string, flat?: bool}> $tiles The choices offered.
	 * @param string                                                                                        $label  What the group chooses, for screen readers.
	 * @param bool                                                                                          $listed Whether Pixonaut listed the blank's views.
	 */
	private function choices_html( string $name, array $shown, array $tiles, string $label, bool $listed ): string {
		$gone = true === ( $shown['view_gone'] ?? $shown['gone'] ?? false );
		if ( ! in_array( $shown['value'], array_column( $tiles, 'value' ), true ) ) {
			$tiles[] = array(
				'value'       => $shown['value'],
				'name'        => self::missing_name( $shown, $gone, $listed ),
				'description' => '',
				'thumbnail'   => '',
			);
		}
		return $this->templates->html(
			'admin/picture-view-choices.php',
			array(
				'name'    => $name,
				'value'   => $shown['value'],
				'label'   => $label,
				'choices' => $tiles,
				'note'    => $gone ? __( 'The blank no longer has this view or photo, so the image is drawn on the photo the product page starts on until another is chosen.', 'pixonaut-for-woocommerce' ) : '',
			)
		);
	}

	/**
	 * The thumbnail of the photo a picture is drawn on, as `PictureSources`
	 * picks it: the photo chosen, the first photo of a view chosen without
	 * one, else the photo the product page starts on. Empty for the design
	 * alone, or when the photo is not known.
	 *
	 * @param array{kind: string, view?: string, photo?: string}                                                                      $choice The choice.
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null $views  The placement's views; null when Pixonaut cannot say.
	 * @param string                                                                                                                  $main   The thumbnail of the photo the product page starts on; empty for none.
	 */
	private static function drawn_on( array $choice, ?array $views, string $main ): string {
		if ( PictureView::PREVIEW === $choice['kind'] ) {
			return '';
		}
		$chosen = $choice['view'] ?? null;
		foreach ( null === $chosen ? array() : $views ?? array() as $view ) {
			if ( $view['id'] !== $chosen ) {
				continue;
			}
			foreach ( $view['photos'] as $photo ) {
				if ( ! isset( $choice['photo'] ) || $photo['id'] === $choice['photo'] ) {
					return $photo['thumbnail'];
				}
			}
		}
		return $main;
	}

	/**
	 * Each listed set's preview, by the set's id.
	 *
	 * @param mixed $showcases The sets as `Catalogue::template_showcases()` lists them.
	 * @return array<string, string>
	 */
	private static function previews( mixed $showcases ): array {
		$previews = array();
		foreach ( is_array( $showcases ) ? $showcases : array() as $showcase ) {
			if ( is_array( $showcase ) && is_string( $showcase['id'] ?? null ) && is_string( $showcase['preview_url'] ?? null ) ) {
				$previews[ $showcase['id'] ] = $showcase['preview_url'];
			}
		}
		return $previews;
	}

	/**
	 * The name of a choice the tiles do not offer: a view chosen without a
	 * photo, one the blank no longer has, or one Pixonaut could not be
	 * asked about.
	 *
	 * @param array{value: string, view_label?: string} $shown  The choice as `choice()` or `rows()` shows it.
	 * @param bool                                      $gone   Whether the blank no longer has it.
	 * @param bool                                      $listed Whether Pixonaut listed the blank's views.
	 */
	private static function missing_name( array $shown, bool $gone, bool $listed ): string {
		$view = $shown['view_label'] ?? '';
		if ( ! $gone && '' !== $view ) {
			/* translators: %s: a view of the blank product, e.g. "Front". A choice of what a product image is drawn on: that view, on its photo of the variant sold. Admin product page. */
			return sprintf( __( '%s, on the photo of the variant sold', 'pixonaut-for-woocommerce' ), $view );
		}
		return $listed || $gone
			/* translators: A choice of what a product image is drawn on: a view or photo the blank product no longer has. Admin product page. */
			? __( 'No longer on the blank', 'pixonaut-for-woocommerce' )
			/* translators: A choice of what a product image is drawn on, made before; Pixonaut could not be asked which it is. Admin product page. */
			: __( 'The photo chosen before', 'pixonaut-for-woocommerce' );
	}

	/**
	 * The variation's field choosing the view and photo of its image; empty
	 * when it is not personalised on a blank with photos for its side.
	 *
	 * @param WC_Product  $variation A variation.
	 * @param PanelFields $fields    The variation's field names.
	 */
	public function variation_html( WC_Product $variation, PanelFields $fields ): string {
		$config = ProductConfig::config_for( $variation );
		if ( ! $config->personalise || null === $config->blank_id ) {
			return '';
		}
		$views = $this->views( $config );
		$own   = PictureView::on( $variation );
		if ( null === $own && ( null === $views || array() === $views ) ) {
			return '';
		}
		$tiles = array_merge(
			array(
				array(
					'value'       => PictureView::MOCKUP,
					/* translators: A choice of what a variation's image is drawn on: whatever its product's image is drawn on. Admin product page, variation options. */
					'name'        => __( 'As the product image', 'pixonaut-for-woocommerce' ),
					'description' => __( 'The view and photo the product image is drawn on, in this variation’s colour where the view has it.', 'pixonaut-for-woocommerce' ),
					'thumbnail'   => '',
				),
			),
			self::photo_choices( $views ?? array() )
		);
		return $this->templates->html(
			'admin/variation-picture-view.php',
			array(
				'listed_name'  => $fields->name( PanelFields::PICTURE_VIEW_LISTED ),
				'choices_html' => $this->choices_html(
					$fields->name( PanelFields::PICTURE_VIEW ),
					self::choice( $own ?? array( 'kind' => PictureView::MOCKUP ), $views ),
					$tiles,
					/* translators: Accessible name of the choices of what a variation's image is drawn on. Admin product page, variation options. */
					__( 'What the variation image is drawn on', 'pixonaut-for-woocommerce' ),
					null !== $views
				),
			)
		);
	}

	/**
	 * Saves a variation's choice of view and photo, as its field posted it.
	 *
	 * @param WC_Product $variation A variation.
	 * @param mixed      $posted    The posted value, unslashed; null when the field was not listed.
	 */
	public static function save_variation( WC_Product $variation, mixed $posted ): void {
		if ( ! is_string( $posted ) ) {
			return;
		}
		PictureView::save( $variation, PictureView::from_field( $posted ) );
	}

	/**
	 * The views and photos of the side's placement a picture can be drawn
	 * on: empty without a blank or placement, null when Pixonaut cannot say.
	 *
	 * @param ProductConfig $config A configuration.
	 *
	 * @return list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null
	 */
	private function views( ProductConfig $config ): ?array {
		$placement_id = $config->sides[0]->placement_id ?? null;
		return null === $config->blank_id || null === $placement_id ? array() : $this->blank->placement_photos( $config->blank_id, $placement_id );
	}

	/**
	 * Each photo a picture can be drawn on, as a choice: its posted value,
	 * its view's label (numbered when the view has several) and thumbnail.
	 *
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}> $views The placement's views.
	 *
	 * @return list<array{value: string, name: string, description: string, thumbnail: string}>
	 */
	private static function photo_choices( array $views ): array {
		$choices = array();
		foreach ( $views as $view ) {
			$several = count( $view['photos'] ) > 1;
			foreach ( $view['photos'] as $index => $photo ) {
				$choices[] = array(
					'value'       => PictureView::field_value(
						array(
							'kind'  => PictureView::MOCKUP,
							'view'  => $view['id'],
							'photo' => $photo['id'],
						)
					),
					'name'        => $several
						/* translators: 1: a view of the blank product, e.g. "Front"; 2: which of its photos, e.g. 2. Admin product page. */
						? sprintf( __( '%1$s, photo %2$d', 'pixonaut-for-woocommerce' ), $view['label'], $index + 1 )
						: $view['label'],
					'description' => '',
					'thumbnail'   => $photo['thumbnail'],
				);
			}
		}
		return $choices;
	}

	/**
	 * A choice as a field shows it: the value checked, and whether it names
	 * a view or photo the blank no longer has.
	 *
	 * @param array{kind: string, view?: string, photo?: string}                                                                      $choice The choice.
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null $views  The placement's views; null when Pixonaut cannot say.
	 *
	 * @return array{value: string, gone: bool, view_label: string} `view_label` names the view of a choice naming no photo; empty otherwise.
	 */
	private static function choice( array $choice, ?array $views ): array {
		$label  = '';
		$chosen = isset( $choice['photo'] ) ? null : $choice['view'] ?? null;
		foreach ( null === $chosen ? array() : $views ?? array() as $view ) {
			$label = $chosen === $view['id'] ? $view['label'] : $label;
		}
		return array(
			'value'      => PictureView::field_value( $choice ),
			'gone'       => isset( $choice['view'] ) && null !== $views && null === PictureView::resolve( $views, $choice, null, true ),
			'view_label' => $label,
		);
	}

	/**
	 * The template's showcase sets and preview, as Pixonaut lists them,
	 * kept for a while.
	 *
	 * @param string $template The template version.
	 * @return array<mixed> As `Catalogue::template_showcases()`.
	 *
	 * @throws ApiException When Pixonaut could not be asked.
	 */
	public function listed( string $template ): array {
		return $this->cache->remember( 'template-showcases', array( 'id' => $template ), fn (): array => $this->catalogue->template_showcases( $template ) );
	}

	/**
	 * The sets a product of the template can show, by id and name: those
	 * of the template version the product is linked to.
	 *
	 * @param string $template The template version.
	 * @return list<array{id: string, name: string}>
	 *
	 * @throws ApiException When Pixonaut could not be asked.
	 */
	public function offered( string $template ): array {
		return array_map(
			static fn ( array $set ): array => array(
				'id'   => $set['id'],
				'name' => $set['name'],
			),
			ShowcaseImages::sets( $this->listed( $template )['showcases'] ?? array() )
		);
	}

	/**
	 * The sets a product can show, by id and name: none without a template,
	 * null when Pixonaut cannot be asked.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return list<array{id: string, name: string}>|null
	 */
	public function offered_on( WC_Product $product ): ?array {
		$template = self::template( $product );
		if ( null === $template ) {
			return array();
		}
		try {
			return $this->offered( $template );
		} catch ( ApiException $e ) {
			return null;
		}
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
			) + PictureView::from_field( (string) ( $row['kind'] ?? '' ) );
		}
		ShowcaseImages::save( $product, $images );
	}

	/**
	 * Saves the view and photo of the image drawn without a set.
	 *
	 * @param int $product_id The product.
	 */
	public function save_view( int $product_id ): void {
		if ( ! isset( $_POST[ ProductPanel::NONCE_FIELD ], $_POST[ self::VIEW_LISTED_FIELD ], $_POST[ self::VIEW_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ ProductPanel::NONCE_FIELD ] ) ), ProductPanel::NONCE_ACTION ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PictureView::from_field() sanitises each part.
		PictureView::save( $product, PictureView::from_field( is_string( $_POST[ self::VIEW_FIELD ] ) ? wp_unslash( $_POST[ self::VIEW_FIELD ] ) : '' ) );
	}

	/**
	 * Each set the field lists: the template's, then the chosen ones it no
	 * longer has, or Pixonaut drew without them; each with the value of
	 * how it is drawn, and whether that names a view or photo the blank no
	 * longer has.
	 *
	 * @param list<array{id: string, name: string, kind: string, view?: string, photo?: string}>                                      $sets     The template's sets.
	 * @param list<array{id: string, name: string, kind: string, view?: string, photo?: string}>                                      $chosen   The product's choices.
	 * @param string[]                                                                                                                $gone     Sets Pixonaut answered it does not have.
	 * @param bool                                                                                                                    $on_photo Whether a set can be drawn on the photo; when not, every set is flat.
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null $views    The placement's views; null when Pixonaut cannot say.
	 * @phpstan-param list<string> $gone
	 *
	 * @return list<array{id: string, name: string, kind: string, value: string, chosen: bool, gone: bool, view_gone: bool, view_label: string}>
	 */
	private static function rows( array $sets, array $chosen, array $gone, bool $on_photo, ?array $views ): array {
		$by_id = array_column( $chosen, null, 'id' );
		$rows  = array();
		foreach ( $sets as $set ) {
			$choice = $by_id[ $set['id'] ] ?? null;
			unset( $by_id[ $set['id'] ] );
			$rows[] = self::row( $set, $choice ?? array( 'kind' => ShowcaseImages::MOCKUP ), $on_photo, $views ) + array(
				'chosen' => null !== $choice,
				'gone'   => null !== $choice && in_array( $set['id'], $gone, true ),
			);
		}
		foreach ( $by_id as $kept ) {
			$rows[] = self::row( $kept, $kept, $on_photo, $views ) + array(
				'chosen' => true,
				'gone'   => true,
			);
		}
		return $rows;
	}

	/**
	 * A set's row, drawn as chosen.
	 *
	 * @param array{id: string, name: string}                                                                                         $set      The set.
	 * @param array{kind: string, view?: string, photo?: string}                                                                      $choice   How it is drawn.
	 * @param bool                                                                                                                    $on_photo Whether it can be drawn on the photo; when not, it is flat.
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>|null $views    The placement's views; null when Pixonaut cannot say.
	 *
	 * @return array{id: string, name: string, kind: string, value: string, view_gone: bool, view_label: string}
	 */
	private static function row( array $set, array $choice, bool $on_photo, ?array $views ): array {
		$choice = $on_photo ? $choice : array( 'kind' => ShowcaseImages::PREVIEW );
		$shown  = self::choice( $choice, $views );
		return array(
			'id'         => $set['id'],
			'name'       => $set['name'],
			'kind'       => $choice['kind'],
			'value'      => $shown['value'],
			'view_gone'  => $shown['gone'],
			'view_label' => $shown['view_label'],
		);
	}

	/**
	 * The preview of a configuration's first side's template, the design
	 * alone as a product shown in it looks; empty without a template, or
	 * when Pixonaut cannot be asked.
	 *
	 * @param ProductConfig $config A configuration.
	 */
	public function preview( ProductConfig $config ): string {
		$side = $config->sides[0] ?? null;
		if ( null === $side || '' === $side->template_base_id || null === $side->template_version ) {
			return '';
		}
		try {
			$preview = $this->listed( (string) new TemplateId( $side->template_base_id, $side->template_version ) )['preview_url'] ?? null;
		} catch ( ApiException $e ) {
			return '';
		}
		return is_string( $preview ) ? $preview : '';
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
