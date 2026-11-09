<?php
/**
 * The publishable key a connect hands over, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connect answer's `publishableKey`, the key the store's pages load the designer with.
 */
final class ConnectedStoreExchangePublishableKey {

	/**
	 * @param string[] $allowed_origins `allowedOrigins`, the origins it was bound to.
	 * @param string   $id              `id`.
	 * @param string   $key             `key`, the `pk_` key.
	 * @phpstan-param list<string> $allowed_origins
	 */
	public function __construct(
		public readonly array $allowed_origins,
		public readonly string $id,
		public readonly string $key,
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
			allowed_origins: Read::strings( $data, 'allowedOrigins', $path ),
			id: Read::string( $data, 'id', $path ),
			key: Read::string( $data, 'key', $path ),
		);
	}
}
