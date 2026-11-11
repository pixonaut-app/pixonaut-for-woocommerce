<?php
/**
 * The settings registry.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Settings;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use LogicException;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Approval\ProofLink;
use Pixonaut\WooCommerce\Approval\ProofOutcome;
use Pixonaut\WooCommerce\Cart\PictureView;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Storefront\AttributeOptions;
use Pixonaut\WooCommerce\Storefront\Preload;

/**
 * Every setting the plugin has, stored together in one option. Reads always
 * pass through the setting's definition, so a missing or no longer valid value
 * reads as the default.
 */
final class Settings {

	public const OPTION = Schema::SETTINGS_OPTION;

	public const DEFAULT_LAYOUT = 'default_layout';

	public const DESIGNER_LANGUAGE = 'designer_language';

	public const FORM_LAYOUT = 'form_layout';

	public const OPTIONS_AS_GROUPS = 'options_as_groups';

	public const OPTION_GROUPS_POSITION = 'option_groups_position';

	public const GROUP_NUMBERS = 'group_numbers';

	public const DESIGNER_PRELOAD = 'designer_preload';

	public const DESIGNER_ACCENT = 'designer_accent';

	public const DESIGNER_ACCENT_COLOUR = 'designer_accent_colour';

	public const PRINT_AREA_FRAME = 'print_area_frame';

	public const ADD_AFTER_APPROVE = 'add_after_approve';

	public const ATTRIBUTE_OPTIONS = 'attribute_options';

	public const SWATCH_NAMES = 'swatch_names';

	public const PRODUCT_PICTURES = 'product_pictures';

	public const SHOW_FIELD_VALUES = 'show_field_values';

	public const LOW_RESOLUTION_WARNINGS = 'low_resolution_warnings';

	public const LOW_RESOLUTION_QUIET_IMAGES = 'low_resolution_quiet_images';

	public const LINE_PICTURE_VIEW = 'line_picture_view';

	public const DEFAULT_APPROVAL_MODE = 'default_approval_mode';

	public const PROOF_DEADLINE_DAYS = 'proof_deadline_days';

	public const PROOF_NO_ANSWER = 'proof_no_answer';

	public const PROOF_ON_DENY = 'proof_on_deny';

	public const PROOF_REMINDER_AFTER_DAYS = 'proof_reminder_after_days';

	public const PROOF_REMINDER_EVERY_DAYS = 'proof_reminder_every_days';

	public const PROOF_REMINDERS = 'proof_reminders';

	public const API_BASE = 'api_base';

	public const SERVER_API_BASE = 'server_api_base';

	public const STUDIO_BASE = 'studio_base';

	public const CDN_BASE = 'cdn_base';

	public const SDK_URL = 'sdk_url';

	public const SDK_DEBUG = 'sdk_debug';

	public const REQUEST_LOG = 'request_log';

	/**
	 * @var array<string, Setting>
	 */
	private array $settings = array();

