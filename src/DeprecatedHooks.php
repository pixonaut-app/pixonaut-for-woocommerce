<?php
/**
 * Retiring hooks.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

/**
 * The plugin's hooks are public API: one is deprecated for at least one minor
 * version before a major version removes it.
 *
 * A renamed hook goes into RENAMED_FILTERS or RENAMED_ACTIONS. Callbacks still
 * attached to the old name keep working: they run when the new hook fires,
 * before its own callbacks, and WordPress reports the deprecation. A hook
 * removed without a replacement keeps firing at its call site through
 * filter() or action() until the major version.
 */
final class DeprecatedHooks {

	/**
	 * Renamed filters: old name => array( new name, version that renamed it ).
	 *
	 * @var array<non-empty-string, array{0: non-empty-string, 1: string}>
	 */
	public const RENAMED_FILTERS = array();

	/**
	 * Renamed actions: old name => array( new name, version that renamed it ).
	 *
	 * @var array<non-empty-string, array{0: non-empty-string, 1: string}>
	 */
	public const RENAMED_ACTIONS = array();

	/**
	 * Callbacks on an old name run before every callback on the new one, as
	 * they did before the rename moved them.
	 */
	private const FORWARD_PRIORITY = PHP_INT_MIN;

	/**
	 * Hands the old hook every argument the new one fires with.
	 */
	private const FORWARD_ARGS = PHP_INT_MAX;

	/**
	 * @param array<non-empty-string, array{0: non-empty-string, 1: string}> $renamed_filters Old name => array( new name, version ).
	 * @param array<non-empty-string, array{0: non-empty-string, 1: string}> $renamed_actions Old name => array( new name, version ).
	 */
	public function __construct(
		private readonly array $renamed_filters = self::RENAMED_FILTERS,
		private readonly array $renamed_actions = self::RENAMED_ACTIONS,
	) {
	}

	public function register(): void {
		foreach ( $this->renamed_filters as $old => list( $new, $version ) ) {
			add_filter(
				$new,
				static fn ( mixed ...$args ): mixed => self::filter( $old, array_values( $args ), $version, $new ),
				self::FORWARD_PRIORITY,
				self::FORWARD_ARGS
			);
		}
		foreach ( $this->renamed_actions as $old => list( $new, $version ) ) {
			add_action(
				$new,
				static function ( mixed ...$args ) use ( $old, $version, $new ): void {
					self::action( $old, array_values( $args ), $version, $new );
				},
				self::FORWARD_PRIORITY,
				self::FORWARD_ARGS
			);
		}
	}

	/**
	 * Applies a deprecated filter. WordPress reports the deprecation only
	 * when a callback is attached.
	 *
	 * @param string            $hook        The deprecated filter.
	 * @phpstan-param non-empty-string $hook
	 * @param array<int, mixed> $args        Its arguments, the value first.
	 * @param string            $version     The plugin version that deprecated it.
	 * @param string            $replacement The hook to use instead, if any.
	 * @return mixed The filtered value.
	 */
	public static function filter( string $hook, array $args, string $version, string $replacement = '' ): mixed {
		// The deprecated filter is documented where it fires, or in RENAMED_FILTERS.
		return apply_filters_deprecated( $hook, $args, $version, $replacement, self::message( $version ) );
	}

	/**
	 * Fires a deprecated action. WordPress reports the deprecation only when
	 * a callback is attached.
	 *
	 * @param string            $hook        The deprecated action.
	 * @phpstan-param non-empty-string $hook
	 * @param array<int, mixed> $args        Its arguments.
	 * @param string            $version     The plugin version that deprecated it.
	 * @param string            $replacement The hook to use instead, if any.
	 */
	public static function action( string $hook, array $args, string $version, string $replacement = '' ): void {
		// The deprecated action is documented where it fires, or in RENAMED_ACTIONS.
		do_action_deprecated( $hook, $args, $version, $replacement, self::message( $version ) );
	}

	/**
	 * Says whose version the deprecation notice names, which WordPress would
	 * otherwise read as its own.
	 *
	 * @param string $version The plugin version that deprecated the hook.
	 */
	private static function message( string $version ): string {
		/* translators: %s: plugin version, e.g. 1.2.0. */
		return sprintf( __( 'Deprecated in Pixonaut for WooCommerce %s.', 'pixonaut-for-woocommerce' ), $version );
	}
}
