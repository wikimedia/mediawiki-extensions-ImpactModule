<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Unit\Aqs;

use MediaWiki\Extension\ImpactModule\Aqs\AqsError;
use MediaWiki\Extension\ImpactModule\Aqs\AqsResponseParser;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Aqs\AqsResponseParser
 */
class AqsResponseParserTest extends MediaWikiUnitTestCase {

	/** 2024-01-01T00:00:00Z */
	private const WINDOW_START = 1704067200;
	/** 2024-01-03T00:00:00Z */
	private const WINDOW_END = 1704240000;

	public static function provideNormalizeDailySeries(): iterable {
		yield 'null response (AQS 404) yields zero-filled window' => [
			null,
			[ '2024-01-01' => 0, '2024-01-02' => 0, '2024-01-03' => 0 ],
		];

		yield 'empty items yields zero-filled window' => [
			[ 'items' => [] ],
			[ '2024-01-01' => 0, '2024-01-02' => 0, '2024-01-03' => 0 ],
		];

		yield 'classic dialect: rows in items[0].results, missing days zero-filled' => [
			[
				'items' => [
					[
						'results' => [
							[ 'timestamp' => '2024-01-01T00:00:00.000Z', 'edit_count' => 5 ],
							[ 'timestamp' => '2024-01-03T00:00:00.000Z', 'edit_count' => 2 ],
						],
					],
				],
			],
			[ '2024-01-01' => 5, '2024-01-02' => 0, '2024-01-03' => 2 ],
		];

		yield 'v3 dialect: rows directly in items' => [
			[
				'items' => [
					[ 'timestamp' => '2024-01-02', 'edit_count' => 7 ],
				],
			],
			[ '2024-01-01' => 0, '2024-01-02' => 7, '2024-01-03' => 0 ],
		];

		yield 'compact YYYYMMDDHH timestamps are parsed' => [
			[
				'items' => [
					[ 'timestamp' => '2024010100', 'edit_count' => 3 ],
				],
			],
			[ '2024-01-01' => 3, '2024-01-02' => 0, '2024-01-03' => 0 ],
		];

		yield 'numeric string counts are cast to int' => [
			[
				'items' => [
					[ 'timestamp' => '2024-01-02', 'edit_count' => '4' ],
				],
			],
			[ '2024-01-01' => 0, '2024-01-02' => 4, '2024-01-03' => 0 ],
		];

		yield 'rows outside the requested window are ignored' => [
			[
				'items' => [
					[ 'timestamp' => '2023-12-31', 'edit_count' => 9 ],
					[ 'timestamp' => '2024-01-02', 'edit_count' => 1 ],
					[ 'timestamp' => '2024-01-04', 'edit_count' => 9 ],
				],
			],
			[ '2024-01-01' => 0, '2024-01-02' => 1, '2024-01-03' => 0 ],
		];
	}

	/**
	 * @dataProvider provideNormalizeDailySeries
	 */
	public function testNormalizeDailySeries( ?array $response, array $expected ) {
		$parser = new AqsResponseParser();
		$this->assertSame(
			$expected,
			$parser->normalizeDailySeries( $response, self::WINDOW_START, self::WINDOW_END )
		);
	}

	public function testNormalizeDailySeriesSingleDayWindow() {
		$parser = new AqsResponseParser();
		$this->assertSame(
			[ '2024-01-01' => 8 ],
			$parser->normalizeDailySeries(
				[ 'items' => [ [ 'timestamp' => '2024-01-01', 'edit_count' => 8 ] ] ],
				self::WINDOW_START,
				self::WINDOW_START
			)
		);
	}

	public static function provideMalformedResponses(): iterable {
		yield 'no items key' => [
			[ 'detail' => 'oops' ],
			'no "items" array (top-level keys: detail)',
		];

		yield 'items is not an array' => [
			[ 'items' => 'oops' ],
			'no "items" array',
		];

		yield 'non-array result row' => [
			[ 'items' => [ 'oops' ] ],
			'non-array result row',
		];

		yield 'row without timestamp' => [
			[ 'items' => [ [ 'edit_count' => 5 ] ] ],
			'cannot parse timestamp from row with keys edit_count',
		];

		yield 'row with unparseable timestamp' => [
			[ 'items' => [ [ 'timestamp' => 'yesterday', 'edit_count' => 5 ] ] ],
			'cannot parse timestamp',
		];

		yield 'row without edit_count' => [
			[ 'items' => [ [ 'timestamp' => '2024-01-01' ] ] ],
			'no numeric "edit_count" field in row with keys timestamp',
		];

		yield 'row with non-numeric edit_count' => [
			[ 'items' => [ [ 'timestamp' => '2024-01-01', 'edit_count' => 'many' ] ] ],
			'no numeric "edit_count" field',
		];
	}

	/**
	 * @dataProvider provideMalformedResponses
	 */
	public function testNormalizeDailySeriesRejectsMalformedResponses(
		array $response,
		string $expectedMessagePart
	) {
		$parser = new AqsResponseParser();
		$this->expectException( AqsError::class );
		$this->expectExceptionMessage( $expectedMessagePart );
		$parser->normalizeDailySeries( $response, self::WINDOW_START, self::WINDOW_END );
	}
}
