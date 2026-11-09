<?php
/**
 * A designer link Pixonaut made.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `POST /v1/templates/{id}/designer-links` the store reads.
 */
final class DesignerLink {

	/**
	 * @param string                     $id       `id`.
	 * @param string                     $url      `url`, the hosted designer the link opens.
	 * @param DesignerLinkWarning[]|null $warnings `warnings`; absent when there is nothing to warn of.
	 * @phpstan-param list<DesignerLinkWarning>|null $warnings
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $url,
		public readonly ?array $warnings,
	) {
	}

	/**
	 * `POST /v1/templates/{id}/designer-links`.
	 *
	 * @param Sdk                  $pixonaut         The SDK.
	 * @param string               $template_base_id The template's base id.
	 * @param array<string, mixed> $body             The request body.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function create( Sdk $pixonaut, string $template_base_id, array $body ): self {
		return Read::answer( $pixonaut->call( 'templates.designerLinks.create', array( 'id' => $template_base_id ), body: $body ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			url: Read::string( $data, 'url', $path ),
			warnings: array_key_exists( 'warnings', $data ) ? Read::objects( $data, 'warnings', $path, DesignerLinkWarning::from_answer( ... ) ) : null,
		);
	}
}
