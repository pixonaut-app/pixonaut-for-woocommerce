<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Approval;

use Brain\Monkey\Filters;
use Mockery;
use Pixonaut\WooCommerce\Approval\ProofLink;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use WC_Order_Item_Product;

final class ProofLinkTest extends TestCase {

	public function test_the_link_outlives_the_deadline_by_the_margin(): void {
		$this->assertSame( 14 + ProofLink::MARGIN_DAYS, ProofLink::days( 14, $this->item() ) );
		$this->assertSame( ProofLink::MAX_LINK_DAYS, ProofLink::days( ProofLink::MAX_DEADLINE_DAYS, $this->item() ) );
	}

	public function test_the_link_never_outlives_what_pixonaut_takes(): void {
		Filters\expectApplied( 'pixonaut_wc_approval_proof_link_days' )->andReturn( 365 );

		$this->assertSame( ProofLink::MAX_LINK_DAYS, ProofLink::days( 14, $this->item() ) );
	}

	public function test_a_filtered_lifetime_below_a_day_is_one_day(): void {
		Filters\expectApplied( 'pixonaut_wc_approval_proof_link_days' )->andReturn( 0 );

		$this->assertSame( 1, ProofLink::days( 14, $this->item() ) );
	}

	private function item(): WC_Order_Item_Product {
		/** @var WC_Order_Item_Product $item */
		$item = Mockery::mock( WC_Order_Item_Product::class );
		return $item;
	}
}
