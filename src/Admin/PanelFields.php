<?php
/**
 * The names the Pixonaut fields are posted under.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

/**
 * Where the product screen posts one configuration's fields: at the top of
 * the form for the product, under `pixonaut_variation[<loop>]` for each
 * variation, so several variations' fields share one form. The ids carry the
 * same distinction, so each variation's labels point at its own fields.
 */
final class PanelFields {

	/**
	 * The posted array each variation's fields are under, by the variation's
	 * place in WooCommerce's variations list.
	 */
	public const VARIATION_FIELD = 'pixonaut_variation';

	/**
	 * The keys the fields are read back from, below `VARIATION_FIELD` for a
	 * variation and as `pixonaut_<key>` for the product.
	 */
	public const PERSONALISE = 'personalise';

	public const BLANK = 'blank';

	public const SIDES = 'sides';

	public const LAYOUT = 'layout';

	public const FORM_LAYOUT = 'form_layout';

	public const APPROVAL_MODE = 'approval_mode';

	/**
	 * Where the product's options stand in the designer's form, an `OptionGroups` choice; empty for the store's.
	 */
	public const OPTION_GROUPS = 'option_groups';

	/**
	 * Whether the designer's form numbers its groups, a `GroupNumbers` choice; empty for the store's.
	 */
	public const GROUP_NUMBERS = 'group_numbers';

	public const VIEWS = 'views';

	/**
	 * The printshop a product without a blank sends its orders to; empty for the workspace's default.
	 */
	public const PRINTSHOP = 'printshop';

	/**
	 * How the designer draws a print size whose ratio is not the template's, a `CanvasRatio` choice.
	 */
	public const CANVAS_RATIO = 'canvas_ratio';

	/**
	 * The starting view's id. It is posted inside the views field, under
	 * `ProductPanel::VIEWS_INITIAL_KEY`, so it is read for the same blank.
	 */
	public const INITIAL_VIEW = 'initial_view';

	public const OVERRIDE = 'override';

	public const VARIANT = 'variant';

	/**
	 * The view and photo a picture is drawn on (`PictureView`), and its
	 * marker, posted while the views were listed.
	 */
	public const PICTURE_VIEW = 'picture_view';

	public const PICTURE_VIEW_LISTED = 'picture_view_listed';

	public const NONCE = 'nonce';

	/**
	 * Put before the product's own fields' keys and ids.
	 */
	public const PRODUCT_PREFIX = 'pixonaut_';

	/**
	 * @param string $prefix    Put before every field's key: `pixonaut_`, or
	 *                          `pixonaut_variation[<loop>][` closed by $suffix.
	 * @param string $suffix    Put after every key.
	 * @param string $id_prefix Put before every field's id.
	 */
	private function __construct(
		private readonly string $prefix,
		private readonly string $suffix,
		public readonly string $id_prefix,
	) {
	}

	/**
	 * The product's own fields.
	 */
	public static function product(): self {
		return new self( self::PRODUCT_PREFIX, '', self::PRODUCT_PREFIX );
	}

	/**
	 * A variation's fields.
	 *
	 * @param int $loop The variation's place in the variations list.
	 */
	public static function variation( int $loop ): self {
		return new self( self::VARIATION_FIELD . '[' . $loop . '][', ']', self::VARIATION_FIELD . '_' . $loop . '_' );
	}

	/**
	 * The posted name of a field.
	 *
	 * @param string $key One of the key constants.
	 */
	public function name( string $key ): string {
		return $this->prefix . $key . $this->suffix;
	}

	/**
	 * The id of a field.
	 *
	 * @param string $key One of the key constants, or a side's field.
	 */
	public function id( string $key ): string {
		return $this->id_prefix . $key;
	}
}
