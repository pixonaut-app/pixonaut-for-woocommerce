<?php
/**
 * Finds the hooks a directory of PHP fires.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support\Hooks;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Pixonaut\WooCommerce\DeprecatedHooks;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Reads every hook call and the docblock right above it, the same way
 * wp-hooks/generator does: the docblock must end on the line before the call.
 * Class names in `@param` types are resolved against the file's namespace and
 * `use` statements. The generated hook reference, the docblock lint and the
 * hook inventory test all read the hooks from here.
 */
final class HookScanner {

	/**
	 * Every hook the plugin itself defines starts with this.
	 */
	public const PREFIX = 'pixonaut_wc_';

	/**
	 * Hook functions, and the type of hook each fires.
	 */
	private const FUNCTIONS = array(
		'do_action'                => Hook::ACTION,
		'do_action_ref_array'      => Hook::ACTION,
		'do_action_deprecated'     => Hook::ACTION,
		'apply_filters'            => Hook::FILTER,
		'apply_filters_ref_array'  => Hook::FILTER,
		'apply_filters_deprecated' => Hook::FILTER,
	);

	/**
	 * The plugin's deprecation helpers, and the WordPress function each
	 * stands in for at a retired hook's call site.
	 */
	private const DEPRECATION_HELPERS = array(
		'filter' => 'apply_filters_deprecated',
		'action' => 'do_action_deprecated',
	);