	public function __construct() {
		$this->register(
			Setting::select(
				self::DEFAULT_LAYOUT,
				Layout::INLINE,
				Layout::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Default layout', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'How personalised products show the designer, unless a product overrides it.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => Layout::names(),
				)
			)
		);
		$this->register(
			Setting::select(
				self::DESIGNER_LANGUAGE,
				DesignerLanguage::FOLLOW_SITE,
				DesignerLanguage::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Designer language', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'The language of the designer on the product page, of Pixonaut’s designer the Personalise button opens, and of the proof pages customers approve their designs on. Following the site shows each visitor the site’s language they browse in when Pixonaut has it, and the workspace’s default language when it does not.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => DesignerLanguage::names(),
				)
			)
		);
		$this->register(
			Setting::select(
				self::FORM_LAYOUT,
				FormLayout::TEMPLATE,
				FormLayout::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Form layout', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'How the designer lays out its form, on the product page and in Pixonaut’s designer the Personalise button opens, unless a product overrides it: as the template chose, every step on one page, or one step at a time.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => FormLayout::names(),
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::OPTIONS_AS_GROUPS,
				false,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: each product option, such as Size or Colour, gets a group of its own in the designer's form. */
					'title' => __( 'As its own group', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Puts each of a product\'s options, such as Size or Colour, in a group of its own in the designer\'s form on the product page, named after the option, in the product\'s attribute order. Off, the options stay on top of the form, unnumbered. A product whose groups were arranged by hand keeps them.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::select(
				self::OPTION_GROUPS_POSITION,
				OptionGroups::FIRST,
				OptionGroups::positions( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: whether the groups of a product's options come before or after the template's groups. */
					'title'    => __( 'Place of the option groups', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'Whether the groups of a product\'s options come before or after the template\'s groups.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => OptionGroups::position_names(),
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::GROUP_NUMBERS,
				true,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: whether the designer's form numbers its groups. */
					'title' => __( 'Group numbers', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Numbers the groups of the designer\'s form on the product page: 1 Photo, 2 Text on one page, Step 1 of 2 in steps. Off shows the group names with no numbers.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::select(
				self::DESIGNER_PRELOAD,
				Preload::AUTO,
				Preload::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Load other colours and views', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'Whether the designer loads a product\'s other colours, views and sides before the customer picks one, so they show at once. Choose In the background or Off for products with many large photos, or for customers mostly on phones.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => Preload::names(),
				)
			)
		);
		$this->register(
			Setting::select(
				self::DESIGNER_ACCENT,
				Accent::NONE,
				Accent::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Designer accent colour', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'The colour the designer on the product page marks the chosen view, colour and option in, and draws its Next button in. None takes the accent colour of the store\'s brand in Pixonaut, and keeps the designer\'s own colours when the brand has none.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => Accent::names(),
				)
			)
		);
		$this->register(
			Setting::colour(
				self::DESIGNER_ACCENT_COLOUR,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Custom accent colour', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'The accent colour when Designer accent colour is A custom colour, e.g. #1e73be.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::PRINT_AREA_FRAME,
				false,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title' => __( 'Show the print area\'s frame', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Draws a thin grey frame on the preview around where the design can go, in the designer on the product page and in Pixonaut\'s designer the Personalise button opens. Off, the design sits on the product photo as it will be printed.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::ADD_AFTER_APPROVE,
				false,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. "Hosted page" is Pixonaut's designer page the Personalise button opens. */
					'title' => __( 'Add to cart after approve in hosted page', 'pixonaut-for-woocommerce' ),
					/* translators: Help text of a setting on the settings page. "Approve and add to cart" and "Approve and go back" are the labels of the button on Pixonaut's designer page. */
					'desc'  => __( 'For the Personalise button layout. Approving adds the product to the cart and opens its cart item\'s page, and Pixonaut\'s designer says Approve and add to cart. Off, approving returns to the product page, and it says Approve and go back.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::select(
				self::ATTRIBUTE_OPTIONS,
				AttributeOptions::THEME,
				AttributeOptions::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: how the options of a product option such as a size show in the designer's form. */
					'title'    => __( 'Product options in the designer', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'How the designer on the product page shows the options of a variable product, such as its sizes, when it draws them in its form. As the theme shows them (the default) keeps the theme\'s own field, and any swatches another plugin draws in it. As swatches shows each option as a button like the designer\'s own choices, an option that cannot be bought with the other choices greyed out.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => AttributeOptions::names(),
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::SWATCH_NAMES,
				true,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: whether a product option's swatches drawn as a colour or a picture show the option's name beside them. */
					'title' => __( 'Show value names beside swatches', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'With product options shown as swatches, a value Pixonaut draws as a colour or a picture shows its name beside it. Off, the swatch shows the colour or picture alone; its name is still read out by screen readers, shown when the swatch is pointed at, and the chosen value\'s name shows beside the option\'s name. A value without a colour or picture always shows its name. Each global attribute can show or hide them whatever this says, on its edit screen under Products, Attributes.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::PRODUCT_PICTURES,
				true,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title' => __( 'Use Pixonaut pictures as product images', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Sets each personalised product’s and variation’s image to Pixonaut’s picture of it, its template on the blank, and draws it again when the product changes. A product can show its template’s showcase sets instead, chosen under Product images in its Pixonaut tab. An image you set yourself is kept. Off, each product gets back the image it had before.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::checkbox(
				self::LOW_RESOLUTION_WARNINGS,
				true,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: whether a product's Pixonaut tab warns when a print size makes one of its template's images print below its recommended resolution. */
					'title' => __( 'Warn about soft images', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Warns in a product\'s Pixonaut tab when one of its print sizes makes an image in its template print below the resolution Pixonaut recommends, so it may look soft or pixelated. Off, the tab says nothing of it. A size the Pixonaut workspace refuses for it is still refused and says why: whether Pixonaut warns, refuses or says nothing is chosen in the workspace\'s settings in the Pixonaut studio.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::multiselect(
				self::LOW_RESOLUTION_QUIET_IMAGES,
				static fn ( string $asset_id ): bool => '' !== trim( $asset_id ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: the images a product's Pixonaut tab no longer warns about. */
					'title' => __( 'Images not warned about', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'The images you chose not to be warned about again in a product\'s Pixonaut tab, by their Pixonaut id. Remove one to be warned about it again.', 'pixonaut-for-woocommerce' ),
				),
				fn (): array => array_combine( $this->quiet_images(), $this->quiet_images() )
			)
		);
		$this->register(
			Setting::checkbox(
				self::SHOW_FIELD_VALUES,
				false,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant; the fields are the ones the customer filled in to personalise the item. */
					'title' => __( 'List the design\'s fields', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Lists what the customer filled in under a personalised item in the cart, the checkout, their orders and the order emails, as the designer showed it. Off, the item shows only its name. The order screen in the admin always lists them.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::select(
				self::LINE_PICTURE_VIEW,
				PictureView::PREVIEWED,
				PictureView::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant: which view of the product the picture of a personalised item shows. */
					'title'    => __( 'Picture shows', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'A personalised item shows the customer’s design on the product in the cart, the checkout, their orders and the order emails. This chooses the view of the product it is drawn in: the one the customer previewed their design in, or the first of the placement’s views the product is shown in.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => PictureView::names(),
				)
			)
		);
		$this->register(
			Setting::select(
				self::DEFAULT_APPROVAL_MODE,
				ApprovalMode::ADD_TO_CART,
				ApprovalMode::available( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'Default approval', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'How customers approve their design before it is printed, unless a product overrides it.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => ApprovalMode::names(),
				)
			)
		);
		$this->register(
			Setting::number(
				self::PROOF_DEADLINE_DAYS,
				ProofLink::DEFAULT_DEADLINE_DAYS,
				1,
				ProofLink::MAX_DEADLINE_DAYS,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant; the value is a number of days. */
					'title'    => __( 'Proof approval deadline (days)', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'With proof by email, how many days the customer has to approve their proof. The link in the email works a week longer.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
				)
			)
		);
		$this->register(
			Setting::select(
				self::PROOF_NO_ANSWER,
				ProofOutcome::DEFAULT_NO_ANSWER,
				ProofOutcome::no_answer_choices( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant. */
					'title'    => __( 'When no answer by the deadline', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'With proof by email, what happens to a design the customer neither approved nor rejected by the deadline.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => ProofOutcome::no_answer_names(),
				)
			)
		);
		$this->register(
			Setting::select(
				self::PROOF_ON_DENY,
				ProofOutcome::DEFAULT_ON_DENY,
				ProofOutcome::on_deny_choices( ... ),
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant; what happens to an item whose design the customer rejected. */
					'title'    => __( 'On deny', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'What happens to an item whose design the customer rejected, or which was denied at the deadline.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
					'options'  => ProofOutcome::on_deny_names(),
				)
			)
		);
		$this->register(
			Setting::number(
				self::PROOF_REMINDER_AFTER_DAYS,
				ProofOutcome::DEFAULT_REMINDER_AFTER_DAYS,
				1,
				ProofLink::MAX_DEADLINE_DAYS,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant; the value is a number of days. */
					'title'    => __( 'First proof reminder after (days)', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'Days after the proof email that a customer who has not answered is reminded. Only reminders before the deadline are sent.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
				)
			)
		);
		$this->register(
			Setting::number(
				self::PROOF_REMINDER_EVERY_DAYS,
				ProofOutcome::DEFAULT_REMINDER_EVERY_DAYS,
				1,
				ProofLink::MAX_DEADLINE_DAYS,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant; the value is a number of days. */
					'title'    => __( 'Then remind every (days)', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'Days between one proof reminder and the next.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
				)
			)
		);
		$this->register(
			Setting::number(
				self::PROOF_REMINDERS,
				ProofOutcome::DEFAULT_REMINDERS,
				0,
				ProofOutcome::MAX_REMINDERS,
				static fn (): array => array(
					/* translators: Title of a setting on the settings page, shown to the merchant; the value is a number of reminders. */
					'title'    => __( 'Proof reminders at most', 'pixonaut-for-woocommerce' ),
					'desc'     => __( 'How many reminders a customer gets at most; 0 sends none. The last one says when the deadline is and what happens then.', 'pixonaut-for-woocommerce' ),
					'desc_tip' => true,
				)
			)
		);
		$this->register_developer_tools();
	}

	/**
	 * The hidden developer tools' settings. A `wp-config.php` constant wins
	 * over each address (`Api\Hosts`).
	 */
	private function register_developer_tools(): void {
		$this->register(
			Setting::url(
				self::API_BASE,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; the base URL of the Pixonaut API. */
					'title' => __( 'API base', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'The Pixonaut API as the browser reaches it, e.g. a local Pixonaut. Empty uses Pixonaut’s own. The store’s key is sent to this address.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::url(
				self::SERVER_API_BASE,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; the base URL WordPress uses for the Pixonaut API. */
					'title' => __( 'Server-to-server API base', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'The Pixonaut API as WordPress itself reaches it, when that differs from the browser’s address (WordPress inside Docker, say). Empty uses the API base.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::url(
				self::STUDIO_BASE,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; the base URL of the Pixonaut studio. */
					'title' => __( 'Studio base', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'The Pixonaut studio, where a connect is approved and the admin’s studio links lead. Empty uses Pixonaut’s own.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::url(
				self::CDN_BASE,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; the base URL of the CDN the designer SDK loads from. */
					'title' => __( 'CDN base', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Where the pinned designer SDK release loads from, e.g. Pixonaut’s staging CDN. The release’s integrity is still checked. Empty uses Pixonaut’s own.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::url(
				self::SDK_URL,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; the URL of a designer SDK script. */
					'title' => __( 'SDK script', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Loads the designer SDK from this script, e.g. a local build, instead of the pinned release from the CDN base. Its integrity is not checked. Empty loads the pinned release.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::toggle(
				self::SDK_DEBUG,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; turns on debug logging of the designer SDK. */
					'title' => __( 'SDK debug', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Logs every designer mount and its failures to the browser console. On the storefront, only for logged-in store managers.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
		$this->register(
			Setting::toggle(
				self::REQUEST_LOG,
				static fn (): array => array(
					/* translators: Title of a developer setting on the settings page, shown to the merchant; turns on a log of requests to Pixonaut. */
					'title' => __( 'Request log', 'pixonaut-for-woocommerce' ),
					'desc'  => __( 'Records the last 50 requests to Pixonaut, without keys or tokens.', 'pixonaut-for-woocommerce' ),
				)
			)
		);
	}

	/**
	 * Adds a setting.
	 *
	 * @param Setting $setting The definition.
	 * @throws LogicException When a setting with the same key exists.
	 */
	public function register( Setting $setting ): void {
		if ( isset( $this->settings[ $setting->key ] ) ) {
			throw new LogicException( sprintf( 'Setting "%s" is registered twice.', $setting->key ) );
		}
		$this->settings[ $setting->key ] = $setting;
	}

	/**
	 * @param string $key A registered setting's key.
	 * @return mixed The stored value, or the default when none is stored or it is invalid.
	 */
	public function get( string $key ): mixed {
		$stored = $this->stored();
		return $this->setting( $key )->resolve( $stored[ $key ] ?? null );
	}

	/**
	 * The images a product's Pixonaut tab no longer warns about printing
	 * below their recommended resolution, by Pixonaut asset id.
	 *
	 * @return list<string>
	 */
	public function quiet_images(): array {
		$ids = $this->get( self::LOW_RESOLUTION_QUIET_IMAGES );
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * Stores a value. An invalid value is stored as the default.
	 *
	 * @param string $key   A registered setting's key.
	 * @param mixed  $value The new value.
	 */
	public function set( string $key, mixed $value ): void {
		$this->set_many( array( $key => $value ) );
	}

	/**
	 * Stores several values at once, in one write of the option, as the
	 * settings form saves a tab. An invalid value is stored as the default.
	 *
	 * @param array<string, mixed> $values Registered settings' keys and their new values.
	 */
	public function set_many( array $values ): void {
		$stored = $this->stored();
		foreach ( $values as $key => $value ) {
			$setting        = $this->setting( $key );
			$stored[ $key ] = $setting->stored( $setting->resolve( $value ) );
		}
		update_option( self::OPTION, $stored );
	}

	/**
	 * @return array<string, Setting> Every setting, in registration order.
	 */
	public function all(): array {
		return $this->settings;
	}

	/**
	 * @param string $section Setting::GENERAL or Setting::DEVELOPER.
	 * @return array<string, Setting> The section's settings, in registration order.
	 */
	public function section( string $section ): array {
		return array_filter( $this->settings, static fn ( Setting $setting ): bool => $section === $setting->section );
	}

	/**
	 * @param string $key A registered setting's key.
	 * @throws LogicException When no setting has that key.
	 */
	private function setting( string $key ): Setting {
		if ( ! isset( $this->settings[ $key ] ) ) {
			throw new LogicException( sprintf( 'Unknown setting "%s".', $key ) );
		}
		return $this->settings[ $key ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
