<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Integration\Aqs;

use MediaWiki\Extension\ImpactModule\Aqs\AqsClient;
use MediaWiki\Extension\ImpactModule\Aqs\AqsError;
use MediaWiki\Extension\ImpactModule\Aqs\AqsPageType;
use MediaWiki\Extension\ImpactModule\ImpactModuleServices;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Json\FormatJson;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use StatusValue;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Tests of AqsClient's collect-then-flush mode and of the memoization it
 * shares with the one-request-at-a-time path.
 *
 * Except where a test says otherwise, each one installs a MultiHttpClient,
 * which makes any call to HttpRequestFactory::create() fail the test. That is
 * the point: it proves the batch is the only I/O, and that nothing is quietly
 * fetched one request at a time afterwards.
 *
 * @covers \MediaWiki\Extension\ImpactModule\Aqs\AqsClient
 * @covers \MediaWiki\Extension\ImpactModule\Aqs\AqsError
 */
class AqsClientPrefetchTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	private const BASE_URL = 'https://aqs.test/metrics';
	private const CENTRAL_ID = 12345;
	/** 2 days, ending INGESTION_LAG_DAYS before the faked today */
	private const LAST_DAYS = 2;
	private const EDITS_URL = self::BASE_URL
		. '/edits/v3/per_editor/12345/content/daily/20260113/20260114';
	private const EDITS_ALL_URL = self::BASE_URL
		. '/edits/v3/per_editor/12345/all_page_types/daily/20260113/20260114';

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( '20260116000000' );
		$this->overrideConfigValue( 'ImpactModuleAqsBaseURL', self::BASE_URL );
	}

	private function getUser(): UserIdentity {
		return new UserIdentityValue( 1, 'Admin' );
	}

	/**
	 * Which central ID a local user has is not this class's concern, and the
	 * answer depends on which CentralIdLookup provider is installed, so pin it.
	 * These tests then exercise AqsClient either way and need no database.
	 *
	 * Pinned here rather than in setUp() because installMockHttp() goes through
	 * setService(), which resets every service instance that was not itself set
	 * that way - the resolver included, and with it anything pinned on it.
	 */
	private function getClient(): AqsClient {
		$imServices = ImpactModuleServices::wrap( $this->getServiceContainer() );
		$imServices->getCentralUserIdResolver()
			->setDebugCentralUserId( self::CENTRAL_ID );
		return $imServices->getAqsClient();
	}

	private static function dailySeries( string $countField, array $countByDate ): string {
		$items = [];
		foreach ( $countByDate as $date => $count ) {
			$items[] = [ 'timestamp' => $date . 'T00:00:00.000Z', $countField => $count ];
		}
		return FormatJson::encode( [ 'items' => $items ] );
	}

	public function testFlushCollectedFetchesEverythingAtOnce() {
		$this->installMockHttp( $this->makeFakeHttpMultiClient( [
			self::EDITS_URL => self::dailySeries( 'edit_count', [ '2026-01-13' => 3 ] ),
			self::EDITS_ALL_URL => self::dailySeries( 'edit_count', [ '2026-01-14' => 9 ] ),
		] ) );

		$client = $this->getClient();
		$user = $this->getUser();

		$client->startCollecting();
		$this->assertSame(
			[ '2026-01-13' => 0, '2026-01-14' => 0 ],
			$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS ),
			'a collecting call reports no data instead of fetching'
		);
		$client->getEditsPerDay( $user, AqsPageType::All, self::LAST_DAYS );
		$client->flushCollected();

		$this->assertSame(
			[ '2026-01-13' => 3, '2026-01-14' => 0 ],
			$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS ),
			'the edits series comes from the batch'
		);
		$this->assertSame(
			[ '2026-01-13' => 0, '2026-01-14' => 9 ],
			$client->getEditsPerDay( $user, AqsPageType::All, self::LAST_DAYS ),
			'the second series comes from the same batch'
		);
	}

	public function testFlushCollectedMakesOneRequestPerDistinctUrl() {
		$collectedReqs = [];
		$multiClient = $this->createNoOpMock( MultiHttpClient::class, [ 'runMulti' ] );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $reqs ) use ( &$collectedReqs ) {
				$collectedReqs = $reqs;
				foreach ( $reqs as &$req ) {
					$req['response'] = [
						'code' => 404, 'reason' => '', 'headers' => [], 'body' => '', 'error' => '',
					];
				}
				return $reqs;
			}
		);
		$this->installMockHttp( $multiClient );

		$client = $this->getClient();
		$user = $this->getUser();

		$client->startCollecting();
		// The same request declared twice, as two metrics reading one series would
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		$client->getEditsPerDay( $user, AqsPageType::All, self::LAST_DAYS );
		$client->flushCollected();

		$this->assertSame(
			[ self::EDITS_URL, self::EDITS_ALL_URL ],
			array_keys( $collectedReqs ),
			'duplicate declarations collapse into a single request'
		);
	}

	public function testFlushCollectedHandlesNoData() {
		$this->installMockHttp( $this->makeFakeHttpMultiClient( [
			self::EDITS_URL => [ 'code' => 404 ],
		] ) );

		$client = $this->getClient();
		$user = $this->getUser();

		$client->startCollecting();
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		$client->flushCollected();

		$this->assertSame(
			[ '2026-01-13' => 0, '2026-01-14' => 0 ],
			$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS ),
			'an HTTP 404 in the batch is memoized as no activity'
		);
	}

	public function testFlushCollectedMemoizesFailures() {
		$this->installMockHttp( $this->makeFakeHttpMultiClient( [
			self::EDITS_URL => [ 'code' => 500, 'reason' => 'Internal Server Error' ],
		] ) );

		$client = $this->getClient();
		$user = $this->getUser();

		$client->startCollecting();
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		// A failing request must not make flushing itself throw
		$client->flushCollected();

		// Rethrown on access rather than retried one request at a time
		$this->expectException( AqsError::class );
		$this->expectExceptionMessage( 'AQS request failed with HTTP 500' );
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
	}

	public function testFlushCollectedReportsTransportFailures() {
		$this->installMockHttp( $this->makeFakeHttpMultiClient( [
			self::EDITS_URL => [ 'code' => 0, 'error' => 'could not connect' ],
		] ) );

		$client = $this->getClient();
		$user = $this->getUser();

		$client->startCollecting();
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		$client->flushCollected();

		// A response that never arrived is reported as a transport error
		$this->expectException( AqsError::class );
		$this->expectExceptionMessage( 'HTTP 0: could not connect' );
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
	}

	public function testFlushCollectedWithNothingCollected() {
		$this->installMockHttp( $this->createNoOpMock( MultiHttpClient::class ) );

		$client = $this->getClient();
		$client->startCollecting();
		// No endpoint accessed, so runMulti() must not be called either
		$client->flushCollected();
		$this->assertTrue( true );
	}

	/**
	 * Unlike the rest of this class, this drives the path that fetches one
	 * request at a time, to check it memoizes a failure the way a batch does.
	 */
	public function testAFailureOutsideABatchIsMemoizedToo() {
		$httpStatus = StatusValue::newFatal( 'http' );
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->expects( $this->once() )
			->method( 'execute' )
			->willReturn( $httpStatus );
		$mockRequest->method( 'getStatus' )
			->willReturn( 500 );
		$this->installMockHttp( $mockRequest );

		$client = $this->getClient();
		$user = $this->getUser();

		// Two callers wanting the same URL, one request between them, and the
		// same error reported to both
		for ( $i = 0; $i < 2; $i++ ) {
			try {
				$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
				$this->fail( 'Expected AqsError on attempt ' . $i );
			} catch ( AqsError $e ) {
				$this->assertSame( $httpStatus, $e->getStatusValue() );
			}
		}
	}

	public function testAlreadyMemoizedRequestsAreNotCollectedAgain() {
		$collectedReqs = [];
		$multiClient = $this->createNoOpMock( MultiHttpClient::class, [ 'runMulti' ] );
		$multiClient->method( 'runMulti' )->willReturnCallback(
			static function ( array $reqs ) use ( &$collectedReqs ) {
				$collectedReqs[] = array_keys( $reqs );
				foreach ( $reqs as &$req ) {
					$req['response'] = [
						'code' => 404, 'reason' => '', 'headers' => [], 'body' => '', 'error' => '',
					];
				}
				return $reqs;
			}
		);
		$this->installMockHttp( $multiClient );

		$client = $this->getClient();
		$user = $this->getUser();

		$client->startCollecting();
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		$client->flushCollected();

		$client->startCollecting();
		$client->getEditsPerDay( $user, AqsPageType::Content, self::LAST_DAYS );
		$client->flushCollected();

		$this->assertSame(
			[ [ self::EDITS_URL ] ],
			$collectedReqs,
			'a second collecting pass does not re-request what is already memoized'
		);
	}
}
