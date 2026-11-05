<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\TemplateId;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class TemplateIdTest extends TestCase {

	public function test_splits_base_id_and_version(): void {
		$id = TemplateId::parse( 'mini-tee@2' );

		$this->assertNotNull( $id );
		$this->assertSame( 'mini-tee', $id->base_id );
		$this->assertSame( 2, $id->version );
		$this->assertSame( 'mini-tee@2', (string) $id );
	}

	public function test_splits_on_the_last_at_sign(): void {
		$id = TemplateId::parse( 'team@shop@12' );

		$this->assertNotNull( $id );
		$this->assertSame( 'team@shop', $id->base_id );
		$this->assertSame( 12, $id->version );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformed(): array {
		return array(
			'no version'         => array( 'mini-tee' ),
			'empty version'      => array( 'mini-tee@' ),
			'no base id'         => array( '@2' ),
			'version zero'       => array( 'mini-tee@0' ),
			'leading zero'       => array( 'mini-tee@02' ),
			'not a whole number' => array( 'mini-tee@1.5' ),
			'empty'              => array( '' ),
		);
	}

	/**
	 * @dataProvider malformed
	 *
	 * @param string $id A malformed id.
	 */
	public function test_refuses_a_malformed_id( string $id ): void {
		$this->assertNull( TemplateId::parse( $id ) );
	}
}
