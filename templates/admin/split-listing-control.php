<?php
/**
 * The control on a global attribute's row in the product's Attributes tab
 * that lists each of the attribute's values as its own product: Auto
 * (follow the attribute), On or Off. A click cycles through them.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/split-listing-control.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 1.4.0
 *
 * @var string                $field       The posted choice's name.
 * @var string                $id          The control's id.
 * @var string                $choice      The product's choice: `auto`, `on` or `off`.
 * @var array<string, string> $states      What each choice reads as beside the control, by choice.
 * @var string                $nonce_field The control's nonce field.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_checked = array(
	'auto' => 'mixed',
	'on'   => 'true',
	'off'  => 'false',
);
?>
<tr class="pixonaut-split-listing">
	<td>
		<div class="enable_variation show_if_variable">
			<input type="hidden" class="pixonaut-split-listing__choice" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $choice ); ?>" />
			<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() output. ?>
			<button type="button" class="pixonaut-split-listing__toggle" role="checkbox" id="<?php echo esc_attr( $id ); ?>" aria-checked="<?php echo esc_attr( $pixonaut_checked[ $choice ] ?? 'mixed' ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-state"
				<?php foreach ( $states as $pixonaut_choice => $pixonaut_text ) : ?>
					data-state-<?php echo esc_attr( $pixonaut_choice ); ?>="<?php echo esc_attr( $pixonaut_text ); ?>"
				<?php endforeach; ?>
			>
				<span class="pixonaut-split-listing__box" aria-hidden="true"></span>
				<?php
				/* translators: Three-way checkbox on an attribute's row in the product's Attributes tab: the shop lists each of the attribute's values as its own product. */
				esc_html_e( 'Show each value as its own product', 'pixonaut-for-woocommerce' );
				?>
			</button>
			<span class="pixonaut-split-listing__state description" id="<?php echo esc_attr( $id ); ?>-state"><?php echo esc_html( $states[ $choice ] ?? '' ); ?></span>
		</div>
	</td>
</tr>
