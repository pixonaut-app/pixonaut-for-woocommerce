<?php
/**
 * Links to Pixonaut's hosted designer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\ShareLinksUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\TemplatesDesignerLinksCreateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The store's designer links: made with their warnings logged, the working
 * ones found again across pages, and withdrawn once.
 */
final class DesignerLinks {

	/**
	 * The warning on a link with more sides than the designer is built for.
	 */
	public const SIDES_OVER_RECOMMENDED = 'sides_over_recommended';

	/**
	 * `kind` of a designer link in `GET /v1/share-links`.
	 */
	public const KIND = 'designer';

	/**
	 * Links one page of `GET /v1/share-links` answers at most.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * `createdBy` narrowing `GET /v1/share-links` to the caller's own links.
	 */
	private const CREATED_BY_CALLER = 'me';

	/**
	 * @param Sdk             $pixonaut Calls Pixonaut.
	 * @param LoggerInterface $logger   Logs what Pixonaut warns of in a link it made.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * `POST /v1/templates/{id}/designer-links`: a link to the hosted designer
	 * for the template's live version. A warning in the answer, such as
	 * `sides_over_recommended` for a link with more sides than the designer
	 * is built for, is logged; the link is made all the same.
	 *
	 * @param string               $template_base_id The template's base id; a side under `sides` may name another.
	 * @param array<string, mixed> $body             One side at the top level or 2–10 under `sides`, and `returnUrl`, `locale`, `name`.
	 *
	 * @return array{id: string, url: string} The link's id and the hosted designer's address.
	 *
	 * @throws ApiException When Pixonaut refused (`print_size_illegal`, `share_link_name_taken`, …) or could not be reached.
	 */
	public function create( string $template_base_id, array $body ): array {
		$link = $this->pixonaut->designerLinks()->create( $template_base_id, TemplatesDesignerLinksCreateRequest::fromArray( $body ) );
		if ( '' === $link->url || '' === $link->id ) {
			throw new ApiException( 'templates.designerLinks.create', 200, ApiException::UNEXPECTED_RESPONSE, false );
		}
		$warnings = $link->raw()['warnings'] ?? null;
		foreach ( is_array( $warnings ) ? $warnings : array() as $warning ) {
			if ( is_array( $warning ) ) {
				$this->warn( $template_base_id, $warning );
			}
		}
		return array(
			'id'  => $link->id,
			'url' => $link->url,
		);
	}

	/**
	 * `GET /v1/share-links`: the ids of the designer links the store made for
	 * a template that still work, every page of them.
	 *
	 * @param string $template_base_id The template the links were made for: their subject.
	 *
	 * @return list<string>
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function working( string $template_base_id ): array {
		$ids = array();
		$this->each(
			$template_base_id,
			null,
			static function ( array $link ) use ( &$ids ): bool {
				if ( is_string( $link['id'] ?? null ) ) {
					$ids[] = $link['id'];
				}
				return true;
			}
		);
		return $ids;
	}

	/**
	 * The address a working designer link the store made for a template
	 * opens now: a regenerated link keeps its id under a new address. The
	 * links are narrowed to those holding the id (`q`), and read until it is
	 * found.
	 *
	 * @param string $template_base_id The template the link was made for: its subject.
	 * @param string $id               The link.
	 *
	 * @return string|null Null when the link no longer works, or Pixonaut lists it without an address.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function address( string $template_base_id, string $id ): ?string {
		$url = null;
		$this->each(
			$template_base_id,
			$id,
			static function ( array $link ) use ( $id, &$url ): bool {
				if ( ( $link['id'] ?? null ) !== $id ) {
					return true;
				}
				$url = is_string( $link['url'] ?? null ) && '' !== $link['url'] ? $link['url'] : null;
				return false;
			}
		);
		return $url;
	}

	/**
	 * `GET /v1/share-links`: hands each of the store's working designer
	 * links for a template to `$visit`, a page at a time, until it answers
	 * false or the pages end.
	 *
	 * @param string      $template_base_id The template the links were made for.
	 * @param string|null $query            Narrows the links to those holding it, such as an id; null for all.
	 * @param callable    $visit            Given each link; answers whether to read on.
	 * @phpstan-param callable(array<string, mixed>): bool $visit
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function each( string $template_base_id, ?string $query, callable $visit ): void {
		$page = 1;
		do {
			$answer = $this->pixonaut->shareLinks()->list(
				page: $page,
				pageSize: self::PAGE_SIZE,
				subjectId: $template_base_id,
				kind: self::KIND,
				createdBy: self::CREATED_BY_CALLER,
				q: $query,
			)->raw();
			$links  = array_values( array_filter( is_array( $answer['links'] ?? null ) ? $answer['links'] : array(), 'is_array' ) );
			foreach ( $links as $link ) {
				if ( ! $visit( $link ) ) {
					return;
				}
			}
			++$page;
			$full = self::PAGE_SIZE === count( $links );
		} while ( $full );
	}

	/**
	 * `PATCH /v1/share-links/{id}`: renames a link, or takes its name away.
	 *
	 * @param string      $id   The link.
	 * @param string|null $name Its new name; null for none.
	 *
	 * @throws ApiException When Pixonaut refused (`share_link_name_taken`, `not_found` for one no longer working) or could not be reached.
	 */
	public function rename( string $id, ?string $name ): void {
		$this->pixonaut->shareLinks()->update( $id, new ShareLinksUpdateRequest( name: $name ) );
	}

	/**
	 * `DELETE /v1/share-links/{id}`: the link stops working. One that no
	 * longer works is withdrawn already.
	 *
	 * @param string $id The link.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function withdraw( string $id ): void {
		try {
			$this->pixonaut->shareLinks()->delete( $id );
		} catch ( ApiException $error ) {
			if ( ErrorCode::NOT_FOUND !== $error->errorCode ) {
				throw $error;
			}
		}
	}

	/**
	 * @param string       $template_base_id The template the link was made for.
	 * @param array<mixed> $warning          One of the answer's `warnings`: `code`, and for `sides_over_recommended`, `sides` and `recommended`.
	 */
	private function warn( string $template_base_id, array $warning ): void {
		$code = is_string( $warning['code'] ?? null ) ? $warning['code'] : 'unknown';
		$this->logger->warning(
			self::SIDES_OVER_RECOMMENDED === $code && is_int( $warning['sides'] ?? null ) && is_int( $warning['recommended'] ?? null )
				? sprintf( 'Pixonaut made a designer link for template %s with %d sides; its designer is built for at most %d. Give the product fewer sides.', $template_base_id, $warning['sides'], $warning['recommended'] )
				: sprintf( 'Pixonaut made a designer link for template %s with a warning: %s.', $template_base_id, $code ),
			array(
				'operation' => 'templates.designerLinks.create',
				'template'  => $template_base_id,
				'warning'   => $warning,
			)
		);
	}
}
