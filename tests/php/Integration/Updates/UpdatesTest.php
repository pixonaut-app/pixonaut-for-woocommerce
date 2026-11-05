<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Updates;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Support\FakeGitHub;
use Pixonaut\WooCommerce\Updates\GitHubReleases;
use Pixonaut\WooCommerce\Updates\GitHubUpdater;
use Pixonaut\WooCommerce\Updates\UpdateChannel;
use Pixonaut\WooCommerce\Updates\Updates;
use Pixonaut\WooCommerce\Updates\UpgradeNotice;
use Pixonaut\WooCommerce\Vendor\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;
use WP_UnitTestCase;

final class UpdatesTest extends WP_UnitTestCase {

	private const REPOSITORY = 'pixonaut-app/pixonaut-for-woocommerce';

	private const TOKEN = 'github_pat_11TESTTOKEN0123456789abcdef';

	/**
	 * A download host that is not the repository's API.
	 */
	private const ELSEWHERE_HOST = 'downloads.example.test';

	private FakeGitHub $github;

	public function set_up(): void {
		parent::set_up();
		$this->github = ( new FakeGitHub( self::REPOSITORY ) )->start();
	}

	public function tear_down(): void {
		$this->github->stop();
		foreach ( Schema::UPDATE_STATE_OPTIONS as $option ) {
			delete_site_option( $option );
		}
		parent::tear_down();
	}

	public function test_without_a_token_the_public_releases_are_checked_and_the_newer_one_offered(): void {
		$settings = new Settings();
		$this->github->release( '0.2.0' )->release( '0.1.0' );
		$updates = new Updates( $settings, $this->plugin_file(), null, true );

		$updates->register();
		$checker = $updates->updater()?->checker();
		$this->assertNotNull( $checker );
		$this->assertArrayHasKey( UpdateChannel::SETTING, $settings->all() );
		$update = $checker->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.2.0', $update->version );
		$this->assertSame( 'https://github.com/' . self::REPOSITORY . '/releases/download/pixonaut-for-woocommerce-0.2.0.zip', $update->download_url );
		$this->assertNotSame( array(), $this->github->requests );
		foreach ( $this->github->requests as $request ) {
			$this->assertArrayNotHasKey( 'Authorization', $request['headers'], $request['url'] );
		}
	}

	public function test_stable_channel_takes_the_newest_stable_release(): void {
		$this->github->release( '0.3.0-beta.1', true )->release( '0.2.0' )->release( '0.1.0' );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.2.0', $update->version );
		$this->assertSame( 'https://' . FakeGitHub::HOST . '/repos/' . self::REPOSITORY . '/releases/assets/22', $update->download_url );
	}

	public function test_stable_channel_skips_a_beta_github_does_not_mark_as_pre_release(): void {
		$this->github->release( '0.3.0-beta.2' )->release( '0.2.0' );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.2.0', $update->version );
	}

	public function test_beta_channel_takes_the_newest_beta(): void {
		$this->github->release( '0.3.0-beta.1', true )->release( '0.2.0' );

		$update = $this->checker( UpdateChannel::BETA )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.3.0-beta.1', $update->version );
		$this->assertSame( 'https://' . FakeGitHub::HOST . '/repos/' . self::REPOSITORY . '/releases/assets/12', $update->download_url );
	}

	public function test_beta_channel_takes_a_stable_release_newer_than_the_betas(): void {
		$this->github->release( '0.3.0' )->release( '0.3.0-beta.2', true );

		$update = $this->checker( UpdateChannel::BETA )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.3.0', $update->version );
	}

	public function test_only_releases_are_searched_never_tags_or_branches(): void {
		$this->github->release( '0.3.0-beta.1', true );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNull( $update );
		$this->assertSame( array( '/repos/' . self::REPOSITORY . '/releases' ), array_values( array_unique( $this->github->paths() ) ) );
	}

