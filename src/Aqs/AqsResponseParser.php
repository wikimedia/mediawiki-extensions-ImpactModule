<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Aqs;

/**
 * Interprets decoded AQS response bodies into the structures AqsClient
 * promises to its callers.
 *
 * Pure and stateless: arrays in, arrays out, no I/O. Throws AqsError
 * whenever a response does not match the expected shape.
 */
class AqsResponseParser {

	private const SECONDS_PER_DAY = 86400;

	// Field names in AQS daily-series response rows. Kept as constants so a
	// mismatch against the live API is a one-line fix; normalizeDailySeries()
	// throws a descriptive AqsError when the response does not match.
	private const RESPONSE_FIELD_TIMESTAMP = 'timestamp';
	private const RESPONSE_FIELD_COUNT = 'edit_count';

	/**
	 * Convert a decoded AQS daily-series response into a dense, ascending
	 * map of 'Y-m-d' => count covering every day of the requested window,
	 * zero-filled where AQS reported nothing.
	 *
	 * @param array|null $response Decoded response, or null when AQS has no
	 *   data for the range (HTTP 404) — reported as all-zero activity.
	 * @param int $startEpoch First day of the requested window
	 * @param int $endEpoch Last day of the requested window
	 * @return array<string,int>
	 */
	public function normalizeDailySeries( ?array $response, int $startEpoch, int $endEpoch ): array {
		$series = [];
		for ( $day = $startEpoch; $day <= $endEpoch; $day += self::SECONDS_PER_DAY ) {
			$series[gmdate( 'Y-m-d', $day )] = 0;
		}

		if ( $response === null ) {
			return $series;
		}

		foreach ( $this->extractDailyRows( $response ) as $row ) {
			if ( !is_array( $row ) ) {
				throw AqsError::newFromMessage( 'Unexpected AQS response: non-array result row' );
			}

			$timestamp = $row[self::RESPONSE_FIELD_TIMESTAMP] ?? null;
			if ( !is_string( $timestamp ) || !preg_match( '/^(\d{4})-?(\d{2})-?(\d{2})/', $timestamp, $m ) ) {
				throw AqsError::newFromMessage(
					'Unexpected AQS response: cannot parse timestamp from row with keys '
						. implode( ', ', array_keys( $row ) )
				);
			}

			$count = $row[self::RESPONSE_FIELD_COUNT] ?? null;
			if ( !is_numeric( $count ) ) {
				throw AqsError::newFromMessage(
					'Unexpected AQS response: no numeric "' . self::RESPONSE_FIELD_COUNT
						. '" field in row with keys ' . implode( ', ', array_keys( $row ) )
				);
			}

			$date = $m[1] . '-' . $m[2] . '-' . $m[3];
			if ( array_key_exists( $date, $series ) ) {
				// Rows outside the requested window are silently ignored
				$series[$date] = (int)$count;
			}
		}

		return $series;
	}

	/**
	 * Locate the per-day result rows inside an AQS response envelope.
	 *
	 * Handles both known AQS dialects: rows nested in items[0].results
	 * (classic edits endpoints) and rows directly in items (the v3
	 * endpoints, as confirmed against edits/v3/per_editor).
	 */
	private function extractDailyRows( array $response ): array {
		$items = $response['items'] ?? null;
		if ( !is_array( $items ) ) {
			throw AqsError::newFromMessage(
				'Unexpected AQS response: no "items" array (top-level keys: '
					. implode( ', ', array_keys( $response ) ) . ')'
			);
		}

		if ( isset( $items[0]['results'] ) && is_array( $items[0]['results'] ) ) {
			return $items[0]['results'];
		}

		return $items;
	}
}
