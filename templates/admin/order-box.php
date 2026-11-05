<?php
/**
 * The Pixonaut box on the order screen: each personalised line, its proofs,
 * where it stands and what can be done about it.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/order-box.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var list<array{item_id: int, name: string, state: string, state_label: string, reason: string, sides: list<array{key: string, design_id: string, proof_url: string, proof_srcset: string, studio_url: string, erased: bool}>, order_id: string, order_url: string, order_status: string, made_by: string, delivery: string, order_erased: bool, unreadable: string, actions: list<string>}> $lines The order's Pixonaut lines.
 * @var bool   $can_act     Whether the user may act on a line.
 * @var string $nonce_field The actions' nonce field.
 * @var array{code: string, detail: string, message: string}|null $result What the last action came to, and why in words when Pixonaut said; null when none.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_results = array(
	'done'                => array( 'success', __( 'Done. The item below shows where it stands now.', 'pixonaut-for-woocommerce' ) ),
	'not_available'       => array( 'error', __( 'That action is no longer available for the item. The actions below are up to date.', 'pixonaut-for-woocommerce' ) ),
	'reason_missing'      => array( 'error', __( 'Say what the printshop told you; Pixonaut keeps it with the order.', 'pixonaut-for-woocommerce' ) ),
	'status_not_allowed'  => array( 'warning', __( 'The order changed in Pixonaut meanwhile, so nothing was changed. The actions below are up to date.', 'pixonaut-for-woocommerce' ) ),
	'reference_taken'     => array( 'error', __( 'Another order in Pixonaut has that printshop reference. Nothing was changed; check the reference.', 'pixonaut-for-woocommerce' ) ),
	'line_changed'        => array( 'warning', __( 'The item changed while the action ran. Check it below and try again if needed.', 'pixonaut-for-woocommerce' ) ),
	/* translators: %s: the new printshop order's status in Pixonaut, e.g. queued. Shown to the merchant on the order screen. */
	'retry_still_pending' => array( 'warning', __( 'The order was placed again, but its status in Pixonaut is "%s". Check it below.', 'pixonaut-for-woocommerce' ) ),
	/* translators: %s: the error code Pixonaut answered. */
	'refused'             => array( 'error', __( 'Pixonaut refused it with "%s". Nothing was changed.', 'pixonaut-for-woocommerce' ) ),
	'unreachable'         => array( 'warning', __( 'Pixonaut could not be reached, so it is not known whether it took the action. Reload the page: the box shows where the item stands.', 'pixonaut-for-woocommerce' ) ),
	'not_scheduled'       => array( 'error', __( 'The item could not be scheduled to go again, so it still needs attention. Check WooCommerce > Status > Scheduled Actions, then retry.', 'pixonaut-for-woocommerce' ) ),
);

