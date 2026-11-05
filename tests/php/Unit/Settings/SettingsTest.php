<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use LogicException;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Approval\ProofLink;
use Pixonaut\WooCommerce\Approval\ProofOutcome;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Features;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Settings\Setting;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Storefront\AttributeOptions;
use Pixonaut\WooCommerce\Storefront\Preload;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class SettingsTest extends TestCase {

	use FakeOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\stubTranslationFunctions();
	}

	public function test_defaults(): void {
		$settings = new Settings();

		$this->assertSame( Layout::INLINE, $settings->get( Settings::DEFAULT_LAYOUT ) );
		$this->assertSame( DesignerLanguage::FOLLOW_SITE, $settings->get( Settings::DESIGNER_LANGUAGE ) );
		$this->assertSame( FormLayout::TEMPLATE, $settings->get( Settings::FORM_LAYOUT ) );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $settings->get( Settings::DEFAULT_APPROVAL_MODE ) );
		$this->assertSame( Preload::AUTO, $settings->get( Settings::DESIGNER_PRELOAD ) );
		$this->assertSame( Accent::NONE, $settings->get( Settings::DESIGNER_ACCENT ) );
		$this->assertSame( '', $settings->get( Settings::DESIGNER_ACCENT_COLOUR ) );
		$this->assertFalse( $settings->get( Settings::PRINT_AREA_FRAME ) );
		$this->assertSame( AttributeOptions::THEME, $settings->get( Settings::ATTRIBUTE_OPTIONS ) );
		$this->assertSame( ProofOutcome::DENY, $settings->get( Settings::PROOF_NO_ANSWER ) );
		$this->assertSame( ProofOutcome::NEEDS_ATTENTION, $settings->get( Settings::PROOF_ON_DENY ) );
		$this->assertSame( 2, $settings->get( Settings::PROOF_REMINDER_AFTER_DAYS ) );
		$this->assertSame( 2, $settings->get( Settings::PROOF_REMINDER_EVERY_DAYS ) );
		$this->assertSame( 2, $settings->get( Settings::PROOF_REMINDERS ) );
	}

	public function test_proof_reminders_fit_within_the_longest_deadline(): void {
		$this->options[ Settings::OPTION ] = array(
			Settings::PROOF_REMINDER_AFTER_DAYS => (string) ( ProofLink::MAX_DEADLINE_DAYS + 1 ),
			Settings::PROOF_REMINDERS           => '0',
		);
		$settings                          = new Settings();

		$this->assertSame( ProofOutcome::DEFAULT_REMINDER_AFTER_DAYS, $settings->get( Settings::PROOF_REMINDER_AFTER_DAYS ) );
		$this->assertSame( 0, $settings->get( Settings::PROOF_REMINDERS ) );
	}

	public function test_stores_a_valid_value(): void {
		$settings = new Settings();

		$settings->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );

		$this->assertSame( Layout::BUTTON, $settings->get( Settings::DEFAULT_LAYOUT ) );
		$this->assertSame( array( Settings::DEFAULT_LAYOUT => Layout::BUTTON ), $this->options[ Settings::OPTION ] );
	}

	public function test_invalid_stored_value_reads_as_the_default(): void {
		$this->options[ Settings::OPTION ] = array( Settings::DEFAULT_APPROVAL_MODE => 'telepathy' );

		$this->assertSame( ApprovalMode::ADD_TO_CART, ( new Settings() )->get( Settings::DEFAULT_APPROVAL_MODE ) );
	}

	public function test_the_print_area_frame_reads_as_the_settings_form_saves_it(): void {
		$this->options[ Settings::OPTION ] = array( Settings::PRINT_AREA_FRAME => 'yes' );
		$this->assertTrue( ( new Settings() )->get( Settings::PRINT_AREA_FRAME ) );

		$this->options[ Settings::OPTION ] = array( Settings::PRINT_AREA_FRAME => 'no' );
		$this->assertFalse( ( new Settings() )->get( Settings::PRINT_AREA_FRAME ) );

		$this->options[ Settings::OPTION ] = array( Settings::PRINT_AREA_FRAME => 'maybe' );
		$this->assertFalse( ( new Settings() )->get( Settings::PRINT_AREA_FRAME ) );
	}

	public function test_the_print_area_frame_is_stored_as_the_settings_form_shows_it(): void {
		$settings = new Settings();

		$settings->set( Settings::PRINT_AREA_FRAME, true );
		$this->assertSame( 'yes', $this->options[ Settings::OPTION ][ Settings::PRINT_AREA_FRAME ] );
		$this->assertTrue( $settings->get( Settings::PRINT_AREA_FRAME ) );

		$settings->set( Settings::PRINT_AREA_FRAME, false );
		$this->assertSame( 'no', $this->options[ Settings::OPTION ][ Settings::PRINT_AREA_FRAME ] );
	}

	public function test_an_accent_colour_reads_in_lower_case_and_anything_else_as_none(): void {
		$this->options[ Settings::OPTION ] = array( Settings::DESIGNER_ACCENT_COLOUR => ' #1E73BE ' );
		$this->assertSame( '#1e73be', ( new Settings() )->get( Settings::DESIGNER_ACCENT_COLOUR ) );

		foreach ( array( 'blue', '#1e73b', '#1e73bez', 'rgb(0, 0, 0)' ) as $invalid ) {
			$this->options[ Settings::OPTION ] = array( Settings::DESIGNER_ACCENT_COLOUR => $invalid );
			$this->assertSame( '', ( new Settings() )->get( Settings::DESIGNER_ACCENT_COLOUR ), $invalid );
		}
	}

	public function test_the_custom_accent_is_the_colour_only_while_custom_is_chosen(): void {
		$this->options[ Settings::OPTION ] = array(
			Settings::DESIGNER_ACCENT        => Accent::CUSTOM,
			Settings::DESIGNER_ACCENT_COLOUR => '#1e73be',
		);
		$this->assertSame( '#1e73be', Accent::custom( new Settings() ) );

		$this->options[ Settings::OPTION ][ Settings::DESIGNER_ACCENT ] = Accent::THEME;
		$this->assertNull( Accent::custom( new Settings() ) );
		$this->assertTrue( Accent::follows_theme( new Settings() ) );

		$this->options[ Settings::OPTION ] = array( Settings::DESIGNER_ACCENT => Accent::CUSTOM );
		$this->assertNull( Accent::custom( new Settings() ) );
	}

	public function test_unknown_setting_is_refused(): void {
		$this->expectException( LogicException::class );
		( new Settings() )->get( 'no_such_setting' );
	}

	public function test_registering_a_key_twice_is_refused(): void {
		$settings = new Settings();

		$this->expectException( LogicException::class );
		$settings->register( Setting::select( Settings::DEFAULT_LAYOUT, Layout::INLINE, Layout::available( ... ), static fn (): array => array() ) );
	}

	public function test_select_field_lists_only_allowed_values(): void {
		$setting = Setting::select(
			'colour',
			'red',
			static fn (): array => array( 'red' ),
			static fn (): array => array(
				'title'   => 'Colour',
				'options' => array(
					'red'  => 'Red',
					'blue' => 'Blue',
				),
			)
		);

		$this->assertSame(
			array(
				'type'    => 'select',
				'title'   => 'Colour',
				'options' => array( 'red' => 'Red' ),
			),
			$setting->field()
		);
		$this->assertSame( 'red', $setting->resolve( 'blue' ) );
	}

	public function test_every_layout_and_approval_mode_is_an_enabled_feature(): void {
		foreach ( array_merge( Layout::FEATURES, ApprovalMode::FEATURES ) as $feature ) {
			$this->assertTrue( Features::enabled( $feature ), $feature );
		}
		$this->assertFalse( Features::enabled( 'no_such_feature' ) );
		$this->assertSame( array( Layout::INLINE, Layout::BUTTON ), Layout::available() );
		$this->assertSame( array( ApprovalMode::ADD_TO_CART, ApprovalMode::CHECKBOX, ApprovalMode::PROOF_EMAIL ), ApprovalMode::available() );
	}
}
