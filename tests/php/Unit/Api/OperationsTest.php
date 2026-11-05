<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class OperationsTest extends TestCase {

	public function test_every_registered_operation_is_consumed_with_its_credential(): void {
		$consumed = OpenApiAssert::consumed();

		foreach ( Operations::plugin()->all() as $id => $operation ) {
			$this->assertContains( $id, $consumed['operations'], "{$id} is missing from consumed.json." );
			$sent  = Credential::None === $operation->credential ? array() : array( $operation->credential->value );
			$entry = $consumed['keys'][ $id ] ?? null;
			if ( is_array( $entry ) ) {
				// Also called with other credentials, through the SDK.
				foreach ( $sent as $key ) {
					$this->assertContains( $key, $entry, "{$id} has the wrong keys entry in consumed.json." );
				}
				continue;
			}
			$this->assertSame( $sent[0] ?? null, $entry, "{$id} has the wrong keys entry in consumed.json." );
			$this->assertLessThanOrEqual( 1, count( $sent ), "{$id} has the wrong keys entry in consumed.json." );
		}
	}
}
