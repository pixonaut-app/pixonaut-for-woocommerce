<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\DeveloperTools;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Updates\UpdateChannel;
use ReflectionProperty;
use RuntimeException;
use WC_Admin_Settings;
use WPDieException;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// WooCommerce loads its settings classes on admin screens only.
		require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		unset( $_POST[ Settings::OPTION ], $_POST['save'], $_REQUEST['_wpnonce'], $_GET['page'], $_GET[ SettingsPage::TAB_ARG ], $_GET['pixonaut_connect'] );
		( new ReflectionProperty( WC_Admin_Settings::class, 'errors' ) )->setValue( null, array() );
		( new ReflectionProperty( WC_Admin_Settings::class, 'messages' ) )->setValue( null, array() );
		remove_all_filters( 'wp_redirect' );
		parent::tear_down();
	}

	public function test_the_page_has_the_connection_tab_then_a_tab_per_kind_of_setting(): void {
		$tabs = ( new SettingsPage( $this->settings() ) )->tabs();

		$this->assertSame( array( SettingsPage::CONNECTION, SettingsPage::STOREFRONT, SettingsPage::APPROVAL ), array_slice( $tabs, 0, 3 ) );
		$this->assertSame( array_key_exists( UpdateChannel::SETTING, $this->settings()->section( Setting::GENERAL ) ), in_array( SettingsPage::UPDATES, $tabs, true ), 'Updates shows only with the update channel' );
	}

	public function test_the_connection_tab_shows_the_card_and_the_developer_tools(): void {
		$types = array_column( ( new SettingsPage( $this->settings() ) )->fields( SettingsPage::CONNECTION ), 'type' );

		$this->assertSame( array( ConnectionScreen::FIELD_TYPE, DeveloperTools::FIELD_TYPE ), $types );
	}

	public function test_the_tabs_group_every_setting_under_a_heading_with_its_description_shown(): void {
		$fields = $this->fields();

		$this->assertSame(
			array( 'Product page designer', 'Product images', 'Cart and orders', 'Image resolution', 'Approval', 'Proof by email' ),
			array_slice( array_values( array_map( static fn ( array $field ): string => $field['title'], array_filter( $fields, static fn ( array $field ): bool => 'title' === ( $field['type'] ?? null ) && '' !== $field['title'] ) ) ), 0, 6 )
		);
		$shown = array_column( array_filter( $fields, static fn ( array $field ): bool => str_starts_with( (string) ( $field['id'] ?? '' ), Settings::OPTION . '[' ) ), null, 'id' );
		$keys  = array_map( static fn ( Setting $setting ): string => Settings::OPTION . '[' . $setting->key . ']', array_values( $this->settings()->section( Setting::GENERAL ) ) );
		$this->assertEqualsCanonicalizing( $keys, array_keys( $shown ), 'every general setting is shown once' );
		foreach ( $shown as $field ) {
			$this->assertFalse( $field['desc_tip'], $field['id'] . ' shows its description under it' );
		}
	}

	public function test_the_reminder_intervals_come_after_how_many_reminders_are_sent(): void {
		$ids = array_column( $this->fields(), 'id' );

		$this->assertLessThan(
			array_search( Settings::OPTION . '[' . Settings::PROOF_REMINDER_AFTER_DAYS . ']', $ids, true ),
			array_search( Settings::OPTION . '[' . Settings::PROOF_REMINDERS . ']', $ids, true )
		);
	}

	public function test_the_custom_colour_shows_only_with_a_custom_accent_and_is_required_then(): void {
		$colour = $this->field( Settings::DESIGNER_ACCENT_COLOUR );

		$this->assertSame( 'wc-settings-row-pixonaut-dependent pixonaut-hidden', $colour['row_class'] );
		$this->assertSame( Settings::OPTION . '[' . Settings::DESIGNER_ACCENT . ']', $colour['custom_attributes']['data-pixonaut-depends'] );
		$this->assertSame( Accent::CUSTOM, $colour['custom_attributes']['data-pixonaut-when-equals'] );
		$this->assertStringContainsString( 'enter the colour', $colour['custom_attributes']['data-pixonaut-required'] );

		$this->settings()->set( Settings::DESIGNER_ACCENT, Accent::CUSTOM );
		$this->assertSame( 'wc-settings-row-pixonaut-dependent', $this->field( Settings::DESIGNER_ACCENT_COLOUR )['row_class'] );
	}

	public function test_the_reminder_intervals_show_only_while_reminders_are_sent(): void {
		$this->settings()->set( Settings::PROOF_REMINDERS, 0 );
		$every = $this->field( Settings::PROOF_REMINDER_EVERY_DAYS );

		$this->assertSame( 'wc-settings-row-pixonaut-dependent pixonaut-hidden', $every['row_class'] );
		$this->assertSame( '0', $every['custom_attributes']['data-pixonaut-when-above'] );
		$this->assertSame( 1, $every['custom_attributes']['min'], 'the number field keeps its own limits' );

		$this->settings()->set( Settings::PROOF_REMINDERS, 2 );
		$this->assertSame( 'wc-settings-row-pixonaut-dependent', $this->field( Settings::PROOF_REMINDER_EVERY_DAYS )['row_class'] );
	}

	public function test_saving_a_custom_accent_without_a_colour_keeps_the_colour_and_says_so(): void {
		$this->settings()->set( Settings::DESIGNER_ACCENT_COLOUR, '#1e73be' );

		$this->save(
			array(
				Settings::DESIGNER_ACCENT        => Accent::CUSTOM,
				Settings::DESIGNER_ACCENT_COLOUR => '',
			)
		);

		$this->assertSame( Accent::CUSTOM, $this->settings()->get( Settings::DESIGNER_ACCENT ) );
		$this->assertSame( '#1e73be', $this->settings()->get( Settings::DESIGNER_ACCENT_COLOUR ) );
		$this->assertSame( array( 'Designer accent colour is set to A custom colour: enter the colour, e.g. #1e73be.' ), $this->errors() );
	}

	public function test_saving_a_custom_accent_with_a_colour_says_nothing(): void {
		$this->save(
			array(
				Settings::DESIGNER_ACCENT        => Accent::CUSTOM,
				Settings::DESIGNER_ACCENT_COLOUR => '#AA3300',
			)
		);

		$this->assertSame( '#aa3300', $this->settings()->get( Settings::DESIGNER_ACCENT_COLOUR ) );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_saving_a_tab_leaves_the_other_tabs_settings_as_they_were(): void {
		$this->settings()->set( Settings::PRINT_AREA_FRAME, true );

		$this->save( array( Settings::PROOF_DEADLINE_DAYS => '9' ), SettingsPage::APPROVAL );

		$this->assertSame( 9, $this->settings()->get( Settings::PROOF_DEADLINE_DAYS ) );
		$this->assertTrue( $this->settings()->get( Settings::PRINT_AREA_FRAME ), 'an unticked box on another tab is not saved as off' );
		$this->assertSame( array( 'Your settings have been saved.' ), $this->messages() );
	}

	public function test_the_page_saves_a_posted_tab_with_a_valid_nonce(): void {
		$this->sign_in( 'shop_manager' );
		$this->post( array( Settings::PROOF_DEADLINE_DAYS => '9' ), SettingsPage::APPROVAL, wp_create_nonce( SettingsPage::SAVE_ACTION ) );

		( new SettingsPage( $this->settings() ) )->load();

		$this->assertSame( 9, $this->settings()->get( Settings::PROOF_DEADLINE_DAYS ) );
	}

	public function test_the_page_refuses_a_post_without_a_valid_nonce(): void {
		$this->sign_in( 'shop_manager' );
		$this->post( array( Settings::PROOF_DEADLINE_DAYS => '9' ), SettingsPage::APPROVAL, 'not-a-nonce' );

		$this->assert_refused();
	}

	public function test_the_page_refuses_a_post_from_a_user_who_may_not_manage_woocommerce(): void {
		$this->sign_in( 'editor' );
		$this->post( array( Settings::PROOF_DEADLINE_DAYS => '9' ), SettingsPage::APPROVAL, wp_create_nonce( SettingsPage::SAVE_ACTION ) );

		$this->assert_refused();
	}

	public function test_the_menu_item_opens_the_page_for_users_who_may_manage_woocommerce(): void {
		global $menu;
		$this->sign_in( 'administrator' );

		( new SettingsPage( $this->settings() ) )->add_menu();

		$items = array_column( (array) $menu, null, 2 );
		$this->assertSame( 'manage_woocommerce', $items[ SettingsPage::PAGE ][1] ?? null );
		$this->assertNotFalse( has_action( 'load-' . SettingsPage::HOOK_SUFFIX ) );
	}

	public function test_the_menu_item_shows_the_pixonaut_helmet_in_the_admin_colours(): void {
		global $menu;
		$this->sign_in( 'administrator' );

		( new SettingsPage( $this->settings() ) )->add_menu();

		$icon = array_column( (array) $menu, null, 2 )[ SettingsPage::PAGE ][6] ?? '';
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $icon, 'WordPress repaints only a base64 SVG data URI' );
		$svg = (string) base64_decode( substr( $icon, strlen( 'data:image/svg+xml;base64,' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads the icon back.
		$this->assertStringStartsWith( '<svg', $svg );
		// WordPress repaints fills only: a stroke or `fill="none"` would keep its own colour.
		$this->assertStringNotContainsString( 'stroke', $svg );
		$this->assertStringNotContainsString( 'fill="none"', $svg );
		preg_match_all( '/fill="([^"]+)"/', $svg, $fills );
		$this->assertSame( array( '#1c2b5e' ), array_values( array_unique( $fills[1] ) ), 'one colour, so every shape is repainted alike' );
	}

	public function test_the_old_woocommerce_settings_tab_redirects_to_the_page_with_its_arguments(): void {
		$this->sign_in( 'shop_manager' );
		$_GET['page']             = 'wc-settings';
		$_GET['tab']              = SettingsPage::WC_TAB;
		$_GET['pixonaut_connect'] = 'connected';
		$this->throw_on_redirect();

		try {
			( new SettingsPage( $this->settings() ) )->redirect_wc_tab();
			$this->fail( 'no redirect' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( add_query_arg( 'pixonaut_connect', 'connected', SettingsPage::url( SettingsPage::CONNECTION ) ), $e->getMessage() );
		}
	}

	public function test_the_old_tab_does_not_redirect_users_who_could_not_open_it(): void {
		$this->sign_in( 'editor' );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = SettingsPage::WC_TAB;
		$this->throw_on_redirect();

		( new SettingsPage( $this->settings() ) )->redirect_wc_tab();

		$this->addToAssertionCount( 1 );
	}

	public function test_an_unknown_tab_opens_the_connection_tab(): void {
		$_GET[ SettingsPage::TAB_ARG ] = 'nope';

		$this->assertSame( SettingsPage::CONNECTION, ( new SettingsPage( $this->settings() ) )->current_tab() );
	}

	/**
	 * Every settings tab's fields, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function fields(): array {
		$page = new SettingsPage( $this->settings() );
		return array_merge( ...array_map( array( $page, 'fields' ), array_slice( $page->tabs(), 1 ) ) );
	}

	/**
	 * @param string $role The role of the user signed in.
	 */
	private function sign_in( string $role ): void {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		if ( ! is_int( $id ) ) {
			$this->fail( 'Could not create a user.' );
		}
		wp_set_current_user( $id );
	}

	/**
	 * Posts a tab's form as the browser sends it.
	 *
	 * @param array<string, string> $posted The settings posted, by key.
	 * @param string                $tab    The tab.
	 * @param string                $nonce  The form's nonce.
	 */
	private function post( array $posted, string $tab, string $nonce ): void {
		$_GET[ SettingsPage::TAB_ARG ] = $tab;
		$_POST[ Settings::OPTION ]     = $posted;
		$_POST['save']                 = '1';
		$_REQUEST['_wpnonce']          = $nonce;
	}

	/**
	 * Asserts the posted form stopped with an error and changed nothing.
	 */
	private function assert_refused(): void {
		$before = $this->settings()->get( Settings::PROOF_DEADLINE_DAYS );
		try {
			( new SettingsPage( $this->settings() ) )->load();
			$this->fail( 'the post was saved' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->settings()->get( Settings::PROOF_DEADLINE_DAYS ) );
		}
	}

	private function throw_on_redirect(): void {
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's redirect.
			}
		);
	}

	/**
	 * @param string $key A setting's key.
	 * @return array<string, mixed> Its field.
	 */
	private function field( string $key ): array {
		$fields = array_column( $this->fields(), null, 'id' );
		$field  = $fields[ Settings::OPTION . '[' . $key . ']' ] ?? null;
		if ( ! is_array( $field ) ) {
			$this->fail( 'No field for ' . $key );
		}
		return $field;
	}

	/**
	 * Saves a tab as its form posts it.
	 *
	 * @param array<string, string> $posted The settings posted, by key.
	 * @param string                $tab    The tab.
	 */
	private function save( array $posted, string $tab = SettingsPage::STOREFRONT ): void {
		$_POST[ Settings::OPTION ] = $posted; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		( new SettingsPage( $this->settings() ) )->save( $tab );
	}

	/**
	 * @return list<string> The messages the settings page shows.
	 */
	private function messages(): array {
		return array_values( ( new ReflectionProperty( WC_Admin_Settings::class, 'messages' ) )->getValue() );
	}

	/**
	 * @return list<string> The errors the settings screen shows.
	 */
	private function errors(): array {
		return array_values( ( new ReflectionProperty( WC_Admin_Settings::class, 'errors' ) )->getValue() );
	}

	private function settings(): Settings {
		return Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings();
	}
}
