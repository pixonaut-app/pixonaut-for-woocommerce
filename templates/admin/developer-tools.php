<?php
/**
 * The hidden developer tools on the settings page's Connection tab.
 *
 * Override by copying to yourtheme/woocommerce/pixonaut/admin/developer-tools.php.
 *
 * @package Pixonaut\WooCommerce
 * @version 0.1.0
 *
 * @var string     $anchor      The section's element id.
 * @var string     $form_id     The form every field and button submits.
 * @var string     $fields_arg  The posted settings' name, as `<fields_arg>[<key>]`.
 * @var string     $reset_arg   The name of a button that resets the setting it names.
 * @var array<int, string> $countdown The notice before the section unlocks, by presses left.
 * @var array<string, \Pixonaut\WooCommerce\Settings\Setting> $settings The section's settings, by key.
 * @var array<string, array{value: string, locked_by: string, editable: bool}> $hosts Each Pixonaut address's override, the constant fixing it (empty when none), and whether the user may change it, by setting.
 * @var array<string, string> $effective Each Pixonaut address in use, by setting.
 * @var string     $sdk_url     The SDK script override; empty when the pinned one loads.
 * @var bool       $sdk_url_editable Whether the user may change the SDK script.
 * @var string     $pinned_sdk  The pinned SDK script.
 * @var bool       $sdk_debug   Whether designer mounts are logged to the console.
 * @var bool       $request_log Whether requests to Pixonaut are recorded.
 * @var list<array<string, mixed>> $requests The requests recorded, newest first.
 * @var array{workspace: string, key_prefix: string, mode: string, paused: string, webhook_url: string, webhook: array{active: bool, failing: bool, failed_count: int}|null, spec: string, sdk: string}|null $connection The connection; null when not connected.
 * @var array<string, string> $actions Action links: pull, ping, recheck, clear.
 * @var list<array{id: int, hook: string, status: string, date: string, url: string}> $jobs The plugin's pending and failed jobs.
 * @var string     $result      What the last action did, as a key; may be empty.
 */

defined( 'ABSPATH' ) || exit;

use Pixonaut\WooCommerce\Settings\Settings;

$pixonaut_results = array(
	'saved'          => array( 'success', __( 'Developer tools saved.', 'pixonaut-for-woocommerce' ) ),
	'invalid_url'    => array( 'error', __( 'An address was not saved: use a full http:// or https:// address.', 'pixonaut-for-woocommerce' ) ),
	'pulled'         => array( 'success', __( 'The event stream was pulled.', 'pixonaut-for-woocommerce' ) ),
	'pull_failed'    => array( 'error', __( 'The event stream was not pulled: Pixonaut refused or could not be reached, or the store is still setting its first cursor. WooCommerce > Status > Logs says which.', 'pixonaut-for-woocommerce' ) ),
	'pinged'         => array( 'success', __( 'Pixonaut was asked to send the store’s endpoint a test webhook. The connection card shows whether it arrived.', 'pixonaut-for-woocommerce' ) ),
	'recheck_queued' => array( 'success', __( 'Every linked product is queued to be re-checked.', 'pixonaut-for-woocommerce' ) ),
	'recheck_failed' => array( 'error', __( 'The re-check could not be queued. Check Action Scheduler under WooCommerce > Status > Scheduled Actions.', 'pixonaut-for-woocommerce' ) ),
	'cleared'        => array( 'success', __( 'The cached catalogue was cleared, and the stored designer links are being withdrawn.', 'pixonaut-for-woocommerce' ) ),
	'not_connected'  => array( 'error', __( 'The store is not connected to Pixonaut, or the connection is paused.', 'pixonaut-for-woocommerce' ) ),
);
$pixonaut_field   = static function ( string $key, string $type, string $value, bool $locked ) use ( $form_id, $fields_arg ): void {
	?>
	<input
		type="<?php echo esc_attr( $type ); ?>"
		class="regular-text"
		id="pixonaut-developer-<?php echo esc_attr( $key ); ?>"
		name="<?php echo esc_attr( $fields_arg . '[' . $key . ']' ); ?>"
		form="<?php echo esc_attr( $form_id ); ?>"
		value="<?php echo esc_attr( $value ); ?>"
		<?php wp_readonly( $locked ); ?>
	>
	<?php
};
?>
<div
	id="<?php echo esc_attr( $anchor ); ?>"
	class="pixonaut-developer-tools"
	data-countdown="<?php echo esc_attr( (string) wp_json_encode( $countdown ) ); ?>"
	hidden
