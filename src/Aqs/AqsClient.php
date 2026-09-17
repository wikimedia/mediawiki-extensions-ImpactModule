<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Aqs;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Extension\ImpactModule\User\CentralUserIdResolver;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\User\UserIdentity;
use Wikimedia\MapCacheLRU\MapCacheLRU;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class AqsClient {

	/**
	 * Number of days AQS may need to ingest new data.
	 *
	 * Requested date ranges end this many days before today, so that every
	 * day the client reports on is fully ingested: a zero in a result
	 * always means "no activity", never "not processed yet".
	 */
	public const INGESTION_LAG_DAYS = 2;

	private const SECONDS_PER_DAY = 86400;
	private const int AQS_TIMEOUT = 5;

	private readonly AqsResponseParser $responseParser;
	private readonly MapCacheLRU $cache;
	private bool $collecting = false;
	/** @var array<string,true> Set of URLs registered while collecting */
	private array $pendingUrls = [];

	public function __construct(
		private readonly CentralUserIdResolver $centralUserIdResolver,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly string $baseUrl
	) {
		$this->responseParser = new AqsResponseParser();
		$this->cache = new MapCacheLRU( 1000 );
	}

	/**
	 * Compute the date range covering the $lastDays most recent fully
	 * ingested days (UTC), as inclusive epoch timestamps.
	 *
	 * Returned as epoch seconds (not formatted dates) so that the URL and
	 * the normalized series are guaranteed to be built from the same window,
	 * even if the computation happens to straddle a UTC midnight.
	 *
	 * @return array{int,int} [ start, end ]
	 */
	private function getDailyRange( int $lastDays ): array {
		if ( $lastDays < 1 ) {
			throw new InvalidArgumentException(
				'$lastDays must be at least 1, got ' . $lastDays
			);
		}

		$end = ConvertibleTimestamp::time() - self::INGESTION_LAG_DAYS * self::SECONDS_PER_DAY;
		$start = $end - ( $lastDays - 1 ) * self::SECONDS_PER_DAY;
		return [ $start, $end ];
	}

	/**
	 * The central user ID that AQS per-editor endpoints are keyed by.
	 *
	 * Callers are expected to have established that the subject has one, which
	 * is what IMetric::isAvailableForUser() is for; reaching here without one
	 * means the two disagree.
	 *
	 * @throws LogicException when the user has no central ID
	 */
	private function getCentralUserId( UserIdentity $user ): int {
		$centralUserId = $this->centralUserIdResolver->getCentralUserId( $user );
		if ( !$centralUserId ) {
			// No central ID, unable to compute
			// TODO: This also happens for suppressed users, figure out a better behaviour...
			throw new LogicException( 'No central ID was returned for user #' . $user->getId() );
		}
		return $centralUserId;
	}

	/**
	 * Start registering requested URLs instead of fetching them.
	 *
	 * While collecting, an endpoint access that is not memoized yet registers
	 * its URL and returns the same "no data" value an HTTP 404 produces, so
	 * callers run to completion without performing any I/O. Pass over the
	 * callers once to let them declare what they need, then call
	 * flushCollected() to fetch all of it at once.
	 */
	public function startCollecting(): void {
		$this->collecting = true;
	}

	/**
	 * Fetch everything registered since startCollecting() in one parallel
	 * batch, and memoize the results.
	 *
	 * Stops collecting, whether or not anything was registered.
	 *
	 * Never throws: a request that failed is memoized as its own error and
	 * rethrown when a caller actually asks for that URL, so one broken
	 * endpoint neither takes out the whole batch nor gets quietly retried one
	 * request at a time afterwards.
	 */
	public function flushCollected(): void {
		$this->collecting = false;
		$urls = array_keys( $this->pendingUrls );
		$this->pendingUrls = [];
		if ( !$urls ) {
			return;
		}

		$httpMethod = 'GET';
		$reqs = [];
		foreach ( $urls as $url ) {
			// Keyed by URL, so a request that several callers asked for is made once
			$reqs[$url] = [ 'method' => $httpMethod, 'url' => $url ];
		}

		$results = $this->httpRequestFactory
			->createMultiClient( [ 'reqTimeout' => self::AQS_TIMEOUT ] )
			->runMulti( $reqs, [], __METHOD__ );

		foreach ( $results as $url => $req ) {
			$cacheKey = $this->cache->makeKey( $httpMethod, $url );
			try {
				$this->cache->set( $cacheKey, $this->interpretMultiResponse( $req['response'] ) );
			} catch ( AqsError $e ) {
				$this->cache->set( $cacheKey, $e );
			}
		}
	}

	/**
	 * Interpret one runMulti() response map the way downloadAqsUrl()
	 * interprets a single request.
	 *
	 * @return array|null Decoded response, or null when AQS has no data
	 * @throws AqsError on transport failure or an unexpected response shape
	 */
	private function interpretMultiResponse( array $response ): ?array {
		$code = (int)$response['code'];
		if ( $code === 404 ) {
			// No data in AQS
			return null;
		}
		if ( $response['error'] !== '' || $code < 200 || $code > 299 ) {
			throw AqsError::newFromMessage(
				'AQS request failed with HTTP ' . $code
					. ( $response['error'] !== '' ? ': ' . $response['error'] : '' )
			);
		}
		return $this->parseResponseBody( (string)$response['body'] );
	}

	/**
	 * @throws AqsError when the body is not a JSON array
	 */
	private function parseResponseBody( string $body ): array {
		$jsonStatus = FormatJson::parse( $body, FormatJson::FORCE_ASSOC );
		if ( !$jsonStatus->isOK() ) {
			throw AqsError::newFromStatus( $jsonStatus );
		}

		$data = $jsonStatus->getValue();
		if ( !is_array( $data ) ) {
			throw AqsError::newFromMessage( 'AQS returned a non-array' );
		}
		return $data;
	}

	private function downloadAqsUrl( string $httpMethod, string $url ): ?array {
		$request = $this->httpRequestFactory->create(
			$url,
			[
				'method' => $httpMethod,
				'timeout' => self::AQS_TIMEOUT,
			],
			__METHOD__
		);
		$httpStatus = $request->execute();
		if ( !$httpStatus->isOK() ) {
			// Request failed: either HTTP 404 (means no data in AQS), or something else
			// TODO: log this somehow?

			if ( $request->getStatus() === 404 ) {
				return null;
			}

			throw AqsError::newFromStatus( $httpStatus );
		}

		return $this->parseResponseBody( $request->getContent() );
	}

	/**
	 * Build AQS URL and download it
	 *
	 * This implements memoization, to ensure the same URL is not accessed multiple times.
	 */
	private function accessAqsEndpoint( array $params ): ?array {
		// Constant right now, but used in two places
		$httpMethod = 'GET';
		$url = implode( '/', array_merge( [ $this->baseUrl ], $params ) );
		$cacheKey = $this->cache->makeKey( $httpMethod, $url );

		if ( $this->collecting && !$this->cache->has( $cacheKey ) ) {
			$this->pendingUrls[$url] = true;
			// The value an HTTP 404 yields, so the caller completes without
			// any I/O. A collecting pass discards its result anyway.
			return null;
		}

		$response = $this->cache->getWithSetCallback(
			$cacheKey,
			function () use ( $httpMethod, $url ) {
				try {
					return $this->downloadAqsUrl( $httpMethod, $url );
				} catch ( AqsError $e ) {
					// Memoized like a batched failure, rather than left for the
					// next caller of this URL to request all over again
					return $e;
				}
			}
		);
		if ( $response instanceof AqsError ) {
			// Memoized failure, from either path
			throw $response;
		}
		return $response;
	}

	/**
	 * Number of edits the user made per day, over the $lastDays most recent
	 * fully ingested days.
	 *
	 * @return array<string,int> 'Y-m-d' => edit count; dense (every day of
	 *   the window present), ascending. All-zero when AQS has no data.
	 * @throws AqsError on transport failure or an unexpected response shape
	 */
	public function getEditsPerDay(
		UserIdentity $user,
		AqsPageType $pageType,
		int $lastDays
	): array {
		[ $startEpoch, $endEpoch ] = $this->getDailyRange( $lastDays );

		$response = $this->accessAqsEndpoint( [
			'edits', 'v3', 'per_editor',
			$this->getCentralUserId( $user ),
			$pageType->value, 'daily',
			gmdate( 'Ymd', $startEpoch ), gmdate( 'Ymd', $endEpoch ),
		] );

		return $this->responseParser->normalizeDailySeries( $response, $startEpoch, $endEpoch );
	}
}
