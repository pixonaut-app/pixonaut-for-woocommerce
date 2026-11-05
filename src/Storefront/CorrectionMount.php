<?php
/**
 * The mount options that open a committed design for a correction.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Cart\DesignCheck;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * A product page that edits a design the customer made already, from their
 * cart or after they asked for changes to its proof, mounts each side on a
 * correction link minted for its design. The SDK mounts it without the
 * publishable key: the link names the design and is the credential.
 * A product on the Personalise button layout sends the customer to
 * Pixonaut's hosted correction page instead (`page()`).
 */
final class CorrectionMount {

	/**
	 * @param Connection $connection The store's connection.
	 * @param Designs    $designs    Reads designs and mints correction links.
	 * @param Logger     $logger     Logs links and designs Pixonaut refused.
	 * @param Settings   $settings   The designer's language, form layout and preloading.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Designs $designs,
		private readonly Logger $logger,
		private readonly Settings $settings,
	) {
	}

	/**
	 * Whether a correction can be opened now: the store is connected and its
	 * connection is not paused.
	 */
	public function available(): bool {
		return null !== $this->connection->current() && null === $this->connection->paused();
	}

	/**
	 * A side's mount options on a fresh correction link for its design, or
	 * null when the store cannot open it now. With the configuration of
	 * what was bought, the side opens on its product as the product page
	 * mounts it (`product()`).
	 *
	 * @param string             $design_id The side's design.
	 * @param WC_Product         $product   The product the page shows.
	 * @param string             $side_key  The side.
	 * @param ProductConfig|null $config    The configuration of what was bought, whose form layout the designer takes; null for the template's.
	 * @param WC_Product|null    $bought    The product or variation bought, whose blank variant the designer shows; `$product` when null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function options( string $design_id, WC_Product $product, string $side_key, ?ProductConfig $config = null, ?WC_Product $bought = null ): ?array {
		$link = $this->available() ? $this->token( $design_id ) : null;
		if ( null === $link ) {
			return null;
		}
		$options  = array(
			'correctionLink' => $link,
			'design'         => $design_id,
			'apiBase'        => Hosts::api(),
		);
		$language = DesignerLanguage::resolve( $this->settings, determine_locale() );
		if ( null !== $language ) {
			// Absent, the SDK uses the workspace's default language.
			$options['locale'] = $language;
		}
		$options += DesignerLook::mount_options( $this->settings );
		$layout   = null === $config ? null : FormLayout::resolve( $config, $this->settings );
		if ( null !== $layout ) {
			$options['layout'] = $layout;
		}
		if ( null !== $config ) {
			$options += $this->product( $design_id, $config, $side_key, $bought ?? $product );
		}

		/**
		 * Filters the options one side mounts with on a product page opened
		 * to edit a design the customer made already, from their cart or
		 * after they asked for changes to its proof, before the targets are
		 * added. The storefront script runs them through the JavaScript
		 * filter `pixonaut.designer.mountOptions` too.
		 *
		 * A correction keeps the design's template. When the design is still
		 * what the side sells, the side opens on its product as the product
		 * page mounts it, with no size menu, and its form arranged as there
		 * (`formGroups`): the design keeps the size it was bought at. The SDK refuses a blank or placement the design was
		 * not made on. `preload`,
		 * `showPrintAreaFrame` and `accent` follow the store's settings, as
		 * on any product page.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $options  `correctionLink`, `design`, `apiBase`, `locale` (one of `DesignerLanguage::languages()`, absent for the workspace's default), `layout` (`page` or `steps`, absent to follow the template), `preload`, `showPrintAreaFrame`, `accent` (with a custom accent colour), and with the design on its product `blankId`, `placementId`, `placements`, `views`, `initialView` and `variants` (with a blank, as on the product page).
		 * @param WC_Product           $product  The product.
		 * @param string               $side_key The side, e.g. `front`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_storefront_edit_mount_options', function ( $options ) {
		 *     $options['locale'] = 'sv';
		 *     return $options;
		 * } );
		 */
		return (array) apply_filters( 'pixonaut_wc_storefront_edit_mount_options', $options, $product, $side_key );
	}

	/**
	 * The options that open a side on its product, as the product page
	 * mounts it less the size menu, which a correction never shows; empty when
	 * the design is no longer what the side sells (another template version,
	 * blank, placement or a size off its menu) or Pixonaut did not answer,
	 * and the side opens on the design alone.
	 *
	 * @param string        $design_id The side's design.
	 * @param ProductConfig $config    The configuration of what was bought.
	 * @param string        $side_key  The side.
	 * @param WC_Product    $bought    The product or variation bought.
	 *
	 * @return array<string, mixed>
	 */
	private function product( string $design_id, ProductConfig $config, string $side_key, WC_Product $bought ): array {
		$side = null;
		foreach ( $config->sides as $candidate ) {
			if ( $candidate->key === $side_key ) {
				$side = $candidate;
			}
		}
		if ( null === $side ) {
			return array();
		}
		try {
			$design = $this->designs->get( $design_id );
		} catch ( ApiError $error ) {
			$this->logger->warning( sprintf( 'Could not read design %s to open it on its product: %s', $design_id, $error->getMessage() ), array( 'code' => $error->error_code ) );
			return array();
		}
		$size = DesignCheck::size_index( $design[ Designs::PRINT_SIZE ] ?? null, $side->print_sizes );
		if ( null === $size || null !== DesignCheck::mismatch( $design, $side, $config->blank_id ) ) {
			return array();
		}
		$variant = null === $config->blank_id ? null : ProductVariant::sold_by( $bought, $config );
		$options = ProductMount::options( $config, $side, null === $variant ? array() : array( $variant ) );
		// The SDK draws a correction in the box frozen with the design.
		unset( $options['printSizes'] );
		$groups = FormGroupsMount::option( $side, $bought, OptionGroups::resolve( $config, $this->settings ) );
		return null === $groups ? $options : $options + array( 'formGroups' => $groups );
	}

	/**
	 * Pixonaut's hosted correction page for a design, which sends the
	 * customer back to `$return_url` with `$state`, or null when the store
	 * cannot open it now.
	 *
	 * @param string $design_id  The design.
	 * @param string $return_url Where the page sends the customer back to.
	 * @param string $state      Carried back untouched.
	 */
	public function page( string $design_id, string $return_url, string $state ): ?string {
		return $this->available() ? $this->link( $design_id, $return_url, $state )['url'] ?? null : null;
	}

	/**
	 * A fresh correction link for a design, or null when Pixonaut gave none.
	 *
	 * @param string $design_id The design.
	 */
	private function token( string $design_id ): ?string {
		return $this->link( $design_id )['token'] ?? null;
	}

	/**
	 * @param string      $design_id  The design.
	 * @param string|null $return_url Where the hosted page returns to, if it opens one.
	 * @param string|null $state      Carried back untouched.
	 *
	 * @return array{token: string, expiresAt: int, url: string|null}|null Null when Pixonaut gave none.
	 */
	private function link( string $design_id, ?string $return_url = null, ?string $state = null ): ?array {
		try {
			return $this->designs->correction_link( $design_id, $return_url, $state );
		} catch ( ApiError $error ) {
			$this->logger->warning( sprintf( 'Could not open design %s for editing: %s', $design_id, $error->getMessage() ), array( 'code' => $error->error_code ) );
			return null;
		}
	}
}
