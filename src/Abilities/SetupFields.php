<?php
/**
 * A product's setup as the abilities take it, turned into the product screen's fields.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use WP_Error;

/**
 * Builds what the product screen posts (`PanelFields` keys) from an
 * ability's input, starting from the configuration the product or
 * variation has: a field left out keeps what it has. The fields then go
 * through `ProductPanel::configure()`, so they are checked, filtered and
 * saved as the screen's are. A side's template is a version (`mini-tee@2`),
 * or a template's id alone for its live version now; either way the side
 * follows the template's latest version from then on, unless it is pinned
 * to that version.
 */
final class SetupFields {

	/**
	 * The ability fields only a product takes: a variation follows its product.
	 */
	public const PRODUCT_KEYS = array( 'option_groups', 'group_numbers' );

	/**
	 * The ability fields a configuration's own setup is written from; a
	 * variation that is given any of them gets a setup of its own.
	 */
	public const SETUP_KEYS = array( 'personalised', 'blank_id', 'sides', 'layout', 'form_layout', 'approval_mode', 'views', 'initial_view', 'printshop_id', 'canvas_ratio' );

	/**
	 * The live versions once read, for the rest of the request.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $live = null;

	/**
	 * @param Closure $live_versions Every live template's latest version, by base id; throws `ApiException` when Pixonaut cannot be asked.
	 * @phpstan-param Closure(): array<string, int> $live_versions
	 */
	public function __construct( private readonly Closure $live_versions ) {
	}

	/**
	 * The fields to save: the input's, else what $current has.
	 *
	 * @param array<mixed>  $input   The ability's input.
	 * @param ProductConfig $current The configuration the fields start from.
	 * @return array<string, mixed>|WP_Error By `PanelFields` key, as `ProductPanel::configure()` takes them.
	 */
	public function posted( array $input, ProductConfig $current ): array|WP_Error {
		$sides = is_array( $input['sides'] ?? null ) ? $input['sides'] : self::sides_of( $current );
		$rows  = $this->rows( $sides, $current );
		if ( $rows instanceof WP_Error ) {
			return $rows;
		}
		$blank  = is_string( $input['blank_id'] ?? null ) ? sanitize_text_field( $input['blank_id'] ) : $current->blank_id ?? '';
		$posted = array(
			PanelFields::BLANK         => $blank,
			PanelFields::SIDES         => $rows,
			PanelFields::LAYOUT        => self::text( $input, 'layout', $current->layout ),
			PanelFields::FORM_LAYOUT   => self::text( $input, 'form_layout', $current->form_layout ),
			PanelFields::APPROVAL_MODE => self::text( $input, 'approval_mode', $current->approval_mode ),
			PanelFields::CANVAS_RATIO  => self::text( $input, 'canvas_ratio', CanvasRatio::posted( $current->draw_at_template_ratio ) ),
			PanelFields::OPTION_GROUPS => self::text( $input, 'option_groups', $current->option_groups ),
			PanelFields::GROUP_NUMBERS => self::text( $input, 'group_numbers', $current->group_numbers ),
		);
		// Sides given are meant to be used: left out, personalisation is then on.
		if ( (bool) ( $input['personalised'] ?? ( is_array( $input['sides'] ?? null ) || $current->personalise ) ) ) {
			$posted[ PanelFields::PERSONALISE ] = 'yes';
		}
		if ( is_array( $input['views'] ?? null ) || is_string( $input['initial_view'] ?? null ) ) {
			// Read for the blank they are posted with, as the screen's are.
			$posted[ PanelFields::VIEWS ] = array(
				ProductPanel::VIEWS_BLANK_KEY   => $blank,
				ProductPanel::VIEWS_TICKED_KEY  => is_array( $input['views'] ?? null ) ? array_values( array_filter( $input['views'], 'is_string' ) ) : $current->views,
				ProductPanel::VIEWS_INITIAL_KEY => self::text( $input, 'initial_view', $current->initial_view ),
			);
		}
		if ( is_string( $input['printshop_id'] ?? null ) ) {
			$posted[ PanelFields::PRINTSHOP ] = $input['printshop_id'];
		}
		return $posted;
	}

	/**
	 * A configuration's sides as the abilities take and give them.
	 *
	 * @param ProductConfig $config The configuration.
	 * @return list<array<string, mixed>>
	 */
	public static function sides_of( ProductConfig $config ): array {
		return array_map( array( self::class, 'side_of' ), $config->sides );
	}

	/**
	 * One side as the abilities take and give it. A side whose sizes
	 * Pixonaut could not check gives its menu as entered.
	 *
	 * @param Side $side The side.
	 * @return array<string, mixed>
	 */
	public static function side_of( Side $side ): array {
		$menu = $side->pending_sizes ?? $side->print_sizes;
		return array(
			'key'             => $side->key,
			'label'           => $side->label,
			'mode'            => $side->mode,
			'template_id'     => '' === $side->template_base_id ? null : ( null === $side->template_version ? $side->template_base_id : (string) new TemplateId( $side->template_base_id, $side->template_version ) ),
			'template_pinned' => $side->pinned,
			'placement_id'    => $side->placement_id,
			'print_sizes'     => array_map( array( self::class, 'size_of' ), $menu ),
			'start_size'      => $side->start_size < count( $menu ) ? $side->start_size : 0,
			'form_groups'     => $side->form_groups?->to_array(),
			'canvas_ratio'    => CanvasRatio::posted( $side->draw_at_template_ratio ),
		);
	}

