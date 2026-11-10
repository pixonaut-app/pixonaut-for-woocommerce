<?php
/**
 * The views a product is shown in on its page, each a tile with its photo,
 * and the one the designer opens on, as a closed section of the Pixonaut
 * tab. The panel draws it again when another blank is picked, before the
 * product is updated.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/product-views.php.
 * Field names, classes and the `data-` attributes are what the plugin reads; keep them.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var array{views: string}                    $names        The views field's posted name.
 * @var array{initial_view: string}             $ids          The starting view field's id.
 * @var string                                  $blank_id     The blank the views are listed for; empty without one.
 * @var list<array{name: string, views: list<array{id: string, label: string, thumbnail: string}>}>|null $views The blank's views, placement by placement; null without a blank, or when Pixonaut could not be asked.
 * @var list<string>                            $chosen_views The ids of the views it is shown in; empty for all.
 * @var string                                  $initial_view The id of the view the designer starts on; empty for the first.
 * @var string                                  $design_preview The template's preview, which the view of the design alone shows; empty for none.
 * @var bool                                    $open         Whether the section was open when the product was last updated.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_all = null === $views ? 0 : 1 + array_sum( array_map( static fn ( array $placement ): int => count( $placement['views'] ), $views ) );
$pixonaut_on  = count( $chosen_views );
// The view the designer opens on: the one chosen, else the first shown.
$pixonaut_opens = $initial_view;
foreach ( '' === $pixonaut_opens && null !== $views ? $views : array() as $pixonaut_placement ) {
	foreach ( $pixonaut_placement['views'] as $pixonaut_view ) {
		if ( '' === $pixonaut_opens && ( 0 === $pixonaut_on || in_array( $pixonaut_view['id'], $chosen_views, true ) ) ) {
			$pixonaut_opens = $pixonaut_view['id'];
		}
	}
}
$pixonaut_opens = '' === $pixonaut_opens ? \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW : $pixonaut_opens;
$pixonaut_label = __( 'The design alone', 'pixonaut-for-woocommerce' );
foreach ( null === $views ? array() : $views as $pixonaut_placement ) {
	foreach ( $pixonaut_placement['views'] as $pixonaut_view ) {
		$pixonaut_label = $pixonaut_view['id'] === $pixonaut_opens ? $pixonaut_view['label'] : $pixonaut_label;
	}
}
$pixonaut_badge = static function ( string $id ) use ( $pixonaut_opens ): void {
	?>
	<span class="pixonaut-view__opens" <?php echo $id === $pixonaut_opens ? '' : 'hidden'; ?>><?php /* translators: Badge on the view the designer opens on, in the Pixonaut tab of the product edit page. */ esc_html_e( 'Opens first', 'pixonaut-for-woocommerce' ); ?></span>
	<?php
};
?>
<details class="pixonaut-group pixonaut-disclosure pixonaut-views-section" data-disclosure="views" <?php echo $open || $pixonaut_on > 0 || '' !== $initial_view ? 'open' : ''; ?>>
	<summary>
		<strong><?php /* translators: Heading of a closed section in the Pixonaut tab of the product edit page: the photos of the blank customers see their design on. */ esc_html_e( 'Views on the product page', 'pixonaut-for-woocommerce' ); ?></strong>
		<span class="pixonaut-disclosure__summary" data-none="<?php esc_attr_e( 'Every view', 'pixonaut-for-woocommerce' ); ?>" data-some="<?php /* translators: 1: how many views are ticked, 2: how many there are. Admin product page. */ esc_attr_e( '%1$d of %2$d views', 'pixonaut-for-woocommerce' ); ?>" data-all="<?php echo esc_attr( (string) $pixonaut_all ); ?>">
			<?php
			echo esc_html(
				0 === $pixonaut_on || 0 === $pixonaut_all
					? __( 'Every view', 'pixonaut-for-woocommerce' )
					/* translators: 1: how many views are ticked, 2: how many there are. Admin product page. */
					: sprintf( __( '%1$d of %2$d views', 'pixonaut-for-woocommerce' ), $pixonaut_on, $pixonaut_all )
			);
			?>
		</span>
		<?php if ( null !== $views ) : ?>
			<?php /* translators: %s: the view the designer opens on, e.g. "Front". Summary of a closed section of the Pixonaut tab of the product edit page. */ $pixonaut_opens_text = __( 'opens on %s', 'pixonaut-for-woocommerce' ); ?>
			<span class="pixonaut-disclosure__summary pixonaut-views__opens-summary" data-text="<?php echo esc_attr( $pixonaut_opens_text ); ?>"><?php echo esc_html( sprintf( $pixonaut_opens_text, $pixonaut_label ) ); ?></span>
		<?php endif; ?>
	</summary>
	<div class="pixonaut-with-blank pixonaut-views" data-blank="<?php echo esc_attr( $blank_id ); ?>">
		<p class="pixonaut-group__intro pixonaut-views__intro"><?php esc_html_e( 'The pictures customers switch between while they design.', 'pixonaut-for-woocommerce' ); ?></p>
		<div class="form-field pixonaut-views__shown">
			<label class="pixonaut-views__label"><?php /* translators: Label of the views customers see their design in. Admin product page. */ esc_html_e( 'Views shown', 'pixonaut-for-woocommerce' ); ?></label>
			<?php if ( null !== $views ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_BLANK_KEY . ']' ); ?>" value="<?php echo esc_attr( $blank_id ); ?>" />
				<?php foreach ( $views as $pixonaut_placement ) : ?>
					<fieldset class="pixonaut-views__placement">
						<legend><?php echo esc_html( $pixonaut_placement['name'] ); ?></legend>
						<ul class="pixonaut-views__list">
							<?php foreach ( $pixonaut_placement['views'] as $pixonaut_view ) : ?>
								<li class="pixonaut-view">
									<label>
										<span class="pixonaut-view__thumb">
											<?php if ( '' !== $pixonaut_view['thumbnail'] ) : ?>
												<img src="<?php echo esc_url( $pixonaut_view['thumbnail'] ); ?>" alt="" loading="lazy" />
											<?php endif; ?>
											<?php $pixonaut_badge( $pixonaut_view['id'] ); ?>
										</span>
										<span class="pixonaut-view__name">
											<input type="checkbox" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_TICKED_KEY . '][]' ); ?>" value="<?php echo esc_attr( $pixonaut_view['id'] ); ?>" <?php checked( in_array( $pixonaut_view['id'], $chosen_views, true ) ); ?> />
											<span><?php echo esc_html( $pixonaut_view['label'] ); ?></span>
										</span>
									</label>
								</li>
							<?php endforeach; ?>
							<?php if ( array() === $pixonaut_placement['views'] ) : ?>
								<li class="description"><?php esc_html_e( 'This print position has no views in Pixonaut yet.', 'pixonaut-for-woocommerce' ); ?></li>
							<?php endif; ?>
						</ul>
					</fieldset>
				<?php endforeach; ?>
				<fieldset class="pixonaut-views__placement pixonaut-views__design">
					<legend><?php echo esc_html_x( 'Design', 'view of the design alone, without the product', 'pixonaut-for-woocommerce' ); ?></legend>
					<ul class="pixonaut-views__list">
						<li class="pixonaut-view">
							<label>
								<span class="pixonaut-view__thumb pixonaut-view__thumb--design">
									<?php if ( '' !== $design_preview ) : ?>
										<img src="<?php echo esc_url( $design_preview ); ?>" alt="" loading="lazy" />
									<?php endif; ?>
									<?php $pixonaut_badge( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW ); ?>
								</span>
								<span class="pixonaut-view__name">
									<input type="checkbox" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_TICKED_KEY . '][]' ); ?>" value="<?php echo esc_attr( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW ); ?>" <?php checked( in_array( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW, $chosen_views, true ) ); ?> />
									<span><?php esc_html_e( 'The design alone', 'pixonaut-for-woocommerce' ); ?></span>
								</span>
							</label>
						</li>
					</ul>
				</fieldset>
			<?php elseif ( '' !== $blank_id ) : ?>
				<p class="description"><?php esc_html_e( 'Pixonaut could not be reached to list the blank’s views. The views chosen before are kept.', 'pixonaut-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<?php if ( null !== $views ) : ?>
				<span class="description pixonaut-views__hint" data-none="<?php esc_attr_e( 'None ticked: every view is shown.', 'pixonaut-for-woocommerce' ); ?>" data-some="<?php esc_attr_e( 'Only the ticked views are shown. Untick them all to show every view.', 'pixonaut-for-woocommerce' ); ?>"><?php 0 === $pixonaut_on ? esc_html_e( 'None ticked: every view is shown.', 'pixonaut-for-woocommerce' ) : esc_html_e( 'Only the ticked views are shown. Untick them all to show every view.', 'pixonaut-for-woocommerce' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( null !== $views ) : ?>
			<p class="form-field pixonaut-views__initial">
				<label for="<?php echo esc_attr( $ids['initial_view'] ); ?>"><?php /* translators: Label of the dropdown choosing which view the designer opens on. Admin product page. */ esc_html_e( 'Opens on', 'pixonaut-for-woocommerce' ); ?></label>
				<select id="<?php echo esc_attr( $ids['initial_view'] ); ?>" name="<?php echo esc_attr( $names['views'] . '[' . \Pixonaut\WooCommerce\Admin\ProductPanel::VIEWS_INITIAL_KEY . ']' ); ?>" class="pixonaut-initial-view">
					<option value=""><?php /* translators: Option in the Opens on dropdown: open on the first of the views shown. Admin product page. */ esc_html_e( 'The first view shown', 'pixonaut-for-woocommerce' ); ?></option>
					<?php foreach ( $views as $pixonaut_placement ) : ?>
						<?php if ( array() !== $pixonaut_placement['views'] ) : ?>
							<optgroup label="<?php echo esc_attr( $pixonaut_placement['name'] ); ?>">
								<?php foreach ( $pixonaut_placement['views'] as $pixonaut_view ) : ?>
									<option value="<?php echo esc_attr( $pixonaut_view['id'] ); ?>" <?php selected( $pixonaut_view['id'], $initial_view ); ?>><?php echo esc_html( $pixonaut_view['label'] ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					<?php endforeach; ?>
					<option value="<?php echo esc_attr( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW ); ?>" <?php selected( \Pixonaut\WooCommerce\Catalogue\ProductConfig::DESIGN_VIEW, $initial_view ); ?>><?php echo esc_html_x( 'Design', 'view of the design alone, without the product', 'pixonaut-for-woocommerce' ); ?></option>
				</select>
			</p>
		<?php endif; ?>
	</div>
</details>