>
	<h2><?php /* translators: Heading of the developer tools section on the settings page, read by developers. */ esc_html_e( 'Developer tools', 'pixonaut-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'For testing the store against a local or staging Pixonaut, and for debugging it. Unlocking only shows this section: everything here is saved and checked on the server.', 'pixonaut-for-woocommerce' ); ?></p>

	<?php if ( isset( $pixonaut_results[ $result ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $pixonaut_results[ $result ][0] ); ?> inline pixonaut-developer-result">
			<p><?php echo esc_html( $pixonaut_results[ $result ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<h3><?php /* translators: Heading of the fields for the addresses the store sends Pixonaut requests to, in developer tools. */ esc_html_e( 'Pixonaut addresses', 'pixonaut-for-woocommerce' ); ?></h3>
	<table class="form-table pixonaut-developer-hosts">
		<?php foreach ( $hosts as $pixonaut_key => $pixonaut_host ) : ?>
			<tr>
				<th scope="row"><label for="pixonaut-developer-<?php echo esc_attr( $pixonaut_key ); ?>"><?php echo esc_html( (string) ( $settings[ $pixonaut_key ]->field()['title'] ?? $pixonaut_key ) ); ?></label></th>
				<td>
					<?php if ( '' !== $pixonaut_host['locked_by'] ) : ?>
						<?php $pixonaut_field( $pixonaut_key, 'url', $effective[ $pixonaut_key ], true ); ?>
						<p class="description pixonaut-developer-locked">
							<?php
							/* translators: %s: name of a wp-config.php constant, e.g. PIXONAUT_WC_API_BASE. Shown to developers in developer tools. */
							echo esc_html( sprintf( __( 'Set by the %s constant in wp-config.php, which wins over this field.', 'pixonaut-for-woocommerce' ), $pixonaut_host['locked_by'] ) );
							?>
						</p>
					<?php else : ?>
						<?php $pixonaut_field( $pixonaut_key, 'url', $pixonaut_host['value'], ! $pixonaut_host['editable'] ); ?>
						<?php if ( $pixonaut_host['editable'] ) : ?>
							<button type="submit" class="button" form="<?php echo esc_attr( $form_id ); ?>" name="<?php echo esc_attr( $reset_arg ); ?>" value="<?php echo esc_attr( $pixonaut_key ); ?>"><?php /* translators: Button in developer tools that restores a field's default value; here Reset is a verb. */ esc_html_e( 'Reset', 'pixonaut-for-woocommerce' ); ?></button>
						<?php else : ?>
							<p class="description pixonaut-developer-locked"><?php esc_html_e( 'Only an administrator can change where the store sends its key.', 'pixonaut-for-woocommerce' ); ?></p>
						<?php endif; ?>
						<p class="description">
							<?php echo esc_html( (string) ( $settings[ $pixonaut_key ]->field()['desc'] ?? '' ) ); ?>
							<?php
							/* translators: %s: the address the store currently sends requests to, e.g. https://api.pixonaut.app. Shown to developers in developer tools. */
							echo esc_html( sprintf( __( 'In use: %s', 'pixonaut-for-woocommerce' ), $effective[ $pixonaut_key ] ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>

	<h3><?php /* translators: Heading of the section for the designer's SDK script, in developer tools. */ esc_html_e( 'Designer SDK', 'pixonaut-for-woocommerce' ); ?></h3>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="pixonaut-developer-<?php echo esc_attr( Settings::SDK_URL ); ?>"><?php /* translators: Label of the field for the SDK script's URL, in developer tools. */ esc_html_e( 'SDK script', 'pixonaut-for-woocommerce' ); ?></label></th>
			<td>
				<?php $pixonaut_field( Settings::SDK_URL, 'url', $sdk_url, ! $sdk_url_editable ); ?>
				<?php if ( $sdk_url_editable ) : ?>
					<button type="submit" class="button" form="<?php echo esc_attr( $form_id ); ?>" name="<?php echo esc_attr( $reset_arg ); ?>" value="<?php echo esc_attr( Settings::SDK_URL ); ?>"><?php /* translators: Button in developer tools that restores a field's default value; here Reset is a verb. */ esc_html_e( 'Reset', 'pixonaut-for-woocommerce' ); ?></button>
				<?php else : ?>
					<p class="description pixonaut-developer-locked"><?php esc_html_e( 'Only an administrator who may add scripts to the site can change the SDK script.', 'pixonaut-for-woocommerce' ); ?></p>
				<?php endif; ?>
				<p class="description">
					<?php echo esc_html( (string) ( $settings[ Settings::SDK_URL ]->field()['desc'] ?? '' ) ); ?>
					<?php
					/* translators: %s: URL of the SDK script this plugin pins. Shown to developers in developer tools. */
					echo esc_html( sprintf( __( 'Pinned: %s', 'pixonaut-for-woocommerce' ), $pinned_sdk ) );
					?>
				</p>
			</td>
		</tr>
		<?php
		foreach ( array(
			Settings::SDK_DEBUG   => $sdk_debug,
			Settings::REQUEST_LOG => $request_log,
		) as $pixonaut_key => $pixonaut_on ) :
			?>
			<tr>
				<th scope="row"><?php echo esc_html( (string) ( $settings[ $pixonaut_key ]->field()['title'] ?? $pixonaut_key ) ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="pixonaut-developer-<?php echo esc_attr( $pixonaut_key ); ?>" name="<?php echo esc_attr( $fields_arg . '[' . $pixonaut_key . ']' ); ?>" form="<?php echo esc_attr( $form_id ); ?>" value="1" <?php checked( $pixonaut_on ); ?>>
						<?php echo esc_html( (string) ( $settings[ $pixonaut_key ]->field()['desc'] ?? '' ) ); ?>
					</label>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
	<p>
		<button type="submit" class="button button-primary" form="<?php echo esc_attr( $form_id ); ?>"><?php /* translators: Button in developer tools that saves the section's fields. */ esc_html_e( 'Save developer tools', 'pixonaut-for-woocommerce' ); ?></button>
	</p>

	<h3><?php /* translators: Heading of the table of recent requests to Pixonaut, in developer tools. */ esc_html_e( 'Request log', 'pixonaut-for-woocommerce' ); ?></h3>
	<?php if ( array() === $requests ) : ?>
		<p class="pixonaut-developer-requests-empty">
			<?php $request_log ? esc_html_e( 'No requests recorded yet.', 'pixonaut-for-woocommerce' ) : esc_html_e( 'The request log is off.', 'pixonaut-for-woocommerce' ); ?>
		</p>
	<?php else : ?>
		<table class="widefat striped pixonaut-developer-requests">
			<thead>
				<tr>
					<th><?php /* translators: Column heading in the request log: the time of the request. */ esc_html_e( 'When', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php /* translators: Column heading in the request log: the Pixonaut API operation called. */ esc_html_e( 'Operation', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php /* translators: Column heading in the request log: the request's URL path. */ esc_html_e( 'Path', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php echo esc_html_x( 'Status', 'HTTP status code of a logged request', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php /* translators: Column heading in the request log: how long the request took. */ esc_html_e( 'Duration', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php /* translators: Column heading in the request log: the id Pixonaut gave the request. */ esc_html_e( 'Request id', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php /* translators: Column heading in the request log: the key that makes a repeated request safe to retry. */ esc_html_e( 'Idempotency key', 'pixonaut-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $requests as $pixonaut_request ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $pixonaut_request['at'] ?? 0 ) ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $pixonaut_request['operation'] ?? '' ) ); ?></code></td>
						<td><code><?php echo esc_html( (string) ( $pixonaut_request['path'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( 0 === ( $pixonaut_request['status'] ?? 0 ) ? (string) ( $pixonaut_request['error'] ?? '' ) : (string) $pixonaut_request['status'] ); ?></td>
						<td>
							<?php
							/* translators: %d: how long a logged request took, in milliseconds. Column of the request log in developer tools. */
							echo esc_html( sprintf( __( '%d ms', 'pixonaut-for-woocommerce' ), (int) ( $pixonaut_request['duration_ms'] ?? 0 ) ) );
							?>
						</td>
						<td><code><?php echo esc_html( (string) ( $pixonaut_request['request_id'] ?? '' ) ); ?></code></td>
						<td><code><?php echo esc_html( (string) ( $pixonaut_request['idempotency_key'] ?? '' ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php /* translators: Heading of the stored connection's technical details, in developer tools. */ esc_html_e( 'Connection details', 'pixonaut-for-woocommerce' ); ?></h3>
	<?php if ( null === $connection ) : ?>
		<p><?php esc_html_e( 'The store is not connected to Pixonaut.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="form-table pixonaut-developer-connection">
			<tr>
				<th scope="row"><?php /* translators: Row heading in developer tools: the Pixonaut workspace the store is connected to. */ esc_html_e( 'Workspace', 'pixonaut-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( $connection['workspace'] ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading in developer tools: the first characters of the store's API key, which identify it. */ esc_html_e( 'Key prefix', 'pixonaut-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( $connection['key_prefix'] ); ?>…</code></td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading in developer tools: live or sandbox. */ esc_html_e( 'Mode', 'pixonaut-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( $connection['mode'] ); ?><?php echo '' === $connection['paused'] ? '' : esc_html( ' (' . /* translators: Added in brackets after the connection mode in developer tools when the connection is paused, followed by the reason. */ __( 'paused', 'pixonaut-for-woocommerce' ) . ': ' . $connection['paused'] . ')' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading in developer tools: the store's endpoint Pixonaut delivers events to. */ esc_html_e( 'Webhook endpoint', 'pixonaut-for-woocommerce' ); ?></th>
				<td>
					<code><?php echo esc_html( $connection['webhook_url'] ); ?></code>
					<br>
					<?php
					if ( null === $connection['webhook'] ) {
						esc_html_e( 'Health unknown: Pixonaut could not say.', 'pixonaut-for-woocommerce' );
					} elseif ( ! $connection['webhook']['active'] ) {
						esc_html_e( 'Disabled in Pixonaut.', 'pixonaut-for-woocommerce' );
					} elseif ( $connection['webhook']['failing'] ) {
						/* translators: %d: number of webhook deliveries from Pixonaut that failed. Shown to developers in developer tools. */
						echo esc_html( sprintf( _n( 'Failing: %d delivery failed.', 'Failing: %d deliveries failed.', $connection['webhook']['failed_count'], 'pixonaut-for-woocommerce' ), $connection['webhook']['failed_count'] ) );
					} else {
						esc_html_e( 'Healthy.', 'pixonaut-for-woocommerce' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading in developer tools: the version of Pixonaut's API spec this plugin was built against. */ esc_html_e( 'Pinned API spec', 'pixonaut-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( $connection['spec'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php /* translators: Row heading in developer tools: the version of Pixonaut's designer SDK this plugin was built against. */ esc_html_e( 'Pinned SDK', 'pixonaut-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( $connection['sdk'] ); ?></code></td>
			</tr>
		</table>
	<?php endif; ?>

	<h3><?php /* translators: Heading of the buttons that run developer actions, in developer tools. */ esc_html_e( 'Actions', 'pixonaut-for-woocommerce' ); ?></h3>
	<p class="pixonaut-developer-actions">
		<a class="button" href="<?php echo esc_url( $actions['pull'] ); ?>"><?php esc_html_e( 'Pull the event stream now', 'pixonaut-for-woocommerce' ); ?></a>
		<a class="button" href="<?php echo esc_url( $actions['ping'] ); ?>"><?php esc_html_e( 'Send a test webhook', 'pixonaut-for-woocommerce' ); ?></a>
		<a class="button" href="<?php echo esc_url( $actions['recheck'] ); ?>"><?php esc_html_e( 'Re-check every linked product', 'pixonaut-for-woocommerce' ); ?></a>
		<a class="button" href="<?php echo esc_url( $actions['clear'] ); ?>"><?php esc_html_e( 'Clear cached catalogue and designer links', 'pixonaut-for-woocommerce' ); ?></a>
	</p>

	<h3><?php /* translators: Heading of the table of background jobs that wait or failed, in developer tools. */ esc_html_e( 'Pending and failed jobs', 'pixonaut-for-woocommerce' ); ?></h3>
	<?php if ( array() === $jobs ) : ?>
		<p><?php esc_html_e( 'No pending or failed jobs.', 'pixonaut-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="widefat striped pixonaut-developer-jobs">
			<thead>
				<tr>
					<th><?php /* translators: Column heading in the jobs table: the name of a background job. */ esc_html_e( 'Job', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php echo esc_html_x( 'Status', 'status of a background job, e.g. pending or failed', 'pixonaut-for-woocommerce' ); ?></th>
					<th><?php /* translators: Column heading in the jobs table: when the job is scheduled to run. */ esc_html_e( 'Scheduled', 'pixonaut-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $jobs as $pixonaut_job ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $pixonaut_job['url'] ); ?>"><code><?php echo esc_html( $pixonaut_job['hook'] ); ?></code> #<?php echo esc_html( (string) $pixonaut_job['id'] ); ?></a></td>
						<td><?php echo esc_html( $pixonaut_job['status'] ); ?></td>
						<td><?php echo esc_html( $pixonaut_job['date'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p>
		<button type="button" class="button-link pixonaut-developer-hide"><?php /* translators: Button that hides the developer tools section again. */ esc_html_e( 'Hide developer tools', 'pixonaut-for-woocommerce' ); ?></button>
	</p>
</div>