	/**
	 * One print size as the abilities take and give it.
	 *
	 * @param PrintSize|null $size The size; null for the placement's default not yet checked.
	 * @return array<string, mixed>
	 */
	public static function size_of( ?PrintSize $size ): array {
		if ( null === $size ) {
			return array( 'placement_default' => true );
		}
		return array(
			'width_mm'          => $size->width_mm,
			'height_mm'         => $size->height_mm,
			'label'             => '' !== $size->label ? $size->label : PrintSize::label_for( $size->width_mm, $size->height_mm ),
			'placement_default' => $size->placement_default,
		);
	}

	/**
	 * The schema of one side, as the abilities take it.
	 *
	 * @return array<string, mixed>
	 */
	public static function side_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'             => array(
					'type'        => 'string',
					'description' => __( 'The side\'s key, unique on the product, e.g. `front`; left out for one made from the placement.', 'pixonaut-for-woocommerce' ),
					'maxLength'   => PostedSides::KEY_MAX_LENGTH,
				),
				'label'           => array(
					'type'        => 'string',
					'description' => __( 'The name customers see, e.g. "Front"; left out for the placement\'s or the template\'s name.', 'pixonaut-for-woocommerce' ),
					'maxLength'   => PostedSides::LABEL_MAX_LENGTH,
				),
				'template_id'     => array(
					'type'        => 'string',
					'description' => __( 'The template, as the template list gives it: a version such as `mini-tee@2`, or the id alone (`mini-tee`) for its live version. The side follows the template\'s latest published version from then on, unless `template_pinned` is true.', 'pixonaut-for-woocommerce' ),
				),
				'template_pinned' => array(
					'type'        => 'boolean',
					'description' => __( 'True to keep the side on the version `template_id` gives when a new version is published; false or left out to follow the latest.', 'pixonaut-for-woocommerce' ),
					'default'     => false,
				),
				'placement_id'    => array(
					'type'        => 'string',
					'description' => __( 'Where on the blank it is printed; needed with a blank, ignored without one.', 'pixonaut-for-woocommerce' ),
				),
				'mode'            => array(
					'type'        => 'string',
					'description' => __( '`personalised` when the customer fills in the template, `standing` when the template is printed as its author left it, from one design for every sale.', 'pixonaut-for-woocommerce' ),
					'enum'        => Side::MODES,
					'default'     => Side::PERSONALISED,
				),
				'print_sizes'     => array(
					'type'        => 'array',
					'description' => __( 'The sizes customers choose between, in millimetres, in order; empty or left out for the placement\'s default size. An entry with `placement_default` true is that default size. Without a blank, each must match the template\'s aspect ratio.', 'pixonaut-for-woocommerce' ),
					'maxItems'    => PostedSides::MAX_SIZES,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'width_mm'          => array(
								'type'             => 'number',
								'exclusiveMinimum' => 0,
							),
							'height_mm'         => array(
								'type'             => 'number',
								'exclusiveMinimum' => 0,
							),
							'label'             => array(
								'type'        => 'string',
								'description' => __( 'The name customers see, e.g. "A3"; left out for the millimetres.', 'pixonaut-for-woocommerce' ),
							),
							'placement_default' => array(
								'type'        => 'boolean',
								'description' => __( 'The placement\'s default size; its width and height are then ignored.', 'pixonaut-for-woocommerce' ),
							),
						),
					),
				),
				'start_size'      => array(
					'type'        => 'integer',
					'description' => __( 'The size the designer starts on, by its place in `print_sizes` from 0. A standing side is printed at it.', 'pixonaut-for-woocommerce' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'form_groups'     => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'How the designer\'s form is arranged on the product page, as `list-products` gives it: `{groups: [{id, title, items}]}`, an item being `{field: "<layerId>:<prop>", label, help, placeholder, options}` or `{attribute: <attribute slug>}`. Null or left out for the template\'s own.', 'pixonaut-for-woocommerce' ),
				),
				'canvas_ratio'    => self::canvas_ratio_schema( __( 'empty or left out for the product\'s choice', 'pixonaut-for-woocommerce' ) ),
			),
			'required'             => array( 'template_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The schema of a canvas ratio choice, on a product or one side.
	 *
	 * @param string $follow What the empty choice follows, e.g. "empty for the template's own setting".
	 * @return array<string, mixed>
	 */
	public static function canvas_ratio_schema( string $follow ): array {
		return array(
			'type'        => 'string',
			/* translators: %s: what the empty choice follows, e.g. "empty for the template's own setting". */
			'description' => sprintf( __( 'The shape customers design on when a print size\'s proportions are not the template\'s: `template` keeps the template\'s shape at every size, and the print is cropped to the size as the template fits it; `print_size` changes shape with each size; %s.', 'pixonaut-for-woocommerce' ), $follow ),
			'enum'        => CanvasRatio::CHOICES,
		);
	}

	/**
	 * The sides as the screen posts them, each template given a version.
	 *
	 * @param array<mixed>  $sides   The sides as the abilities take them.
	 * @param ProductConfig $current What the product has now: a side keeps its template's name.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function rows( array $sides, ProductConfig $current ): array|WP_Error {
		$rows = array();
		foreach ( array_values( $sides ) as $index => $side ) {
			if ( ! is_array( $side ) ) {
				continue;
			}
			$template = is_string( $side['template_id'] ?? null ) ? trim( $side['template_id'] ) : '';
			if ( '' !== $template && null === TemplateId::parse( $template ) ) {
				try {
					$this->live ??= ( $this->live_versions )();
				} catch ( ApiException $e ) {
					return new WP_Error( 'pixonaut_wc_ability_pixonaut_unreachable', __( 'Pixonaut could not be asked for the template\'s live version. Give the version, as the template list gives it, or try again later.', 'pixonaut-for-woocommerce' ) );
				}
				if ( ! isset( $this->live[ $template ] ) ) {
					return new WP_Error(
						'pixonaut_wc_ability_unknown_template',
						/* translators: %s: a template's id, e.g. "mini-tee". */
						sprintf( __( 'No live template has the id "%s".', 'pixonaut-for-woocommerce' ), $template )
					);
				}
				$template = (string) new TemplateId( $template, $this->live[ $template ] );
			}
			$rows[ $index ] = self::row( $side, $template, $current );
		}
		return $rows;
	}

	/**
	 * One side as the screen posts it.
	 *
	 * @param array<mixed>  $side     The side as the abilities take it.
	 * @param string        $template Its template version; empty for none.
	 * @param ProductConfig $current  What the product has now.
	 * @return array<string, mixed>
	 */
	private static function row( array $side, string $template, ProductConfig $current ): array {
		$sizes = array();
		foreach ( is_array( $side['print_sizes'] ?? null ) ? array_values( $side['print_sizes'] ) : array() as $size ) {
			$size    = is_array( $size ) ? $size : array();
			$sizes[] = true === ( $size['placement_default'] ?? false )
				? array( 'kind' => PostedSides::SIZE_PLACEMENT_DEFAULT )
				: array(
					'kind'   => PostedSides::SIZE_CUSTOM,
					'width'  => is_numeric( $size['width_mm'] ?? null ) ? (string) $size['width_mm'] : '',
					'height' => is_numeric( $size['height_mm'] ?? null ) ? (string) $size['height_mm'] : '',
					'label'  => is_string( $size['label'] ?? null ) ? $size['label'] : '',
				);
		}
		$key    = is_string( $side['key'] ?? null ) ? $side['key'] : '';
		$groups = $side['form_groups'] ?? null;
		$pinned = true === ( $side['template_pinned'] ?? false ) ? TemplateId::parse( $template ) : null;
		return array(
			'key'                     => $key,
			'label'                   => is_string( $side['label'] ?? null ) ? $side['label'] : '',
			'placement'               => is_string( $side['placement_id'] ?? null ) ? $side['placement_id'] : '',
			'template'                => $template,
			'template_name'           => self::template_name( $current, $key, $template ),
			'sizes'                   => array() === $sizes ? array( array( 'kind' => PostedSides::SIZE_PLACEMENT_DEFAULT ) ) : $sizes,
			'start_size'              => (string) Abilities::integer( $side, 'start_size', 0 ),
			'mode'                    => is_string( $side['mode'] ?? null ) ? $side['mode'] : Side::PERSONALISED,
			PostedSides::CANVAS_RATIO => is_string( $side['canvas_ratio'] ?? null ) ? $side['canvas_ratio'] : CanvasRatio::FOLLOW,
		) + ( null === $pinned ? array() : array(
			'pinned'  => PostedSides::PINNED,
			'version' => (string) $pinned->version,
		) ) + ( is_array( $groups ) ? array( ProductPanel::FORM_GROUPS_FIELD => (string) wp_json_encode( $groups ) ) : array() );
	}

	/**
	 * The name a side on the same template had, which Pixonaut's answer
	 * replaces when the template is checked.
	 *
	 * @param ProductConfig $current  What the product has now.
	 * @param string        $key      The side's key.
	 * @param string        $template Its template version.
	 */
	private static function template_name( ProductConfig $current, string $key, string $template ): string {
		$base = TemplateId::parse( $template )->base_id ?? null;
		foreach ( $current->sides as $side ) {
			if ( $side->key === $key && $side->template_base_id === $base ) {
				return $side->template_name ?? '';
			}
		}
		return '';
	}

	/**
	 * A text field of the input, else what the configuration has.
	 *
	 * @param array<mixed> $input    The ability's input.
	 * @param string       $key      The field.
	 * @param string|null  $fallback What the configuration has.
	 */
	private static function text( array $input, string $key, ?string $fallback ): string {
		return is_string( $input[ $key ] ?? null ) ? $input[ $key ] : $fallback ?? '';
	}
}
