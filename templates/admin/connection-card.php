<?php
/**
 * The Pixonaut connection card on the settings page's Connection tab.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/connection-card.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string        $state              disconnected, connected, paused or unreachable.
 * @var string        $paused_reason      While paused: unauthorized (Pixonaut refused the key) or site_changed (not the site that connected).
 * @var string        $name               The integration key's name in Pixonaut.
 * @var string        $workspace          The workspace's name.
 * @var string        $environment        live or sandbox.
 * @var string        $site_url           The site Pixonaut knows the store as.
 * @var string[]      $origins            Origins the publishable key takes.
 * @var string        $www_origin         The site's www. counterpart, offered while connected; empty when there is none.
 * @var bool          $www_allowed        Whether the publishable key takes it.
 * @var string        $www_url            Allows or takes away the counterpart; add allow=1 or allow=0.
 * @var string        $brand_url          Chooses the store's brand when posted a brand field with a brand's id, or empty for the workspace's default; empty while not connected.
 * @var string        $print_file_url     Chooses the store's print file form when posted print_file_form (a form, or empty for the workspace's), print_file_tolerance (a number, or empty for the workspace's) and print_file_tolerance_unit (px or mm); empty while not connected.
 * @var array{on: bool, cut: bool, url: string}|null $store_access Whether Pixel may make changes to the store: `on`, whether it was turned off in WordPress (`cut`), and `url`, which takes a posted store_access field of 1 or 0; empty while the switch cannot be changed. Null shows no switch.
 * @var array{active: bool, failing: bool, failed_count: int, url: string}|null $webhook The webhook endpoint's health and address; null when unknown.
 * @var bool          $webhook_reachable  False while Pixonaut cannot reach the store, and so delivers it no webhooks.
 * @var array{delivered: bool, error: string, at: int}|null        $last_ping The last test request; null when none.
 * @var string        $ping_url           Sends a test request; empty when it cannot be sent.
 * @var bool          $can_connect        False while wp-admin or the REST API is on another origin than the site. The connect links show only while disconnected or paused.
 * @var string        $home_origin        The site's origin.
 * @var string        $admin_origin       wp-admin's origin.
 * @var string        $connect_url        Starts a connect; the member picks live or sandbox in Pixonaut.
 * @var array<string, string> $connect_urls Starts a connect offering only that environment, by live and sandbox.
 * @var string        $disconnect_url     Disconnects.
 * @var string        $studio_url         Pixonaut's studio.
 * @var string        $docs_url           The guide on connecting the store.
 * @var string        $tunnel_docs_url    The guide on developing locally with a tunnel.
 * @var string[]      $custom_hosts       The Pixonaut addresses in use that are not Pixonaut's own; empty when none.
 * @var string        $result             What the last action did, as a key; may be empty.
 * @var array<string, array{deprecation: string, sunset: string|null, seen_at: int}> $deprecations Deprecations Pixonaut announced, by operation.
 * @var array{brand_id: string|null, chosen_brand_id: string|null, brands: list<array{id: string, name: string, is_default: bool}>, logo_url: string|null, accent: string|null, watermark: bool|null, deliver_to: string|null, min_print_dpi: float|null, default_language: string|null, print_file_form?: string|null, print_file_tolerance?: array{value: float, unit: string}|null, prints_with?: array{form: string, live_text: bool, tolerance: string}}|null $workspace_settings
 *      The workspace's settings as Pixonaut answered them; null when they could not be read. `brand_id` is the brand the store's hosted pages are drawn in, its own or else the workspace's default, and `logo_url` and `accent` are that brand's; `chosen_brand_id` is the store's own, null while it follows the default; `brands` are the workspace's brands the store may choose from, the default first. `deliver_to` names the address every order ships to, null when orders ship to their customer. `print_file_form` and `print_file_tolerance` are the store's own choices, null while it follows the workspace's; `prints_with` is what its print files get, its own choices or else the workspace's, named.
 */

defined( 'ABSPATH' ) || exit;