	/**
	 * Names in `@param` types that are not classes.
	 */
	private const BUILTIN_TYPES = array( 'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'resource', 'self', 'static', 'string', 'true', 'void' );

	/**
	 * The plugin's own hooks in a directory, sorted by name.
	 *
	 * @param string $dir Directory to scan, e.g. the plugin's `src`.
	 * @return list<Hook>
	 */
	public static function plugin_hooks( string $dir ): array {
		$hooks = array_values(
			array_filter(
				self::scan_directory( $dir ),
				static fn ( Hook $hook ): bool => str_starts_with( $hook->name, self::PREFIX )
			)
		);
		usort( $hooks, static fn ( Hook $a, Hook $b ): int => strcmp( $a->name, $b->name ) );
		return $hooks;
	}

	/**
	 * Every hook call in the PHP files under a directory.
	 *
	 * @param string $dir Directory to scan.
	 * @return list<Hook>
	 * @throws RuntimeException When a file cannot be read or parsed.
	 */
	public static function scan_directory( string $dir ): array {
		$base  = dirname( $dir ) . '/';
		$files = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );

		$hooks = array();
		foreach ( $files as $path ) {
			$code = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file.
			if ( false === $code ) {
				throw new RuntimeException( sprintf( 'Cannot read %s.', $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			array_push( $hooks, ...self::scan_source( $code, substr( $path, strlen( $base ) ) ) );
		}
		return $hooks;
	}

	/**
	 * Every hook call in one file's source.
	 *
	 * @param string $code PHP source.
	 * @param string $file Path to report for the hooks found.
	 * @return list<Hook>
	 * @throws RuntimeException When the source cannot be parsed.
	 */
	public static function scan_source( string $code, string $file ): array {
		$statements = ( new ParserFactory() )->createForHostVersion()->parse( $code );
		if ( null === $statements ) {
			throw new RuntimeException( sprintf( 'Cannot parse %s.', $file ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$visitor = new class( $file ) extends NodeVisitorAbstract {

			/**
			 * @var list<Hook>
			 */
			public array $hooks = array();

			private string $file_namespace = '';

			/**
			 * @var array<string, string> Alias => full class name.
			 */
			private array $uses = array();

			private ?Doc $latest_doc = null;

			public function __construct( private readonly string $file ) {
			}

			public function enterNode( Node $node ) {
				if ( $node instanceof Node\Stmt\Namespace_ ) {
					$this->file_namespace = null === $node->name ? '' : $node->name->toString();
					$this->uses           = array();
				} elseif ( $node instanceof Node\Stmt\Use_ && Node\Stmt\Use_::TYPE_NORMAL === $node->type ) {
					foreach ( $node->uses as $use ) {
						$this->uses[ $use->getAlias()->toString() ] = $use->name->toString();
					}
				}

				$doc = $node->getDocComment();
				if ( null !== $doc ) {
					$this->latest_doc = $doc;
				}

				if ( $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall ) {
					$hook = HookScanner::hook_from_call( $node, $this->file, $this->doc_above( $node ), $this->file_namespace, $this->uses );
					if ( null !== $hook ) {
						$this->hooks[] = $hook;
					}
				}
				return null;
			}

			private function doc_above( Node $node ): ?Doc {
				return null !== $this->latest_doc && $this->latest_doc->getEndLine() + 1 === $node->getStartLine() ? $this->latest_doc : null;
			}
		};

		$traverser = new NodeTraverser();
		$traverser->addVisitor( $visitor );
		$traverser->traverse( $statements );
		return $visitor->hooks;
	}

	/**
	 * The hook a call fires, or null when it is not a hook call: a WordPress
	 * hook function, or DeprecatedHooks::filter() or ::action().
	 *
	 * @param Node\Expr\FuncCall|Node\Expr\StaticCall $call The call.
	 * @param string                                  $file      Path to report.
	 * @param Doc|null                                $doc       The docblock right above the call.
	 * @param string                                  $file_namespace The file's namespace.
	 * @param array<string, string>                   $uses      The file's `use` statements, alias => full name.
	 */
	public static function hook_from_call( Node\Expr\FuncCall|Node\Expr\StaticCall $call, string $file, ?Doc $doc, string $file_namespace, array $uses ): ?Hook {
		$function = self::hook_function( $call, $file_namespace, $uses );
		if ( null === $function || $call->isFirstClassCallable() ) {
			return null;
		}
		$args = $call->getArgs();
		if ( array() === $args ) {
			return null;
		}

		$fields = array(
			'name'       => self::hook_name( $args[0]->value ),
			'type'       => self::FUNCTIONS[ $function ],
			'deprecated' => str_ends_with( $function, '_deprecated' ),
			'file'       => $file,
			'line'       => $call->getStartLine(),
			'args'       => self::argument_count( $function, $args ),
		);
		if ( null === $doc ) {
			return new Hook( ...$fields );
		}
		$resolve = static fn ( string $type ): string => self::resolve_type( $type, $file_namespace, $uses );
		return new Hook( ...array_merge( $fields, array( 'documented' => true ), Docblock::parse( $doc->getText(), $resolve ) ) );
	}

	/**
	 * The WordPress hook function a call is, or stands for, lower case; null
	 * when it is not a hook call.
	 *
	 * @param Node\Expr\FuncCall|Node\Expr\StaticCall $call           The call.
	 * @param string                                  $file_namespace The file's namespace.
	 * @param array<string, string>                   $uses           Alias => full name.
	 */
	private static function hook_function( Node\Expr\FuncCall|Node\Expr\StaticCall $call, string $file_namespace, array $uses ): ?string {
		if ( $call instanceof Node\Expr\FuncCall ) {
			$function = $call->name instanceof Node\Name ? $call->name->toLowerString() : '';
			return isset( self::FUNCTIONS[ $function ] ) ? $function : null;
		}
		if ( ! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier ) {
			return null;
		}
		$class = $call->class instanceof Node\Name\FullyQualified
			? $call->class->toString()
			: self::resolve_type( $call->class->toString(), $file_namespace, $uses );
		if ( DeprecatedHooks::class !== $class ) {
			return null;
		}
		return self::DEPRECATION_HELPERS[ $call->name->toLowerString() ] ?? null;
	}

	/**
	 * The hook name as written: a literal's value, an interpolated string with
	 * its `{$variables}`, or the expression itself.
	 *
	 * @param Node\Expr $expr The first argument of the hook call.
	 */
	private static function hook_name( Node\Expr $expr ): string {
		if ( $expr instanceof Node\Scalar\String_ ) {
			return $expr->value;
		}
		$printed = ( new Standard() )->prettyPrintExpr( $expr );
		return $expr instanceof Node\Scalar\InterpolatedString ? substr( $printed, 1, -1 ) : $printed;
	}

	/**
	 * How many arguments a hook passes: the call's own after the name, or the
	 * items of the array a `_ref_array` or `_deprecated` call passes them in.
	 *
	 * @param string     $hook_function The hook function, lower case.
	 * @param Node\Arg[] $args          The call's arguments.
	 */
	private static function argument_count( string $hook_function, array $args ): ?int {
		if ( str_ends_with( $hook_function, '_ref_array' ) || str_ends_with( $hook_function, '_deprecated' ) ) {
			$passed = $args[1]->value ?? null;
			return $passed instanceof Node\Expr\Array_ ? count( $passed->items ) : null;
		}
		return count( $args ) - 1;
	}

	/**
	 * A docblock type with each class name made fully qualified, without the
	 * leading backslash. Generic and array shapes are left as written.
	 *
	 * @param string                $type      E.g. `LineState|null`.
	 * @param string                $file_namespace The file's namespace.
	 * @param array<string, string> $uses      Alias => full name.
	 */
	public static function resolve_type( string $type, string $file_namespace, array $uses ): string {
		$parts = array();
		foreach ( explode( '|', $type ) as $part ) {
			$nullable = str_starts_with( $part, '?' ) ? '?' : '';
			$name     = ltrim( $part, '?' );
			if ( 1 !== preg_match( '/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name ) || in_array( strtolower( $name ), self::BUILTIN_TYPES, true ) ) {
				$parts[] = $part;
				continue;
			}
			if ( str_starts_with( $name, '\\' ) ) {
				$parts[] = $nullable . substr( $name, 1 );
				continue;
			}
			$first   = explode( '\\', $name, 2 );
			$parts[] = $nullable . match ( true ) {
				isset( $uses[ $first[0] ] ) => $uses[ $first[0] ] . ( isset( $first[1] ) ? '\\' . $first[1] : '' ),
				'' === $file_namespace           => $name,
				default                     => $file_namespace . '\\' . $name,
			};
		}
		return implode( '|', $parts );
	}
}
