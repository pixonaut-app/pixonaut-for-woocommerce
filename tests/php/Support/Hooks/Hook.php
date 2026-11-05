<?php
/**
 * One hook the plugin fires, as its docblock describes it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support\Hooks;

/**
 * A call to `do_action()`, `apply_filters()` or one of their `_ref_array` and
 * `_deprecated` variants, with what its docblock says. Fields the docblock
 * leaves out are empty, so the docblock lint can name them.
 */
final class Hook {

	public const ACTION = 'action';

	public const FILTER = 'filter';

	/**
	 * @param string                                                       $name        Hook name as written, e.g. `pixonaut_wc_fulfilment_{$to}`.
	 * @param string                                                       $type        ACTION or FILTER.
	 * @param bool                                                         $deprecated  Fired through `do_action_deprecated()` or `apply_filters_deprecated()`.
	 * @param string                                                       $file        Path relative to the scanned root's parent, e.g. `src/Labels.php`.
	 * @param int                                                          $line        Line of the call.
	 * @param int|null                                                     $args        Arguments passed, or null when they cannot be counted.
	 * @param bool                                                         $documented  Whether a docblock sits right above the call.
	 * @param string                                                       $summary     First paragraph of the docblock.
	 * @param string                                                       $description Further paragraphs, as Markdown.
	 * @param string                                                       $since       The `@since` version.
	 * @param list<array{type: string, name: string, description: string}> $params      The `@param` tags, types resolved to full class names.
	 * @param string                                                       $example     The `@example` code.
	 * @param string                                                       $deprecation The `@deprecated` text.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly bool $deprecated,
		public readonly string $file,
		public readonly int $line,
		public readonly ?int $args,
		public readonly bool $documented = false,
		public readonly string $summary = '',
		public readonly string $description = '',
		public readonly string $since = '',
		public readonly array $params = array(),
		public readonly string $example = '',
		public readonly string $deprecation = '',
	) {
	}

	/**
	 * Whether the name has a variable part, like `pixonaut_wc_fulfilment_{$to}`.
	 */
	public function is_dynamic(): bool {
		return str_contains( $this->name, '{' ) || str_contains( $this->name, '$' );
	}

	/**
	 * Whether a fired hook name is this hook: equal, or matching with each
	 * variable part standing for at least one character.
	 *
	 * @param string $fired A hook name as WordPress fired it.
	 */
	public function matches( string $fired ): bool {
		if ( ! $this->is_dynamic() ) {
			return $fired === $this->name;
		}
		$parts   = preg_split( '/\{\$[^}]+\}/', $this->name );
		$pattern = implode( '.+', array_map( static fn ( string $part ): string => preg_quote( $part, '/' ), false === $parts ? array() : $parts ) );
		return 1 === preg_match( '/^' . $pattern . '$/', $fired );
	}

	/**
	 * @return array<string, mixed> The fields, for the generated references.
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
