<?php
/**
 * The store's email about prices whose margin fell below its lowest margin.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Emails;

use Pixonaut\WooCommerce\Admin\MarginFloorList;
use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use WC_Product;

/**
 * Sent to the store at most once a day by `MarginFloor`, naming each price
 * whose margin fell below the lowest margin the store accepts since the
 * last one: its product, variation, price, cost and margin, and a link to
 * edit it. Past the most one email names, it says how many more there are,
 * with a link to the products list.
 */
final class MarginFloorEmail extends StoreEmail {

	public const ID = 'pixonaut_margin_floor';

	/**
	 * The prices the email names, as the templates show them.
	 *
	 * @var list<array{product: string, variation: string, price: string, cost: string, margin: string, edit_url: string}>
	 */
	public array $rows = array();

	/**
	 * The lowest margin, as the templates show it.
	 *
	 * @var string
	 */
	public string $floor = '';

	/**
	 * How many more prices are newly below the floor than the email names.
	 *
	 * @var int
	 */
	public int $more = 0;

	public function __construct() {
		$this->id = self::ID;
		/* translators: Name of an email to the merchant, shown in WooCommerce > Settings > Emails. */
		$this->title          = __( 'Pixonaut margin below the lowest margin', 'pixonaut-for-woocommerce' );
		$this->description    = __( 'Sent to the store once a day when the margin of a price, against what the printshop charges for it and its shipping, fell below the lowest margin set under Pixonaut > Prices.', 'pixonaut-for-woocommerce' );
		$this->template_html  = Emails::MARGIN_FLOOR_HTML;
		$this->template_plain = Emails::MARGIN_FLOOR_PLAIN;

		parent::__construct();
	}

	public function get_default_subject(): string {
		/* translators: Default subject of the email to the merchant. {site_title} is filled in by WooCommerce; keep it as written. */
		return __( '[{site_title}]: Prices below your lowest margin', 'pixonaut-for-woocommerce' );
	}

	public function get_default_heading(): string {
		/* translators: Default heading at the top of the email to the merchant. */
		return __( 'Prices below your lowest margin', 'pixonaut-for-woocommerce' );
	}

	/**
	 * Sends the email naming prices below the floor.
	 *
	 * @param array[] $rows  Each price: `item` (the product or variation), `product`, and its `price`, `cost` and `margin` as `ProductPricing::margin_now()` gives them.
	 * @param float   $floor The lowest margin, in percent.
	 * @param int     $more  How many more prices are newly below the floor, named in a later email.
	 * @phpstan-param list<array{item: WC_Product, product: WC_Product, price: int, cost: int, margin: float}> $rows
	 * @return bool Whether it was sent.
	 */
	public function trigger( array $rows, float $floor, int $more = 0 ): bool {
		$this->setup_locale();
		$this->rows  = array_map(
			static fn ( array $row ): array => array(
				'product'   => $row['product']->get_name(),
				'variation' => $row['item']->is_type( 'variation' ) ? ProductPricing::label( $row['item'] ) : '',
				'price'     => PricingPanel::money( $row['price'] ),
				'cost'      => PricingPanel::money( $row['cost'] ),
				'margin'    => PricingPanel::percent( $row['margin'] ),
				'edit_url'  => admin_url( sprintf( 'post.php?post=%d&action=edit', $row['product']->get_id() ) ),
			),
			$rows
		);
		$this->floor = PricingPanel::percent( $floor );
		$this->more  = $more;
		$sent        = false;
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function template_args(): array {
		return array(
			'rows'     => $this->rows,
			'floor'    => $this->floor,
			'more'     => $this->more,
			'list_url' => MarginFloorList::url(),
		);
	}
}
