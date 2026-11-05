<?php
/**
 * Makes a page that holds `[product_page]` in a page builder's own layout, for
 * the compatibility tests and the preview site.
 *
 * @package Pixonaut\WooCommerce
 */

/**
 * Elementor's layout of one shortcode.
 *
 * @param string $shortcode The shortcode.
 */
function pixonaut_compat_elementor_data( string $shortcode ): string {
	return (string) wp_json_encode(
		array(
			array(
				'id'       => 'pxsect1',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'pxcol01',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'pxwidg1',
								'elType'     => 'widget',
								'widgetType' => 'shortcode',
								'settings'   => array( 'shortcode' => $shortcode ),
								'elements'   => array(),
							),
						),
					),
				),
			),
		)
	);
}

/**
 * Beaver Builder's layout of one shortcode: a row, a column group, a column
 * and an HTML module.
 *
 * @param string $shortcode The shortcode.
 * @return array<string, object>
 */
function pixonaut_compat_beaver_data( string $shortcode ): array {
	$node = static fn ( string $id, string $type, ?string $parent_id, array $settings = array(), array $extra = array() ): object => (object) array_merge(
		array(
			'node'     => $id,
			'type'     => $type,
			'parent'   => $parent_id,
			'position' => 0,
			'settings' => (object) $settings,
		),
		$extra
	);
	return array(
		'pxrow1' => $node( 'pxrow1', 'row', null ),
		'pxgrp1' => $node( 'pxgrp1', 'column-group', 'pxrow1' ),
		'pxcol1' => $node( 'pxcol1', 'column', 'pxgrp1', array( 'size' => 100 ) ),
		'pxmod1' => $node(
			'pxmod1',
			'module',
			'pxcol1',
			array(
				'type' => 'html',
				'html' => $shortcode,
			)
		),
	);
}

/**
 * SiteOrigin's layout of one shortcode, in a text widget.
 *
 * @param string $shortcode The shortcode.
 * @return array<string, mixed>
 */
function pixonaut_compat_siteorigin_data( string $shortcode ): array {
	return array(
		'widgets'    => array(
			array(
				'text'        => $shortcode,
				'filter'      => false,
				'panels_info' => array(
					'class'     => 'WP_Widget_Text',
					'grid'      => 0,
					'cell'      => 0,
					'id'        => 0,
					'widget_id' => 'pixonaut-preview-text',
				),
			),
		),
		'grids'      => array( array( 'cells' => 1 ) ),
		'grid_cells' => array(
			array(
				'grid'   => 0,
				'weight' => 1,
			),
		),
	);
}

/**
 * Makes the page. Its content holds the shortcode too, as a builder's own
 * content does for a site without the builder.
 *
 * @param string $builder    A builder's slug, a key of the combinations' `builders`.
 * @param int    $product_id The product on the page.
 */
function pixonaut_compat_builder_page( string $builder, int $product_id ): int {
	$shortcode = sprintf( '[product_page id="%d"]', $product_id );
	$page_id   = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Product on ' . $builder,
			'post_content' => $shortcode,
		)
	);
	if ( 'elementor' === $builder ) {
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		// wp_slash: update_post_meta unslashes, and the JSON's own backslashes are data.
		update_post_meta( $page_id, '_elementor_data', wp_slash( pixonaut_compat_elementor_data( $shortcode ) ) );
	} elseif ( 'beaver-builder-lite-version' === $builder ) {
		update_post_meta( $page_id, '_fl_builder_enabled', 1 );
		update_post_meta( $page_id, '_fl_builder_data', pixonaut_compat_beaver_data( $shortcode ) );
		update_post_meta( $page_id, '_fl_builder_draft', pixonaut_compat_beaver_data( $shortcode ) );
	} elseif ( 'siteorigin-panels' === $builder ) {
		update_post_meta( $page_id, 'panels_data', pixonaut_compat_siteorigin_data( $shortcode ) );
	}
	return $page_id;
}