$pixonaut_action_labels = array(
	/* translators: Button on the order screen: place the printshop order for this item again. */
	'retry'            => __( 'Retry', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen: place a new printshop order for this item although one exists. Here Order is a verb. */
	'reorder'          => __( 'Order again anyway', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen: the merchant confirms the printshop received the order. */
	'settle_submitted' => __( 'Printshop has it', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen: the printshop did not receive the order, so send it again. */
	'settle_failed'    => __( 'Printshop does not have it: send again', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen: cancel the item's order at the printshop. */
	'cancel'           => __( 'Cancel the printshop order', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen in sandbox mode: simulate the printshop order being queued. */
	'rehearse_queued'  => __( 'Rehearse: queued', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen in sandbox mode: simulate the printshop order being printed. */
	'rehearse_printed' => __( 'Rehearse: printed', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen in sandbox mode: simulate the printshop order being shipped. */
	'rehearse_shipped' => __( 'Rehearse: shipped', 'pixonaut-for-woocommerce' ),
	/* translators: Button on the order screen in sandbox mode: simulate the printshop order failing. */
	'rehearse_failed'  => __( 'Rehearse: failed', 'pixonaut-for-woocommerce' ),
);
?>
<div class="pixonaut-order-box">
	<?php if ( null !== $result && isset( $pixonaut_results[ $result['code'] ] ) ) : ?>
		<?php list( $pixonaut_type, $pixonaut_message ) = $pixonaut_results[ $result['code'] ]; ?>
		<div class="notice notice-<?php echo esc_attr( $pixonaut_type ); ?> inline pixonaut-order-box-result"><p><?php echo esc_html( '' !== ( $result['message'] ?? '' ) ? $result['message'] : sprintf( $pixonaut_message, $result['detail'] ) ); ?></p></div>
	<?php endif; ?>
	<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field()'s own markup. ?>
	<?php foreach ( $lines as $pixonaut_line ) : ?>
		<div class="pixonaut-order-line" data-item-id="<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>" data-state="<?php echo esc_attr( $pixonaut_line['state'] ); ?>">
			<h4><?php echo esc_html( $pixonaut_line['name'] ); ?></h4>
			<p class="pixonaut-order-line-state">
				<strong><?php echo esc_html( $pixonaut_line['state_label'] ); ?></strong>
				<?php if ( '' !== $pixonaut_line['reason'] ) : ?>
					<br /><?php echo esc_html( $pixonaut_line['reason'] ); ?>
				<?php endif; ?>
			</p>
			<ul class="pixonaut-order-line-sides">
				<?php foreach ( $pixonaut_line['sides'] as $pixonaut_side ) : ?>
					<li>
						<?php if ( '' !== $pixonaut_side['proof_url'] ) : ?>
							<img src="<?php echo esc_url( $pixonaut_side['proof_url'] ); ?>" srcset="<?php echo esc_attr( $pixonaut_side['proof_srcset'] ); ?>" width="<?php echo esc_attr( (string) \Pixonaut\WooCommerce\Admin\OrderBox::PROOF_WIDTH ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: key of the product side the proof shows, e.g. front. */ __( 'Proof of the %s', 'pixonaut-for-woocommerce' ), $pixonaut_side['key'] ) ); ?>" loading="lazy" decoding="async" />
						<?php endif; ?>
						<span><?php echo esc_html( $pixonaut_side['key'] ); ?></span>
						<?php if ( $pixonaut_side['erased'] ) : ?>
							<span class="pixonaut-order-line-erased"><?php esc_html_e( 'Personalisation removed after the shop\'s retention period: the customer\'s pictures, text and print files are erased in Pixonaut.', 'pixonaut-for-woocommerce' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $pixonaut_side['studio_url'] ) : ?>
							<a href="<?php echo esc_url( $pixonaut_side['studio_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link on the order screen to an item's design in the Pixonaut studio. */ esc_html_e( 'Open the design in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( '' !== $pixonaut_line['order_id'] ) : ?>
				<p class="pixonaut-order-line-order">
					<?php
					echo esc_html(
						'' === $pixonaut_line['order_status']
							/* translators: %s: id of the printshop order in Pixonaut. Shown to the merchant on the order screen. */
							? sprintf( __( 'Printshop order %s', 'pixonaut-for-woocommerce' ), $pixonaut_line['order_id'] )
							/* translators: 1: id of the printshop order in Pixonaut, 2: its status there, e.g. queued. Shown to the merchant on the order screen. */
							: sprintf( __( 'Printshop order %1$s, "%2$s" in Pixonaut', 'pixonaut-for-woocommerce' ), $pixonaut_line['order_id'], $pixonaut_line['order_status'] )
					);
					?>
					<?php if ( '' !== $pixonaut_line['order_url'] ) : ?>
						<a href="<?php echo esc_url( $pixonaut_line['order_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link on the order screen to the item's printshop order in the Pixonaut studio. */ esc_html_e( 'Open in Pixonaut', 'pixonaut-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( $pixonaut_line['order_erased'] ) : ?>
				<p class="pixonaut-order-line-erased"><?php esc_html_e( 'Recipient details removed after the shop\'s retention period: the printshop order\'s name, address and contact details are erased in Pixonaut.', 'pixonaut-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $pixonaut_line['made_by'] ) : ?>
				<p class="pixonaut-order-line-made-by"><?php echo esc_html( $pixonaut_line['made_by'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $pixonaut_line['delivery'] ) : ?>
				<p class="pixonaut-order-line-delivery"><?php echo esc_html( $pixonaut_line['delivery'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $pixonaut_line['unreadable'] ) : ?>
				<p class="pixonaut-order-line-unreadable">
					<?php
					/* translators: %s: the error code Pixonaut answered. */
					echo esc_html( sprintf( __( 'Pixonaut could not be asked about the order ("%s"), so its actions are not shown. Reload the page to try again.', 'pixonaut-for-woocommerce' ), $pixonaut_line['unreadable'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $can_act && array() !== $pixonaut_line['actions'] ) : ?>
				<div class="pixonaut-order-line-actions">
					<?php if ( array_intersect( array( 'settle_submitted', 'settle_failed' ), $pixonaut_line['actions'] ) ) : ?>
						<p>
							<label for="pixonaut-reason-<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>"><?php /* translators: Label of the field where the merchant types the printshop's message about a printshop order, on the order screen. */ esc_html_e( 'What did the printshop say?', 'pixonaut-for-woocommerce' ); ?></label><br />
							<textarea id="pixonaut-reason-<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>" name="pixonaut_line[<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>][reason]" rows="2" cols="60" maxlength="<?php echo esc_attr( (string) \Pixonaut\WooCommerce\Fulfilment\LineActions::REASON_MAX_LENGTH ); ?>"></textarea>
						</p>
					<?php endif; ?>
					<?php if ( in_array( 'settle_submitted', $pixonaut_line['actions'], true ) ) : ?>
						<p>
							<label for="pixonaut-ref-<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>"><?php /* translators: Label of the field for the printshop's own reference for an order, on the order screen. */ esc_html_e( 'The printshop\'s order reference, if it gave one', 'pixonaut-for-woocommerce' ); ?></label><br />
							<input type="text" id="pixonaut-ref-<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>" name="pixonaut_line[<?php echo esc_attr( (string) $pixonaut_line['item_id'] ); ?>][ref]" maxlength="<?php echo esc_attr( (string) \Pixonaut\WooCommerce\Fulfilment\LineActions::ORDER_REF_MAX_LENGTH ); ?>" />
						</p>
					<?php endif; ?>
					<p>
						<?php foreach ( $pixonaut_line['actions'] as $pixonaut_action ) : ?>
							<button type="submit" class="button<?php echo 'retry' === $pixonaut_action || 'settle_submitted' === $pixonaut_action ? ' button-primary' : ''; ?>" name="pixonaut_line_action" value="<?php echo esc_attr( $pixonaut_line['item_id'] . ':' . $pixonaut_action ); ?>"><?php echo esc_html( $pixonaut_action_labels[ $pixonaut_action ] ?? $pixonaut_action ); ?></button>
						<?php endforeach; ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
