<?php
/**
 * What the store's hosted pages are drawn in, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connection's `branding`: the store's own brand, else the workspace's default.
 */
final class ConnectedStoreBranding {

	/**
	 * @param string|null $accent_color `accentColor`, `#rrggbb`.
	 * @param string|null $brand_id     `brandId`; null while the workspace has no brand.
	 * @param string|null $logo_url     `logoUrl`.
	 */
	public function __construct(
		public readonly ?string $accent_color,
		public readonly ?string $brand_id,
		public readonly ?string $logo_url,
	) {
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			accent_color: Read::nullable_string( $data, 'accentColor', $path ),
			brand_id: Read::nullable_string( $data, 'brandId', $path ),
			logo_url: Read::nullable_string( $data, 'logoUrl', $path ),
		);
	}
}
