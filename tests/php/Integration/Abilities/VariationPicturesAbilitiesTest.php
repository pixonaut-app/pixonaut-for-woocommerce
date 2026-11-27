<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DependentPosters;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * The shop tools reading and setting a picture for every variation,
 * store-wide and for a product, with what it costs the shop's storage:
 * turning it on past the warning needs the store manager's confirmation.
 */
final class VariationPicturesAbilitiesTest extends WP_UnitTestCase {

	use DependentPosters;
	use PicturedProducts;

	public const READ = Abilities::NAMESPACE . '/get-variation-pictures';

	public const SET = Abilities::NAMESPACE . '/set-variation-pictures';

	public const UPDATE_SETTINGS = Abilities::NAMESPACE . '/update-plugin-settings';

	/**
	 * Free bytes no estimate is a tenth of.
	 */
	private const ROOMY = PHP_INT_MAX;

	/**
	 * Free bytes every estimate passes a tenth of.
	 */
	private const TIGHT = 1;

	private int $poster_id;

	private int $free = self::ROOMY;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		$this->set_up_dependent_posters();
		$poster = $this->dependent_poster();
		self::config()->save_to( $poster );
		ShowcaseImages::save(
			$poster,
			array(
				array(
					'id'   => 'showcase-leo',
					'name' => 'Leo',
					'kind' => ShowcaseImages::PREVIEW,
				),
			)
		);
		foreach ( $this->poster_variations as $key => $variation ) {
			( new ProductVariant( DesignFixture::BLANK_ID, 'v_' . $key, 'v_' . $key, '', null, BlankFixture::VARIANT_VIEWS ) )->save_to( $variation );
		}
		$this->poster_id = $poster->get_id();
		add_filter( 'pixonaut_wc_catalogue_media_free_bytes', fn (): int => $this->free );
		wp_set_current_user( self::user( 'shop_manager' ) );
	}

	public function tear_down(): void {
		$this->tear_down_dependent_posters();
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_it_reads_store_wide_and_for_a_product_with_what_turning_it_on_costs(): void {
		$store = $this->succeed( self::READ, array() );
		$this->assertFalse( $store['store_wide'] );
		$this->assertFalse( $store['on'] );
		$this->assertSame( count( $this->poster_variations ), $store['turning_on']['pictures'] );
		$this->assertStringContainsString( 'not in Pixonaut', $store['turning_on']['summary'] );

		$product = $this->succeed( self::READ, array( 'product_id' => $this->poster_id ) );
		$this->assertSame( VariationPictures::DEFAULT, $product['choice'] );
		$this->assertSame( count( $this->poster_variations ), $product['turning_on']['pictures'] );
	}

	public function test_it_counts_the_pictures_waiting_and_those_saved_in_the_last_hour(): void {
		as_unschedule_all_actions( ProductPictures::ACTION );
		$waiting = array_map( static fn ( \WC_Product $variation ): int => $variation->get_id(), array_values( $this->poster_variations ) );
		foreach ( $waiting as $id ) {
			PictureQueue::schedule( $id );
		}
		$saved = as_enqueue_async_action( ProductPictures::ACTION, array( 0 ), ProductPictures::GROUP );
		\ActionScheduler::store()->mark_complete( $saved );

		$queue = $this->succeed( self::READ, array() )['queue'];

		$this->assertSame( count( $waiting ), $queue['queued'] );
		$this->assertSame( 1, $queue['drawn_recently'] );
		$this->assertSame( $queue, $this->succeed( self::READ, array( 'product_id' => $this->poster_id ) )['queue'], 'store-wide for a product too' );
	}

	public function test_turning_it_on_past_the_warning_is_refused_without_the_confirmation(): void {
		$this->free = self::TIGHT;

		$refused = $this->ability( self::SET )->execute( array( 'choice' => VariationPictures::ON ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'pixonaut_wc_ability_variation_pictures_unconfirmed', $refused->get_error_code() );
		$this->assertStringContainsString( 'remaining storage', $refused->get_error_message() );
		$this->assertFalse( $this->plugin->settings()->get( Settings::VARIATION_PICTURES ) );

		$poster = wc_get_product( $this->poster_id );
		$this->assertInstanceOf( \WC_Product::class, $poster );
		$refused = $this->ability( self::SET )->execute(
			array(
				'product_id' => $this->poster_id,
				'choice'     => VariationPictures::ON,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( VariationPictures::DEFAULT, VariationPictures::choice( $this->reload( $poster ) ) );

		$answer = $this->succeed(
			self::SET,
			array(
				'choice'  => VariationPictures::ON,
				'confirm' => true,
			)
		);
		$this->assertTrue( $answer['store_wide'] );
		$this->assertTrue( $answer['changed']['warn'] );
		$this->assertTrue( $this->plugin->settings()->get( Settings::VARIATION_PICTURES ) );
	}

	public function test_below_the_warning_it_turns_on_and_off_for_a_product(): void {
		$answer = $this->succeed(
			self::SET,
			array(
				'product_id' => $this->poster_id,
				'choice'     => VariationPictures::ON,
			)
		);
		$this->assertSame( VariationPictures::ON, $answer['choice'] );
		$this->assertTrue( $answer['on'] );

		$answer = $this->succeed(
			self::SET,
			array(
				'product_id' => $this->poster_id,
				'choice'     => VariationPictures::DEFAULT,
			)
		);
		$this->assertSame( VariationPictures::DEFAULT, $answer['choice'] );
		$this->assertFalse( $answer['on'] );
	}

	public function test_the_settings_ability_does_not_turn_it_on(): void {
		$refused = $this->ability( self::UPDATE_SETTINGS )->execute( array( Settings::VARIATION_PICTURES => true ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'pixonaut_wc_ability_variation_pictures_cost', $refused->get_error_code() );
		$this->assertFalse( $this->plugin->settings()->get( Settings::VARIATION_PICTURES ) );
	}

	public function test_the_settings_ability_does_not_turn_product_pictures_on_past_the_warning(): void {
		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );
		$poster = wc_get_product( $this->poster_id );
		$this->assertInstanceOf( \WC_Product::class, $poster );
		VariationPictures::choose( $poster, VariationPictures::ON );
		$this->free = self::TIGHT;

		$refused = $this->ability( self::UPDATE_SETTINGS )->execute( array( Settings::PRODUCT_PICTURES => true ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'pixonaut_wc_ability_variation_pictures_cost', $refused->get_error_code() );
		$this->assertFalse( $this->plugin->settings()->get( Settings::PRODUCT_PICTURES ) );

		$this->free = self::ROOMY;
		$this->assertIsArray( $this->ability( self::UPDATE_SETTINGS )->execute( array( Settings::PRODUCT_PICTURES => true ) ), 'below the warning' );
	}

	public function test_a_product_that_is_not_variable_is_refused(): void {
		$simple = $this->product( self::config() );

		$this->assertInstanceOf( WP_Error::class, $this->ability( self::READ )->execute( array( 'product_id' => $simple->get_id() ) ) );
	}

	public function test_a_user_who_may_not_manage_the_store_may_not_use_them(): void {
		wp_set_current_user( self::user( 'customer' ) );

		$this->assertInstanceOf( WP_Error::class, $this->ability( self::READ )->execute( array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( self::SET )->execute( array( 'choice' => VariationPictures::OFF ) ) );
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	/**
	 * Runs an ability that is to succeed.
	 *
	 * @param string       $name  The ability.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $name, array $input ): array {
		$answer = $this->ability( $name )->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : $name );
		return $answer;
	}

	private static function user( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		self::assertIsInt( $user );
		return $user;
	}
}
