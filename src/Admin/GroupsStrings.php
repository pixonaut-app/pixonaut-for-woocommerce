<?php
/**
 * The words of a side's Groups setting.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

/**
 * What the product screen's script writes in a side's Groups setting, which
 * it draws from the template's groups: translated here, as the script has no
 * translations of its own.
 */
final class GroupsStrings {

	/**
	 * The words by the key the script reads them by.
	 *
	 * @return array<string, string|array<string, string>>
	 */
	public static function all(): array {
		return array(
			'noTemplate'    => __( 'Choose a template to arrange its groups.', 'pixonaut-for-woocommerce' ),
			'loading'       => __( 'Reading the template’s groups…', 'pixonaut-for-woocommerce' ),
			'unreachable'   => __( 'Pixonaut could not be reached to read the template’s groups. The arrangement saved before is kept.', 'pixonaut-for-woocommerce' ),
			/* translators: %s: names of the template's groups and fields the arrangement names, which the template no longer has, separated by commas. Admin product page. */
			'gone'          => __( 'The template changed since this was arranged: %s is gone and is left out.', 'pixonaut-for-woocommerce' ),
			'added'         => __( 'The template has fields this arrangement does not name yet: they show at the end of their group, marked New, until the product is updated.', 'pixonaut-for-woocommerce' ),
			/* translators: Heading of the tray holding the product's variation attributes (such as Size or Colour) not placed in a designer group yet. Admin product page. */
			'tray'          => __( 'Product options', 'pixonaut-for-woocommerce' ),
			'trayHint'      => __( 'Drag an option into a group, or onto New group below. Options left here come first, in a group of their own.', 'pixonaut-for-woocommerce' ),
			'trayEmpty'     => __( 'Every option is in a group. Drag one back here to show it first again.', 'pixonaut-for-woocommerce' ),
			'noOptions'     => __( 'This product has no options used for variations.', 'pixonaut-for-woocommerce' ),
			/* translators: Screen reader label of the button that shows or hides the Product options tray, the product's variation attributes not placed in a designer group. Admin product page. */
			'trayToggle'    => __( 'Show or hide the product options', 'pixonaut-for-woocommerce' ),
			'manySides'     => __( 'Options are placed in the designer on a product with one side. With more sides they stay in the product form.', 'pixonaut-for-woocommerce' ),
			/* translators: Screen reader label of the button that shows or hides the fields of one designer group. Admin product page. */
			'toggle'        => __( 'Show or hide the group’s items', 'pixonaut-for-woocommerce' ),
			/* translators: Screen reader label of the handle that moves a group or an item; Alt and the arrow keys move it too. Admin product page. */
			'move'          => __( 'Move (Alt and the arrow keys)', 'pixonaut-for-woocommerce' ),
			/* translators: Screen reader label of the field renaming a designer group for this product. Admin product page. */
			'groupName'     => __( 'Group name', 'pixonaut-for-woocommerce' ),
			/* translators: Tag on a designer group the merchant added for this product, rather than one of the template's. Admin product page. */
			'ownGroup'      => __( 'Own group', 'pixonaut-for-woocommerce' ),
			/* translators: Button that removes a group the merchant added. Admin product page. */
			'remove'        => __( 'Remove', 'pixonaut-for-woocommerce' ),
			'emptyGroup'    => __( 'Drag fields or options here.', 'pixonaut-for-woocommerce' ),
			/* translators: Kind of an item in a designer group: one of the product's variation attributes, such as Size. Admin product page. */
			'option'        => _x( 'Option', 'a product attribute used for variations', 'pixonaut-for-woocommerce' ),
			'wcOption'      => __( 'WooCommerce option', 'pixonaut-for-woocommerce' ),
			/* translators: Button on an option placed in a designer group that takes it out, back to the Product options tray. Admin product page. */
			'unplace'       => __( 'Back to Product options', 'pixonaut-for-woocommerce' ),
			/* translators: Screen reader label of the field renaming a designer field for this product. Admin product page. */
			'fieldLabel'    => __( 'Field label', 'pixonaut-for-woocommerce' ),
			/* translators: Link that shows more words of a field to rename: its help text, placeholder and choices. Admin product page. */
			'more'          => _x( 'More', 'show more of a field', 'pixonaut-for-woocommerce' ),
			/* translators: Link that hides the help text, placeholder and choices of a field again. Admin product page. */
			'fewer'         => _x( 'Fewer', 'show less of a field', 'pixonaut-for-woocommerce' ),
			/* translators: Label of the field renaming a designer field's help text. Admin product page. */
			'help'          => __( 'Help', 'pixonaut-for-woocommerce' ),
			/* translators: Label of the field renaming a text field's placeholder, the hint shown while it is empty. Admin product page. */
			'placeholder'   => __( 'Placeholder', 'pixonaut-for-woocommerce' ),
			/* translators: Label of the list renaming a field's offered values. %s: how many there are. Admin product page. */
			'choices'       => __( 'Choices (%s)', 'pixonaut-for-woocommerce' ),
			/* translators: Tag on a field the template gained since the groups were arranged. Admin product page. */
			'new'           => _x( 'New', 'a field the template gained', 'pixonaut-for-woocommerce' ),
			'newGroup'      => __( '+ New group: drop an option or a field here', 'pixonaut-for-woocommerce' ),
			/* translators: Placeholder of a new group's name field. Admin product page. */
			'newGroupTitle' => __( 'Name customers see', 'pixonaut-for-woocommerce' ),
			/* translators: Link that clears the arrangement, so the designer shows the template's own groups and names. Admin product page. */
			'reset'         => __( 'Use the template’s groups', 'pixonaut-for-woocommerce' ),
			'types'         => array(
				/* translators: Kind of a designer field: text the customer types. Admin product page. */
				'text'    => _x( 'Text', 'designer field type', 'pixonaut-for-woocommerce' ),
				/* translators: Kind of a designer field: a choice from a list. Admin product page. */
				'select'  => _x( 'Choice', 'designer field type', 'pixonaut-for-woocommerce' ),
				/* translators: Kind of a designer field: a number. Admin product page. */
				'number'  => _x( 'Number', 'designer field type', 'pixonaut-for-woocommerce' ),
				/* translators: Kind of a designer field: a colour. Admin product page. */
				'color'   => _x( 'Colour', 'designer field type', 'pixonaut-for-woocommerce' ),
				/* translators: Kind of a designer field: a picture the customer uploads or picks. Admin product page. */
				'image'   => _x( 'Picture', 'designer field type', 'pixonaut-for-woocommerce' ),
				/* translators: Kind of a designer field: a percentage, such as opacity. Admin product page. */
				'percent' => _x( 'Percent', 'designer field type', 'pixonaut-for-woocommerce' ),
				/* translators: Kind of a designer field: the crop of a picture. Admin product page. */
				'crop'    => _x( 'Crop', 'designer field type', 'pixonaut-for-woocommerce' ),
			),
			/* translators: Kind of a designer field of a type this plugin does not know yet. Admin product page. */
			'field'         => _x( 'Field', 'designer field of an unknown type', 'pixonaut-for-woocommerce' ),
		);
	}
}
