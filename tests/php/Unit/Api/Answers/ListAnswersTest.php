<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Pixonaut\WooCommerce\Api\Answers\DesignerLink;
use Pixonaut\WooCommerce\Api\Answers\EventsPage;
use Pixonaut\WooCommerce\Api\Answers\PrintSizeVerdicts;
use Pixonaut\WooCommerce\Api\Answers\ShareLinkPage;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Webhooks\EventsPull;

final class ListAnswersTest extends TestCase {

	/**
	 * When the listed events happened, in milliseconds since the Unix epoch.
	 */
	private const CREATED_AT = 1_800_000_000_000;

	private ScriptedClient $transport;

	private Sdk $sdk;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new ScriptedClient();
		$this->sdk       = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	public function test_an_events_page_keeps_each_envelope_as_sent(): void {
		$events = array(
			array(
				'id'        => 'evt_1',
				'type'      => 'design.approved',
				'createdAt' => self::CREATED_AT,
				'payload'   => array( 'designId' => 'd1' ),
				'future'    => true,
			),
			array(
				'id'        => 'evt_2',
				'type'      => 'automation.webhook',
				'createdAt' => self::CREATED_AT,
			),
		);
		$body   = array(
			'events'     => $events,
			'nextCursor' => null,
		);
		$this->transport->off_spec( self::answer( $body ) );

		$page = EventsPage::get( $this->sdk, 'evt_0', 'design.approved' );

		$this->assertSame( $events, $page->events );
		$this->assertNull( $page->next_cursor );
		$read = array(
			'events'      => $page->events,
			'next_cursor' => $page->next_cursor,
		);
		$this->assertSame( 'evt_2', EventsPull::moved_on( 'evt_0', $read ) );
		$this->assertStringContainsString( 'after=evt_0&types=design.approved', $this->transport->sent[0]['url'] );
	}

	public function test_an_events_page_without_its_cursor_is_unexpected(): void {
		$this->transport->off_spec( self::answer( array( 'events' => array() ) ) );

		$this->expectException( ApiException::class );
		EventsPage::get( $this->sdk, null, 'design.approved' );
	}

	public function test_an_events_page_with_an_event_that_is_no_envelope_is_unexpected(): void {
		$body = array(
			'events'     => array(
				array(
					'id'      => 'evt_1',
					'type'    => 'design.approved',
					'payload' => array( 'designId' => 'd1' ),
				),
			),
			'nextCursor' => null,
		);
		$this->transport->off_spec( self::answer( $body ) );

		try {
			EventsPage::get( $this->sdk, null, 'design.approved' );
			$this->fail( 'An event without its createdAt was read.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'unexpected_response', $e->errorCode );
			$previous = $e->getPrevious();
			$this->assertInstanceOf( UnexpectedResponse::class, $previous );
			$this->assertSame( '$.events[0]: missing required field "createdAt"', $previous->getMessage() );
		}
	}

	public function test_a_designer_link_reads_its_warnings(): void {
		$link = array(
			'id'       => 'sl_1',
			'url'      => 'https://app.pixonaut.test/d/abc',
			'warnings' => array(
				array(
					'code'        => 'sides_over_recommended',
					'sides'       => 6,
					'recommended' => 4,
				),
			),
		);
		$this->transport->off_spec( self::answer( $link + array( 'token' => 'secret' ) ) );

		$made = DesignerLink::create( $this->sdk, 'tpl_1', array( 'name' => 'x' ) );

		$this->assertSame( 'sl_1', $made->id );
		$this->assertSame( 'https://app.pixonaut.test/d/abc', $made->url );
		$warning = $made->warnings[0] ?? null;
		$this->assertNotNull( $warning );
		$this->assertSame( array( 'sides_over_recommended', 6, 4 ), array( $warning->code, $warning->sides, $warning->recommended ) );
	}

	public function test_a_designer_link_without_warnings_has_none(): void {
		$body = array(
			'id'  => 'sl_1',
			'url' => 'https://app.pixonaut.test/d/abc',
		);
		$this->transport->off_spec( self::answer( $body ) );

		$this->assertNull( DesignerLink::create( $this->sdk, 'tpl_1', array() )->warnings );
	}

	public function test_a_share_link_page_reads_id_and_address(): void {
		$body = array(
			'links' => array(
				array(
					'id'   => 'sl_1',
					'url'  => null,
					'kind' => 'designer',
				),
				array(
					'id'  => 'sl_2',
					'url' => 'https://x.test/y',
				),
			),
		);
		$this->transport->off_spec( self::answer( $body ) );

		$page = ShareLinkPage::get(
			$this->sdk,
			array(
				'page' => 1,
				'q'    => null,
			)
		);

		$this->assertSame( array( 'sl_1', 'sl_2' ), array( $page->links[0]->id, $page->links[1]->id ) );
		$this->assertNull( $page->links[0]->url );
		$this->assertSame( 'https://x.test/y', $page->links[1]->url );
	}

	public function test_a_share_link_without_its_id_is_unexpected(): void {
		$this->transport->off_spec( self::answer( array( 'links' => array( array( 'url' => 'https://x.test/y' ) ) ) ) );

		$this->expectException( ApiException::class );
		ShareLinkPage::get( $this->sdk, array() );
	}

	public function test_print_size_verdicts_carry_a_reason_only_for_a_size_that_is_not_legal(): void {
		$verdicts = PrintSizeVerdicts::from_answer(
			array(
				'verdicts' => array(
					array( 'legal' => true ),
					array(
						'legal'  => false,
						'reason' => 'print_size_illegal',
					),
				),
			)
		)->verdicts;

		$this->assertTrue( $verdicts[0]->legal );
		$this->assertNull( $verdicts[0]->reason );
		$this->assertFalse( $verdicts[1]->legal );
		$this->assertSame( 'print_size_illegal', $verdicts[1]->reason );
	}

	public function test_a_size_that_is_not_legal_without_a_reason_is_unexpected(): void {
		$this->expectException( UnexpectedResponse::class );
		$this->expectExceptionMessage( '$.verdicts[0]: missing required field "reason"' );
		PrintSizeVerdicts::from_answer( array( 'verdicts' => array( array( 'legal' => false ) ) ) );
	}

	/**
	 * @param array<mixed> $body The answer's body.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}
}
