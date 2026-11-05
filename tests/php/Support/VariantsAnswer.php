<?php
/**
 * Answers every read of a blank's variants, beside a scripted recorder.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * Saving or re-checking a product on a blank reads the blank's variants
 * last. Tests about something else answer that read here, ahead of an
 * `HttpRecorder`, so their scripts and the requests they assert stay as
 * they were; a test about variants sets `$answer`.
 */
final class VariantsAnswer {

	/**
	 * Ahead of `HttpRecorder`, which filters at the default priority.
	 */
	private const PRIORITY = 5;

	/**
	 * `pre_http_request` passes three arguments.
	 */
	private const ARGS = 3;

	private const PATH = '#/v1/blanks/[^/?]+/variants$#';

	/**
	 * The answer every read gets.
	 *
	 * @var array<string, mixed>
	 */
	public array $answer;

	/**
	 * The answer a read in a language gets, by `lang`; a language left out
	 * gets `$answer`, as a blank without names in it reads as written.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $in_language = array();

	/**
	 * The `lang` each read asked for, in order; null for none.
	 *
	 * @var list<string|null>
	 */
	public array $languages = array();

	/**
	 * Set to fail every read as Pixonaut being unreachable does.
	 *
	 * @var \WP_Error|null
	 */
	public ?\WP_Error $error = null;

	/**
	 * Run once a read arrives, before it is answered: what happens while
	 * the plugin waits on Pixonaut.
	 *
	 * @var (\Closure(): void)|null
	 */
	public ?\Closure $meanwhile = null;

	/**
	 * How many reads were answered.
	 *
	 * @var int
	 */
	public int $reads = 0;

	public function __construct() {
		$this->answer = BlankFixture::variants();
	}

	public function start(): self {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), self::PRIORITY, self::ARGS );
		return $this;
	}

	public function stop(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), self::PRIORITY );
	}

	/**
	 * @param false|array<string, mixed>|\WP_Error $preempt What an earlier filter answered.
	 * @param array<string, mixed>                 $args    The request's arguments.
	 * @param string                               $url     The request's URL.
	 * @return false|array<string, mixed>|\WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( false !== $preempt || 'GET' !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) || 1 !== preg_match( self::PATH, $path ) ) {
			return $preempt;
		}
		++$this->reads;
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$language          = is_string( $query['lang'] ?? null ) ? $query['lang'] : null;
		$this->languages[] = $language;
		if ( null !== $this->meanwhile ) {
			( $this->meanwhile )();
		}
		if ( null !== $this->error ) {
			return $this->error;
		}
		$body = (string) wp_json_encode( null === $language ? $this->answer : ( $this->in_language[ $language ] ?? $this->answer ) );
		OpenApiAssert::response( 'GET', ScriptedTransport::target( $url ), 200, $body, 'application/json' );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
