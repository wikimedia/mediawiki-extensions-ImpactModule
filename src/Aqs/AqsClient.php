<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Aqs;

use InvalidArgumentException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\User\CentralId\CentralIdLookup;
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

	public function __construct(
		private readonly CentralIdLookup $centralIdLookup,
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

		$jsonStatus = FormatJson::parse( $request->getContent(), FormatJson::FORCE_ASSOC );
		if ( !$jsonStatus->isOK() ) {
			throw AqsError::newFromStatus( $jsonStatus );
		}

		$data = $jsonStatus->getValue();
		if ( !is_array( $data ) ) {
			throw AqsError::newFromMessage( 'AQS returned a non-array' );
		}
		return $data;
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
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( $httpMethod, $url ),
			fn () => $this->downloadAqsUrl( $httpMethod, $url )
		);
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
		$centralUserId = $this->centralIdLookup->centralIdFromLocalUser( $user );
		if ( !$centralUserId ) {
			// No central ID, unable to compute
			// TODO: This also happens for suppressed users, figure out a better behaviour...
			throw new \LogicException( 'No central ID was returned for user #' . $user->getId() );
		}

		[ $startEpoch, $endEpoch ] = $this->getDailyRange( $lastDays );

		$response = $this->accessAqsEndpoint( [
			'edits', 'v3', 'per_editor',
			$centralUserId,
			$pageType->value, 'daily',
			gmdate( 'Ymd', $startEpoch ), gmdate( 'Ymd', $endEpoch ),
		] );

		return $this->responseParser->normalizeDailySeries( $response, $startEpoch, $endEpoch );
	}
}