$pixonaut_results = array(
	'connected'                      => array( 'success', __( 'The store is connected to Pixonaut.', 'pixonaut-for-woocommerce' ) ),
	'disconnected'                   => array( 'success', __( 'The store is disconnected from Pixonaut, and its keys are revoked.', 'pixonaut-for-woocommerce' ) ),
	'disconnected_locally'           => array( 'warning', __( 'The store forgot its Pixonaut keys, but Pixonaut could not be reached to revoke them. Disconnect the store on the API Keys page in the Pixonaut studio too.', 'pixonaut-for-woocommerce' ) ),
	'forgotten'                      => array( 'success', __( 'This site forgot the connection it was copied with. The site it was copied from stays connected.', 'pixonaut-for-woocommerce' ) ),
	'origin_mismatch'                => array( 'error', __( 'The store cannot connect while wp-admin is on another address than the site.', 'pixonaut-for-woocommerce' ) ),
	'store_access_on'                => array( 'success', __( 'Pixel can now make changes to your store.', 'pixonaut-for-woocommerce' ) ),
	'store_access_off'               => array( 'success', __( 'Pixel can no longer make changes to your store.', 'pixonaut-for-woocommerce' ) ),
	'store_access_off_locally'       => array( 'warning', __( 'Pixel can no longer make changes to your store. Pixonaut could not be told, so the studio may show the switch as on until it next tries.', 'pixonaut-for-woocommerce' ) ),
	'store_access_failed'            => array( 'error', __( 'Pixonaut could not be reached or did not accept the store\'s access, so the switch stays off. Try again.', 'pixonaut-for-woocommerce' ) ),
	'store_access_not_saved'         => array( 'error', __( 'The store could not save the change, so Pixel may still be able to make changes to your store. Check the database, then turn the switch off again.', 'pixonaut-for-woocommerce' ) ),
	'connected_without_store_access' => array( 'warning', __( 'The store is connected to Pixonaut, but Pixonaut could not be reached or did not accept the store\'s access, so Pixel cannot make changes to your store. Turn the switch on below to try again.', 'pixonaut-for-woocommerce' ) ),
	'www_allowed'                    => array( 'success', __( 'Personalised products now also load on the www. address.', 'pixonaut-for-woocommerce' ) ),
	'www_removed'                    => array( 'success', __( 'Personalised products load on the site\'s own address only.', 'pixonaut-for-woocommerce' ) ),
	'www_failed'                     => array( 'error', __( 'Pixonaut did not change the addresses personalised products load on. Try again.', 'pixonaut-for-woocommerce' ) ),
	'brand_chosen'                   => array( 'success', __( 'The store\'s brand is saved. Proofs, corrections and designer links made from now on show it.', 'pixonaut-for-woocommerce' ) ),
	'brand_not_found'                => array( 'error', __( 'That brand is no longer in the Pixonaut workspace, so the store\'s brand did not change. Choose another.', 'pixonaut-for-woocommerce' ) ),
	'brand_failed'                   => array( 'error', __( 'Pixonaut did not confirm the change of the store\'s brand. Check the brand shown below, and try again if it is not the one you chose.', 'pixonaut-for-woocommerce' ) ),
	'print_file_saved'               => array( 'success', __( 'The store\'s print file settings are saved. Print files made from now on follow them, unless a template sets its own.', 'pixonaut-for-woocommerce' ) ),
	/* translators: %s: the limits of a live text tolerance, e.g. 1 to 100 px, or 0.01 to 5 mm. */
	'print_file_invalid'             => array( 'error', sprintf( __( 'The live text tolerance is %s, so the print file settings did not change. Enter a tolerance in those limits, or leave it empty to follow the workspace.', 'pixonaut-for-woocommerce' ), Pixonaut\WooCommerce\Connection\PrintFileForm::tolerance_limits() ) ),
	'print_file_failed'              => array( 'error', __( 'Pixonaut did not confirm the change of the store\'s print file settings. Check the settings shown below, and try again if they are not the ones you chose.', 'pixonaut-for-woocommerce' ) ),
	'invalid_state'                  => array( 'error', __( 'That connect link expired or was already used. Connect again.', 'pixonaut-for-woocommerce' ) ),
	'access_denied'                  => array( 'warning', __( 'The connect was cancelled in Pixonaut.', 'pixonaut-for-woocommerce' ) ),
	'refused'                        => array( 'error', __( 'Pixonaut did not approve the connect. Connect again.', 'pixonaut-for-woocommerce' ) ),
	'exchange_failed'                => array( 'error', __( 'Pixonaut did not hand over the store\'s keys: the approval expired or Pixonaut could not be reached. Connect again.', 'pixonaut-for-woocommerce' ) ),
	'plan_limit_reached'             => array( 'error', __( 'The Pixonaut workspace\'s plan connects no more stores. Upgrade the plan, or disconnect another store on the API Keys page in the Pixonaut studio, then connect again.', 'pixonaut-for-woocommerce' ) ),
	'storage_failed'                 => array( 'error', __( 'Pixonaut handed over the store\'s keys, but the store could not save them to its database. Check the database, then connect again.', 'pixonaut-for-woocommerce' ) ),
	'busy'                           => array( 'warning', __( 'Another connect or disconnect is in progress. Try again in a minute.', 'pixonaut-for-woocommerce' ) ),
	'disconnect_failed'              => array( 'error', __( 'The store could not remove its Pixonaut keys from its database. Check the database, then disconnect again.', 'pixonaut-for-woocommerce' ) ),
);
$pixonaut_environments       = array(
	/* translators: Name of the production Pixonaut workspace environment, where orders reach a printshop. Shown on the connection card. */
	'live'    => __( 'Live', 'pixonaut-for-woocommerce' ),
	/* translators: Name of the Pixonaut test environment, where orders never reach a printshop. Shown on the connection card, as a heading or button. */
	'sandbox' => __( 'Sandbox', 'pixonaut-for-woocommerce' ),
);
$pixonaut_store_access_title = /* translators: The switch on the connection card that lets Pixel, Pixonaut's assistant, act on the store from the Pixonaut studio. */ __( 'Allow Pixel to make changes to your store', 'pixonaut-for-woocommerce' );
$pixonaut_store_access_scope = __( 'Pixel in the Pixonaut studio can then read and change products, variations, attributes, categories and tags, link templates to products, and read orders. Nothing else in WordPress.', 'pixonaut-for-woocommerce' );
$pixonaut_connect_links      = static function ( string $label ) use ( $connect_url, $connect_urls, $store_access, $pixonaut_store_access_title, $pixonaut_store_access_scope ): void {
	?>
	<form method="post" action="<?php echo esc_url( $connect_url ); ?>">
		<?php if ( null !== $store_access ) : ?>
			<div class="pixonaut-switch-row">
				<input type="checkbox" role="switch" class="pixonaut-switch" id="pixonaut-store-access" name="store_access" value="1" />
				<div>
					<label for="pixonaut-store-access"><strong><?php echo esc_html( $pixonaut_store_access_title ); ?></strong></label>
					<p class="description"><?php echo esc_html( $pixonaut_store_access_scope ); ?> <?php esc_html_e( 'You can turn this off at any time.', 'pixonaut-for-woocommerce' ); ?></p>
				</div>
			</div>
		<?php endif; ?>
		<p class="pixonaut-connect">
			<button type="submit" class="button button-primary"><?php echo esc_html( $label ); ?></button>
			<?php /* translators: Text before two buttons on the connection card, Live workspace and Sandbox, read: or connect only to the [Live workspace] [Sandbox]. */ esc_html_e( 'or connect only to the', 'pixonaut-for-woocommerce' ); ?>
			<button type="submit" class="button" formaction="<?php echo esc_url( $connect_urls['live'] ); ?>"><?php /* translators: Button on the connection card: connect only to the live Pixonaut workspace. */ esc_html_e( 'Live workspace', 'pixonaut-for-woocommerce' ); ?></button>
			<button type="submit" class="button" formaction="<?php echo esc_url( $connect_urls['sandbox'] ); ?>"><?php /* translators: Name of the Pixonaut test environment, where orders never reach a printshop. Shown on the connection card, as a heading or button. */ esc_html_e( 'Sandbox', 'pixonaut-for-woocommerce' ); ?></button>
		</p>
	</form>
	<p class="description"><?php esc_html_e( 'Connect a staging or test site to the sandbox: its orders never reach a printshop.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php
};
?>
<div class="pixonaut-connection" data-state="<?php echo esc_attr( $state ); ?>">
	<h2>
		<?php /* translators: Heading of the Pixonaut connection card on the settings page. */ esc_html_e( 'Connection', 'pixonaut-for-woocommerce' ); ?>
		<?php if ( array() !== $custom_hosts ) : ?>
			<span class="pixonaut-custom-host" title="<?php echo esc_attr( implode( ', ', $custom_hosts ) ); ?>"><?php /* translators: Badge on the connection card: the store talks to another address than Pixonaut's own, set by a developer. */ esc_html_e( 'Custom Pixonaut host', 'pixonaut-for-woocommerce' ); ?></span>
		<?php endif; ?>
	</h2>
	<?php if ( array() !== $custom_hosts ) : ?>
		<p class="pixonaut-custom-host-note">
			<?php
			/* translators: %s: comma separated addresses the store sends requests to instead of Pixonaut's, e.g. http://localhost:8787. Shown to the merchant on the connection card. */
			echo esc_html( sprintf( __( 'The store talks to %s instead of Pixonaut, and sends its key there.', 'pixonaut-for-woocommerce' ), implode( ', ', $custom_hosts ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( isset( $pixonaut_results[ $result ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $pixonaut_results[ $result ][0] ); ?> inline pixonaut-connection-result">
			<p><?php echo esc_html( $pixonaut_results[ $result ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<?php foreach ( $deprecations as $pixonaut_operation => $pixonaut_deprecation ) : ?>
		<div class="notice notice-warning inline pixonaut-deprecation">
			<p>
				<?php
				echo esc_html(
					null === $pixonaut_deprecation['sunset']
						/* translators: %s: an HTTP method and path of a Pixonaut API operation, e.g. GET /v1/connect. Shown to the merchant on the connection card. */
						? sprintf( __( 'Pixonaut deprecated %s, which this plugin uses. Update Pixonaut for WooCommerce.', 'pixonaut-for-woocommerce' ), $pixonaut_operation )
						/* translators: 1: an HTTP method and path of a Pixonaut API operation, e.g. GET /v1/connect, 2: the date it stops working. Shown to the merchant on the connection card. */
						: sprintf( __( 'Pixonaut deprecated %1$s, which this plugin uses, and stops answering it on %2$s. Update Pixonaut for WooCommerce before then.', 'pixonaut-for-woocommerce' ), $pixonaut_operation, $pixonaut_deprecation['sunset'] )
				);
				?>
			</p>
		</div>
	<?php endforeach; ?>

	<?php if ( ! $can_connect && in_array( $state, array( 'disconnected', 'paused' ), true ) ) : ?>
		<p class="pixonaut-connection-blocked">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: 1: wp-admin's origin, e.g. https://admin.example.com, 2: the site's origin, 3: HTML link to Settings > General. Shown to the merchant on the connection card. */
					__( 'wp-admin is at <code>%1$s</code> and the site at <code>%2$s</code>. Pixonaut sends you back to wp-admin and delivers events to the REST API only on the site\'s own address. Make the WordPress Address and the Site Address the same under <a href="%3$s">Settings &gt; General</a>, then connect.', 'pixonaut-for-woocommerce' ),
					esc_html( $admin_origin ),
					esc_html( $home_origin ),
					esc_url( admin_url( 'options-general.php' ) )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( 'disconnected' === $state ) : ?>
		<?php if ( $can_connect ) : ?>
			<p><?php esc_html_e( 'Connect the store to a Pixonaut workspace. You approve the connection in Pixonaut and are sent back here.', 'pixonaut-for-woocommerce' ); ?></p>
			<?php $pixonaut_connect_links( /* translators: Button on the connection card that starts connecting the store; here Connect is a verb. */ __( 'Connect to Pixonaut', 'pixonaut-for-woocommerce' ) ); ?>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( 'paused' === $state && 'site_changed' === $paused_reason ) : ?>
			<div class="notice notice-error inline pixonaut-connection-paused">
				<p><?php esc_html_e( 'Site URL changed: this site is not the one that connected to Pixonaut, so nothing is sent for the site it was copied from. Personalised products and orders are paused until you connect this site. For a staging site, pick Sandbox.', 'pixonaut-for-woocommerce' ); ?></p>
			</div>
		<?php elseif ( 'paused' === $state ) : ?>
			<div class="notice notice-error inline pixonaut-connection-paused">
				<p><?php esc_html_e( 'Pixonaut no longer accepts this store\'s key: the store was disconnected in Pixonaut, or its key was revoked. Personalised products and orders are paused until you connect again.', 'pixonaut-for-woocommerce' ); ?></p>
			</div>
		<?php elseif ( 'unreachable' === $state ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Pixonaut could not be reached just now. This is the connection as it was when the store connected.', 'pixonaut-for-woocommerce' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="form-table pixonaut-connection-details">
			<tr>
				<th scope="row"><?php /* translators: Row heading on the connection card: the name of the store's API key in Pixonaut. */ esc_html_e( 'Key name', 'pixonaut-for-woocommerce' ); ?></th>
				<td class="pixonaut-connection-name"><?php echo esc_html( $name ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading on the connection card: the Pixonaut workspace the store is connected to. */ esc_html_e( 'Workspace', 'pixonaut-for-woocommerce' ); ?></th>
				<td class="pixonaut-connection-workspace"><?php echo esc_html( $workspace ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading on the connection card: live or sandbox. */ esc_html_e( 'Environment', 'pixonaut-for-woocommerce' ); ?></th>
				<td>
					<span class="pixonaut-connection-environment pixonaut-environment-<?php echo esc_attr( $environment ); ?>"><?php echo esc_html( $pixonaut_environments[ $environment ] ?? $environment ); ?></span>
					<?php if ( 'sandbox' === $environment ) : ?>
						<p class="description"><?php esc_html_e( 'Orders placed in the sandbox never reach a printshop.', 'pixonaut-for-woocommerce' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading on the connection card: the store's address. */ esc_html_e( 'Site URL', 'pixonaut-for-woocommerce' ); ?></th>
				<td class="pixonaut-connection-site"><?php echo esc_html( $site_url ); ?></td>
			</tr>
			<?php if ( '' !== $www_origin ) : ?>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: the www. address of the site, which can be allowed in addition. */ esc_html_e( 'Also allow', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-connection-www">
						<code><?php echo esc_html( $www_origin ); ?></code>
						<?php if ( $www_allowed ) : ?>
							<span class="pixonaut-www-allowed"><?php /* translators: Status on the connection card: the www. address is allowed. */ esc_html_e( 'Allowed', 'pixonaut-for-woocommerce' ); ?></span>
							<a class="button-link" href="<?php echo esc_url( add_query_arg( 'allow', '0', $www_url ) ); ?>"><?php /* translators: Link on the connection card that stops allowing the www. address. */ esc_html_e( 'Take away', 'pixonaut-for-woocommerce' ); ?></a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'allow', '1', $www_url ) ); ?>"><?php /* translators: Button on the connection card that allows the www. address; here Allow is a verb. */ esc_html_e( 'Allow', 'pixonaut-for-woocommerce' ); ?></a>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Allow it when the store also answers on this address, so personalised products load there too.', 'pixonaut-for-woocommerce' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( '' !== $ping_url ) : ?>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: the state of Pixonaut's event deliveries to the store. */ esc_html_e( 'Webhooks', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-connection-webhook">
						<?php if ( null !== $webhook && ! $webhook['active'] ) : ?>
							<span class="pixonaut-webhook-state" data-state="inactive"><?php /* translators: Status on the connection card: Pixonaut's webhook endpoint for the store is off. */ esc_html_e( 'Switched off', 'pixonaut-for-woocommerce' ); ?></span>
						<?php elseif ( ! $webhook_reachable ) : ?>
							<span class="pixonaut-webhook-state" data-state="unreachable"><?php /* translators: Status on the connection card: Pixonaut cannot reach the store's address, so it sends no webhooks. */ esc_html_e( 'Cannot reach the store', 'pixonaut-for-woocommerce' ); ?></span>
						<?php elseif ( null === $webhook ) : ?>
							<span class="pixonaut-webhook-state" data-state="unknown"><?php esc_html_e( 'Pixonaut did not say how deliveries are going.', 'pixonaut-for-woocommerce' ); ?></span>
						<?php elseif ( $webhook['failing'] ) : ?>
							<span class="pixonaut-webhook-state" data-state="failing">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of webhook deliveries from Pixonaut that failed. Shown on the connection card. */
										_n( 'Failing: %d delivery failed', 'Failing: %d deliveries failed', $webhook['failed_count'], 'pixonaut-for-woocommerce' ),
										$webhook['failed_count']
									)
								);
								?>
							</span>
						<?php else : ?>
							<span class="pixonaut-webhook-state" data-state="active"><?php /* translators: Status on the connection card: the store's webhook endpoint is on and healthy. */ esc_html_e( 'Receiving events', 'pixonaut-for-woocommerce' ); ?></span>
						<?php endif; ?>
						<?php if ( null !== $last_ping ) : ?>
							<p class="pixonaut-webhook-ping" data-delivered="<?php echo $last_ping['delivered'] ? 'true' : 'false'; ?>">
								<?php
								echo esc_html(
									$last_ping['delivered']
										/* translators: %s: how long ago the test request was sent, e.g. 5 mins. Shown on the connection card. */
										? sprintf( __( 'Test request delivered %s ago.', 'pixonaut-for-woocommerce' ), human_time_diff( $last_ping['at'] ) )
										/* translators: 1: how long ago the test request was sent, e.g. 5 mins, 2: why it was not delivered. Shown on the connection card. */
										: sprintf( __( 'Test request not delivered %1$s ago: %2$s', 'pixonaut-for-woocommerce' ), human_time_diff( $last_ping['at'] ), $last_ping['error'] )
								);
								?>
							</p>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( $ping_url ); ?>"><?php /* translators: Button on the connection card: ask Pixonaut to send the store a test webhook. */ esc_html_e( 'Send test request', 'pixonaut-for-woocommerce' ); ?></a>
					</td>
				</tr>
			<?php endif; ?>
			<?php if ( null !== $store_access && '' !== $store_access['url'] ) : ?>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: Pixel, Pixonaut's assistant, and whether it may act on the store. */ esc_html_e( 'Pixel', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-store-access">
						<form method="post" action="<?php echo esc_url( $store_access['url'] ); ?>" class="pixonaut-switch-row">
							<button type="submit" role="switch" class="pixonaut-switch" name="store_access" value="<?php echo $store_access['on'] ? '0' : '1'; ?>" aria-checked="<?php echo $store_access['on'] ? 'true' : 'false'; ?>" aria-labelledby="pixonaut-store-access-title"></button>
							<div>
								<strong id="pixonaut-store-access-title"><?php echo esc_html( $pixonaut_store_access_title ); ?></strong>
								<p class="description">
									<?php
									if ( $store_access['on'] ) {
										esc_html_e( 'On. Pixel in the Pixonaut studio can read and change products, variations, attributes, categories and tags, link templates to products, and read orders. Nothing else in WordPress. Turning it off cuts its access at once.', 'pixonaut-for-woocommerce' );
									} elseif ( $store_access['cut'] ) {
										esc_html_e( 'Off, turned off here. Pixonaut cannot turn it back on: only this switch can.', 'pixonaut-for-woocommerce' );
										echo ' ' . esc_html( $pixonaut_store_access_scope );
									} else {
										echo esc_html( $pixonaut_store_access_scope );
									}
									?>
								</p>
							</div>
						</form>
						<p class="description">
							<?php esc_html_e( 'You chat with Pixel in the Pixonaut studio.', 'pixonaut-for-woocommerce' ); ?>
							<a href="<?php echo esc_url( $studio_url ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link on the connection card to the Pixonaut studio, where the merchant chats with Pixel, Pixonaut's assistant; Pixel is a name, keep it as it is. */ esc_html_e( 'Open the studio', 'pixonaut-for-woocommerce' ); ?></a>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php if ( null !== $workspace_settings ) : ?>
			<h3><?php /* translators: Heading on the connection card of the settings that live in the Pixonaut workspace. */ esc_html_e( 'Workspace settings', 'pixonaut-for-woocommerce' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Set in the Pixonaut studio, except the brand and the print files, which you choose here. They shape the pages Pixonaut shows your customers, and the orders it places.', 'pixonaut-for-woocommerce' ); ?>
				<a href="<?php echo esc_url( $studio_url ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link to the Pixonaut studio, where the workspace settings are changed. */ esc_html_e( 'Change them in the studio', 'pixonaut-for-woocommerce' ); ?></a>
			</p>
			<table class="form-table pixonaut-workspace-settings">
				<?php if ( array() !== $workspace_settings['brands'] ) : ?>
					<tr>
						<th scope="row"><label for="pixonaut-brand"><?php /* translators: Row heading on the connection card: the workspace brand the store's customer-facing Pixonaut pages are drawn in. */ esc_html_e( 'Brand', 'pixonaut-for-woocommerce' ); ?></label></th>
						<td class="pixonaut-workspace-brand">
							<form method="post" action="<?php echo esc_url( $brand_url ); ?>">
								<select id="pixonaut-brand" name="brand">
									<?php foreach ( $workspace_settings['brands'] as $pixonaut_brand ) : ?>
										<?php // The default brand's option follows the default, unless the store chose that brand itself, which saving keeps. ?>
										<option value="<?php echo esc_attr( $pixonaut_brand['is_default'] && $pixonaut_brand['id'] !== $workspace_settings['chosen_brand_id'] ? '' : $pixonaut_brand['id'] ); ?>"<?php selected( $pixonaut_brand['id'], $workspace_settings['brand_id'] ); ?>>
											<?php
											echo esc_html(
												$pixonaut_brand['is_default']
													/* translators: %s: the name of a brand in the Pixonaut workspace. Option on the connection card for the brand every store without one of its own is drawn in. */
													? sprintf( __( '%s (workspace default)', 'pixonaut-for-woocommerce' ), $pixonaut_brand['name'] )
													: $pixonaut_brand['name']
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( count( $workspace_settings['brands'] ) > 1 && '' !== $brand_url ) : ?>
									<button type="submit" class="button"><?php /* translators: Button on the connection card that saves the brand chosen for the store. */ esc_html_e( 'Save brand', 'pixonaut-for-woocommerce' ); ?></button>
								<?php endif; ?>
							</form>
							<p class="description"><?php esc_html_e( 'The logo and colour of the proof, correction and designer pages Pixonaut shows your customers. A link keeps the brand it was made in.', 'pixonaut-for-woocommerce' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: the logo and accent colour of the store's brand. */ esc_html_e( 'Branding', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-workspace-branding">
						<?php if ( null !== $workspace_settings['logo_url'] ) : ?>
							<img src="<?php echo esc_url( $workspace_settings['logo_url'] ); ?>" alt="<?php /* translators: Alt text of the workspace's logo image on the connection card. */ esc_attr_e( 'Workspace logo', 'pixonaut-for-woocommerce' ); ?>" style="max-height: 32px; vertical-align: middle;" />
						<?php else : ?>
							<span><?php /* translators: Shown on the connection card when the workspace has no logo. */ esc_html_e( 'No logo', 'pixonaut-for-woocommerce' ); ?></span>
						<?php endif; ?>
						<?php if ( null !== $workspace_settings['accent'] ) : ?>
							<span class="pixonaut-workspace-accent"><span style="display: inline-block; width: 1em; height: 1em; vertical-align: middle; border: 1px solid #c3c4c7; background: <?php echo esc_attr( $workspace_settings['accent'] ); ?>;"></span> <code><?php echo esc_html( $workspace_settings['accent'] ); ?></code></span>
						<?php else : ?>
							<span><?php /* translators: Shown on the connection card when the workspace has no accent colour. */ esc_html_e( 'No accent colour', 'pixonaut-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: whether proofs carry a watermark. */ esc_html_e( 'Proof watermark', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-workspace-watermark">
						<?php
						echo esc_html(
							null === $workspace_settings['watermark']
								? /* translators: Value on the connection card when Pixonaut did not say. */ __( 'Unknown', 'pixonaut-for-woocommerce' )
								: ( $workspace_settings['watermark'] ? __( 'On: proofs carry a watermark', 'pixonaut-for-woocommerce' ) : /* translators: Value on the connection card: the proof watermark is off. */ __( 'Off', 'pixonaut-for-woocommerce' ) )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: the lowest print resolution Pixonaut accepts, in dots per inch. */ esc_html_e( 'Minimum resolution', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-workspace-resolution">
						<?php
						echo esc_html(
							null === $workspace_settings['min_print_dpi']
								? /* translators: Value on the connection card when Pixonaut did not say. */ __( 'Unknown', 'pixonaut-for-woocommerce' )
								/* translators: %s: the minimum print resolution in dots per inch, e.g. 150. Shown on the connection card. */
								: sprintf( __( '%s dpi, unless a template sets its own', 'pixonaut-for-woocommerce' ), number_format_i18n( $workspace_settings['min_print_dpi'] ) )
						);
						?>
					</td>
				</tr>
				<?php if ( isset( $workspace_settings['prints_with'] ) ) : ?>
					<?php
					$pixonaut_print_forms = Pixonaut\WooCommerce\Connection\PrintFileForm::forms();
					$pixonaut_print_form  = $workspace_settings['print_file_form'] ?? null;
					$pixonaut_tolerance   = $workspace_settings['print_file_tolerance'] ?? null;
					$pixonaut_live_text   = Pixonaut\WooCommerce\Connection\PrintFileForm::LAYERED_LIVE_TEXT;
					if ( null !== $pixonaut_print_form && ! isset( $pixonaut_print_forms[ $pixonaut_print_form ] ) ) {
						// A form Pixonaut added after this release: kept on saving.
						$pixonaut_print_forms[ $pixonaut_print_form ] = $pixonaut_print_form;
					}
					?>
					<tr>
						<th scope="row"><label for="pixonaut-print-file-form"><?php /* translators: Row heading on the connection card: the form of the print files Pixonaut makes of the store's designs. */ esc_html_e( 'Print files', 'pixonaut-for-woocommerce' ); ?></label></th>
						<td class="pixonaut-workspace-print-files">
							<form id="pixonaut-print-file" method="post" action="<?php echo esc_url( $print_file_url ); ?>">
								<select id="pixonaut-print-file-form" name="print_file_form">
									<option value=""<?php selected( null, $pixonaut_print_form ); ?>>
										<?php
										echo esc_html(
											null === $pixonaut_print_form
												/* translators: %s: the print file form the workspace sets, e.g. Flat. Option on the connection card. */
												? sprintf( __( 'Follow the workspace (%s)', 'pixonaut-for-woocommerce' ), $workspace_settings['prints_with']['form'] )
												/* translators: Option on the connection card: print files take the form the Pixonaut workspace sets. */
												: __( 'Follow the workspace', 'pixonaut-for-woocommerce' )
										);
										?>
									</option>
									<?php foreach ( $pixonaut_print_forms as $pixonaut_form_value => $pixonaut_form_name ) : ?>
										<option value="<?php echo esc_attr( $pixonaut_form_value ); ?>"<?php selected( $pixonaut_form_value, $pixonaut_print_form ); ?>><?php echo esc_html( $pixonaut_form_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</form>
							<p class="description pixonaut-prints-with">
								<?php
								echo esc_html(
									$workspace_settings['prints_with']['live_text']
										/* translators: 1: a print file form, e.g. Layers with live text, 2: a live text tolerance, e.g. 1 px. Shown on the connection card. */
										? sprintf( __( 'Print files are made as: %1$s, within %2$s at text edges.', 'pixonaut-for-woocommerce' ), $workspace_settings['prints_with']['form'], $workspace_settings['prints_with']['tolerance'] )
										/* translators: %s: a print file form, e.g. Flat. Shown on the connection card. */
										: sprintf( __( 'Print files are made as: %s.', 'pixonaut-for-woocommerce' ), $workspace_settings['prints_with']['form'] )
								);
								?>
								<?php esc_html_e( 'A template that sets its own form keeps it.', 'pixonaut-for-woocommerce' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'Flat print files and layers with text as shapes print exactly alike. Live text prints the same outside text, and within the tolerance at text edges. A print file edited after Pixonaut made it carries no guarantee.', 'pixonaut-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr class="<?php echo esc_attr( $pixonaut_live_text === $pixonaut_print_form ? '' : Pixonaut\WooCommerce\Settings\SettingsPage::HIDDEN_ROW ); ?>">
						<th scope="row"><label for="pixonaut-print-file-tolerance"><?php /* translators: Row heading on the connection card: how far live text in a print file may lie from its outline. */ esc_html_e( 'Live text tolerance', 'pixonaut-for-woocommerce' ); ?></label></th>
						<td class="pixonaut-workspace-tolerance">
							<input type="number" id="pixonaut-print-file-tolerance" name="print_file_tolerance" form="pixonaut-print-file" class="small-text" min="<?php echo esc_attr( (string) Pixonaut\WooCommerce\Connection\PrintFileForm::TOLERANCE_LIMITS[ Pixonaut\WooCommerce\Connection\PrintFileForm::MM ]['min'] ); ?>" max="<?php echo esc_attr( (string) Pixonaut\WooCommerce\Connection\PrintFileForm::TOLERANCE_LIMITS[ Pixonaut\WooCommerce\Connection\PrintFileForm::PX ]['max'] ); ?>" step="any" value="<?php echo esc_attr( null === $pixonaut_tolerance ? '' : (string) $pixonaut_tolerance['value'] ); ?>" data-pixonaut-depends="pixonaut-print-file-form" data-pixonaut-when-equals="<?php echo esc_attr( $pixonaut_live_text ); ?>" />
							<select name="print_file_tolerance_unit" form="pixonaut-print-file" aria-label="<?php /* translators: Accessible name of the unit menu beside the live text tolerance on the connection card. */ esc_attr_e( 'Unit', 'pixonaut-for-woocommerce' ); ?>">
								<?php foreach ( array_keys( Pixonaut\WooCommerce\Connection\PrintFileForm::TOLERANCE_LIMITS ) as $pixonaut_unit ) : ?>
									<option value="<?php echo esc_attr( $pixonaut_unit ); ?>"<?php selected( $pixonaut_unit, $pixonaut_tolerance['unit'] ?? Pixonaut\WooCommerce\Connection\PrintFileForm::PX ); ?>><?php echo esc_html( $pixonaut_unit ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								echo esc_html(
									null === $pixonaut_tolerance
										/* translators: 1: the limits of a live text tolerance, e.g. 1 to 100 px, or 0.01 to 5 mm, 2: the tolerance the workspace sets, e.g. 1 px. Shown on the connection card. */
										? sprintf( __( '%1$s. Empty follows the workspace: %2$s.', 'pixonaut-for-woocommerce' ), Pixonaut\WooCommerce\Connection\PrintFileForm::tolerance_limits(), $workspace_settings['prints_with']['tolerance'] )
										/* translators: %s: the limits of a live text tolerance, e.g. 1 to 100 px, or 0.01 to 5 mm. Shown on the connection card. */
										: sprintf( __( '%s. Empty follows the workspace.', 'pixonaut-for-woocommerce' ), Pixonaut\WooCommerce\Connection\PrintFileForm::tolerance_limits() )
								);
								?>
							</p>
						</td>
					</tr>
					<?php if ( '' !== $print_file_url ) : ?>
						<tr>
							<th scope="row"></th>
							<td><button type="submit" class="button" form="pixonaut-print-file"><?php /* translators: Button on the connection card that saves the print file form chosen for the store. */ esc_html_e( 'Save print files', 'pixonaut-for-woocommerce' ); ?></button></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: the language the Pixonaut workspace's customer-facing pages use unless told otherwise. */ esc_html_e( 'Default language', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-workspace-language">
						<?php
						/* translators: Shown on the connection card when the store has not read the workspace's default language. */
						echo esc_html( $workspace_settings['default_language'] ?? __( 'Unknown', 'pixonaut-for-woocommerce' ) );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php /* translators: Row heading on the connection card: where the printshop ships orders. */ esc_html_e( 'Delivery address', 'pixonaut-for-woocommerce' ); ?></th>
					<td class="pixonaut-workspace-delivery">
						<?php
						echo esc_html(
							null === $workspace_settings['deliver_to']
								? __( 'Each order ships to its customer', 'pixonaut-for-woocommerce' )
								/* translators: %s: the name of a delivery address saved in Pixonaut, e.g. Warehouse. Shown on the connection card. */
								: sprintf( __( 'Every order ships to %s instead of its customer', 'pixonaut-for-woocommerce' ), $workspace_settings['deliver_to'] )
						);
						?>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<?php if ( ! $webhook_reachable ) : ?>
			<div class="notice notice-warning inline pixonaut-webhook-unreachable">
				<p><?php esc_html_e( 'Pixonaut cannot reach this store at its address, so it sends the store no webhooks. Order and design updates still arrive: the store reads them from Pixonaut every 15 minutes.', 'pixonaut-for-woocommerce' ); ?></p>
				<?php if ( null !== $webhook && '' !== $webhook['url'] ) : ?>
					<p>
						<?php /* translators: Label on the connection card, followed by the address Pixonaut sends webhooks to. */ esc_html_e( 'Webhook address:', 'pixonaut-for-woocommerce' ); ?>
						<code><?php echo esc_html( $webhook['url'] ); ?></code>
					</p>
				<?php endif; ?>
				<p>
					<?php esc_html_e( 'On a sandbox connection you can point it at a tunnel to a local shop: change the address under Webhooks in the Pixonaut studio.', 'pixonaut-for-woocommerce' ); ?>
					<a href="<?php echo esc_url( $tunnel_docs_url ); ?>"><?php esc_html_e( 'Developing locally with a tunnel', 'pixonaut-for-woocommerce' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( null !== $webhook && ! $webhook['active'] ) : ?>
			<div class="notice notice-warning inline pixonaut-webhook-inactive">
				<p><?php esc_html_e( 'Pixonaut is not sending this store its events: its webhook endpoint is switched off. Order and design updates do not reach the store until it is switched on again under Webhooks in the Pixonaut studio.', 'pixonaut-for-woocommerce' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'paused' === $state && $can_connect ) : ?>
			<?php $pixonaut_connect_links( /* translators: Button on the connection card that starts connecting the store again; here Connect is a verb. */ __( 'Connect again', 'pixonaut-for-woocommerce' ) ); ?>
		<?php endif; ?>

		<p>
			<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>"><?php /* translators: Button on the connection card that disconnects the store from Pixonaut; here Disconnect is a verb. */ esc_html_e( 'Disconnect', 'pixonaut-for-woocommerce' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( $studio_url ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link on the connection card to the Pixonaut studio, Pixonaut's own web app. */ esc_html_e( 'Open the Pixonaut studio', 'pixonaut-for-woocommerce' ); ?></a>
		</p>
	<?php endif; ?>

	<p class="pixonaut-connection-help">
		<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer"><?php /* translators: Link on the connection card to the guide on connecting the store to Pixonaut. */ esc_html_e( 'How connecting works', 'pixonaut-for-woocommerce' ); ?></a>
	</p>
</div>
