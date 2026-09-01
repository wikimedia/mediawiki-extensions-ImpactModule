<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Unit\Metrics;

use LogicException;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use MediaWikiUnitTestCase;
use Wikimedia\JsonCodec\JsonCodec;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\MetricResult
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\Value\PrimitiveMetricValue
 */
class MetricResultTest extends MediaWikiUnitTestCase {

	public static function provideSerialisation() {
		return [
			'ready, int' => [ MetricResult::ready( 12 ) ],
			'ready, float' => [ MetricResult::ready( 12.5 ) ],
			'pending' => [ MetricResult::pending() ],
			'error' => [ MetricResult::error() ],
			'disabled' => [ MetricResult::disabled() ],
		];
	}

	/**
	 * @dataProvider provideSerialisation
	 */
	public function testSerialisation( MetricResult $result ) {
		$codec = new JsonCodec();
		$json = $codec->toJsonArray( $result, MetricResult::class );

		// the serialised form is stored in the WAN cache and must not contain
		// any PHP objects (T435969)
		$this->assertSame( $json, json_decode( json_encode( $json ), true ) );

		$this->assertEquals(
			$result,
			$codec->newFromJsonArray( $json, MetricResult::class )
		);
	}

	public function testToString() {
		$this->assertSame( '12', strval( MetricResult::ready( 12 )->getValue() ) );
	}

	public function testGetValueOnError() {
		$this->expectException( LogicException::class );
		// error don't have values
		MetricResult::error()->getValue();
	}
}