	public function test_token_authenticates_requests_and_is_never_stored(): void {
		global $wpdb;
		$this->github->release( '0.2.0' );
		$checker = $this->checker( UpdateChannel::STABLE );

		$checker->checkForUpdates();

		$credentials = base64_encode( 'pixonaut-app:' . self::TOKEN ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the Basic credentials the checker sends.
		$this->assertNotSame( array(), $this->github->requests );
		foreach ( $this->github->requests as $request ) {
			$this->assertSame( 'Basic ' . $credentials, $request['headers']['Authorization'] ?? null, $request['url'] );
			$this->assertStringNotContainsString( self::TOKEN, $request['url'] );
		}
		$this->assertNotFalse( get_site_option( UpdateChannel::state_option( UpdateChannel::STABLE ) ) );
		foreach ( array( self::TOKEN, $credentials ) as $secret ) {
			$stored = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $secret ) . '%' )
			);
			$this->assertSame( '0', $stored );
		}
	}

	public function test_beta_channel_takes_the_highest_beta_published_before_a_maintenance_release(): void {
		$this->github->release( '0.2.1' )->release( '0.3.0-beta.2', true )->release( '0.2.0' );

		$update = $this->checker( UpdateChannel::BETA )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.3.0-beta.2', $update->version );
	}

	public function test_stable_channel_takes_the_highest_release_published_before_a_maintenance_release(): void {
		$this->github->release( '0.1.1' )->release( '0.2.0' )->release( '0.1.0' );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.2.0', $update->version );
	}

	public function test_a_release_without_its_zip_yet_is_skipped(): void {
		$this->github->release( '0.3.0', false, null, false )->release( '0.2.0' );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( '0.2.0', $update->version );
	}

	public function test_upgrade_notice_comes_from_the_breaking_changes_in_the_release_notes(): void {
		$body = implode(
			"\n",
			array(
				'## [0.3.0](https://github.com/' . self::REPOSITORY . '/compare/v0.2.0...v0.3.0) (2026-10-14)',
				'',
				'',
				'### ⚠ BREAKING CHANGES',
				'',
				'* **settings:** the [API key](https://docs.example.test/key) setting moves',
				'  to the Connection tab ([abc1234](https://github.com/' . self::REPOSITORY . '/commit/abc1234))',
				'* templates are renamed',
				'',
				'### Features',
				'',
				'* a feature',
			)
		);
		$this->github->release( '0.3.0', false, $body );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertSame( 'settings: the API key setting moves to the Connection tab templates are renamed', $update->upgrade_notice );
	}

	public function test_a_release_without_breaking_changes_has_no_upgrade_notice(): void {
		$this->github->release( '0.2.0' );

		$update = $this->checker( UpdateChannel::STABLE )->requestUpdate();

		$this->assertNotNull( $update );
		$this->assertEmpty( $update->upgrade_notice );
	}

	public function test_a_long_upgrade_notice_is_cut_to_the_wordpress_org_limit(): void {
		$notice = GitHubReleases::upgrade_notice( "### ⚠ BREAKING CHANGES\n\n* " . str_repeat( 'a', GitHubReleases::UPGRADE_NOTICE_MAX_LENGTH * 2 ) );

		$this->assertSame( GitHubReleases::UPGRADE_NOTICE_MAX_LENGTH, mb_strlen( $notice ) );
		$this->assertStringEndsWith( '…', $notice );
	}

	public function test_a_stable_checker_never_offers_the_beta_a_beta_checker_found(): void {
		$this->github->release( '0.3.0-beta.1', true );
		$this->checker( UpdateChannel::BETA )->checkForUpdates();

		$stable = $this->checker( UpdateChannel::STABLE );

		$this->assertNotFalse( get_site_option( UpdateChannel::state_option( UpdateChannel::BETA ) ) );
		$this->assertFalse( get_site_option( UpdateChannel::state_option( UpdateChannel::STABLE ) ) );
		$this->assertNull( $stable->getUpdate() );
	}

	public function test_asset_downloads_carry_the_token_and_redirects_to_other_hosts_drop_it(): void {
		$this->checker( UpdateChannel::STABLE );
		$authorization = 'Basic ' . base64_encode( 'pixonaut-app:' . self::TOKEN ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the Basic credentials the checker sends.
		$elsewhere     = array();
		add_filter(
			'pre_http_request',
			static function ( $response, array $args, string $url ) use ( &$elsewhere ) {
				if ( self::ELSEWHERE_HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
					return $response;
				}
				$elsewhere[] = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$asset = 'https://' . FakeGitHub::HOST . '/repos/' . self::REPOSITORY . '/releases/assets/22';
		apply_filters( 'upgrader_pre_download', false, $asset ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, as the upgrader fires it.
		wp_remote_get( $asset );
		wp_remote_get( 'https://' . self::ELSEWHERE_HOST . '/pixonaut-for-woocommerce.zip' );

		$download = end( $this->github->requests );
		$this->assertNotFalse( $download );
		$this->assertSame( $authorization, $download['headers']['Authorization'] ?? null );
		$this->assertSame( 'application/octet-stream', $download['headers']['Accept'] ?? null );
		$this->assertCount( 1, $elsewhere );
		$this->assertArrayNotHasKey( 'Authorization', $elsewhere[0] );

		$location = 'https://objects.githubusercontent.com/github-production-release-asset/22';
		$headers  = array( 'Authorization' => $authorization );
		do_action_ref_array( 'requests-requests.before_redirect', array( &$location, &$headers ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- the Requests library's hook.
		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	public function test_uninstall_removes_the_update_state_and_schedule(): void {
		foreach ( Schema::UPDATE_STATE_OPTIONS as $option ) {
			update_site_option( $option, array( 'lastCheck' => time() ) );
		}
		wp_schedule_event( time(), 'twicedaily', Schema::UPDATE_CHECK_CRON_HOOK );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( $this->plugin_file() ) );
		}
		require dirname( __DIR__, 4 ) . '/uninstall.php';

		foreach ( Schema::UPDATE_STATE_OPTIONS as $option ) {
			$this->assertFalse( get_site_option( $option ) );
		}
		$this->assertFalse( wp_next_scheduled( Schema::UPDATE_CHECK_CRON_HOOK ) );
	}

	private function checker( string $channel ): PluginUpdateChecker {
		$checker = ( new GitHubUpdater( $this->plugin_file(), self::TOKEN, $channel ) )->register();
		$this->assertNotNull( $checker );
		return $checker;
	}

	public function test_major_update_shows_the_warning_with_its_upgrade_notice(): void {
		$notice = new UpgradeNotice( plugin_basename( $this->plugin_file() ), '0.1.0', Templates::for_plugin( $this->plugin_file() ) );

		ob_start();
		$notice->render(
			array(),
			(object) array(
				'new_version'    => '1.0.0',
				'upgrade_notice' => 'Templates moved.',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Version 1.0.0 is a major update', $html );
		$this->assertStringContainsString( 'Templates moved.', $html );
	}

	private function plugin_file(): string {
		return dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php';
	}
}
