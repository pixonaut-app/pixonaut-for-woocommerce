<?php
/**
 * The inline designer one product page shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Approval\OrderEditLine;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\EditedLine;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use WC_Product;

/**
 * A personalised product on the inline layout, with the SDK mount options of
 * each side it shows. Without mounts the page cannot personalise right now:
 * it keeps its gallery and says so.
 */
final class InlineDesigner {

	/**
	 * @param WC_Product                          $product The product the page shows, which holds the configuration.
	 * @param ProductConfig                       $config  Its configuration.
	 * @param array<string, array<string, mixed>> $mounts        Mount options by side key, in display order; empty when unavailable.
	 * @param string                              $approval_mode How the customer approves the design, an `ApprovalMode`.
	 * @param EditedLine|null                     $edit          The cart line whose designs the page edits; null when it adds a new one.
	 * @param OrderEditLine|null                  $order_edit    The order line whose design the page edits after the customer asked for changes; null when it edits none.
	 * @param string|null                         $closed        What the page says in place of the designer when the order line's design cannot be changed; null for the usual text.
	 * @param array<int, array<string, mixed>>    $variations    On a variable product: what each variation switches the designer to, by variation id (`VariationSwitches`).
	 * @param array<string, string>               $side_labels   Each mounted side's name, by side key; empty to take them from the configuration.
	 * @param string|null                         $variant       The ref of the blank variant the product sells, which the designer shows it in; null for none, and on a variable product, whose variations each name theirs.
	 */
	public function __construct(
		public readonly WC_Product $product,
		public readonly ProductConfig $config,
		public readonly array $mounts,
		public readonly string $approval_mode = ApprovalMode::ADD_TO_CART,
		public readonly ?EditedLine $edit = null,
		public readonly ?OrderEditLine $order_edit = null,
		public readonly ?string $closed = null,
		public readonly array $variations = array(),
		private readonly array $side_labels = array(),
		public readonly ?string $variant = null,
	) {
	}

	/**
	 * Each side's name, by side key.
	 *
	 * @return array<string, string>
	 */
	public function side_labels(): array {
		if ( array() !== $this->side_labels ) {
			return $this->side_labels;
		}
		$labels = array();
		foreach ( $this->config->sides as $side ) {
			$labels[ $side->key ] = $side->label;
		}
		return $labels;
	}

	public function available(): bool {
		return array() !== $this->mounts;
	}
}
