<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Unit\Metrics;

use LogicException;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use MediaWiki\Extension\ImpactModule\Metrics\Value\IMetricValue;
use MediaWiki\Json\FormatJson;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\MetricResult
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\Value\PrimitiveMetricValue
 */
class MetricResultTest extends MediaWikiUnitTestCase {

	public static function provideSerialisation() {
		return [
			'ready, object' => [
				FormatJson::encode( [ 'state' => 'ready', 'value' => [ 'a', 'b' ] ] ),
				MetricResult::ready( new class implements IMetricValue {
					public function __toString(): string {
						return '12';
					}

					public function jsonSerialize(): mixed {
						return [ 'a', 'b' ];
					}
				} ),
			],
			'ready, int' => [
				FormatJson::encode( [ 'state' => 'ready', 'value' => 12 ] ),
				MetricResult::ready( 12 ),
			],
			'pending' => [
				FormatJson::encode( [ 'state' => 'pending' ] ),
				MetricResult::pending(),
			],
			'error' => [
				FormatJson::encode( [ 'state' => 'error' ] ),
				MetricResult::error(),
			],
			'disabled' => [
				FormatJson::encode( [ 'state' => 'disabled' ] ),
				MetricResult::disabled(),
			],
		];
	}

	/**
	 * @dataProvider provideSerialisation
	 */
	public function testSerialisation( string $expected, MetricResult $result ) {
		$this->assertSame( $expected, FormatJson::encode( $result ) );
	}

	public function testGetValueOnError() {
		$this->expectException( LogicException::class );
		// error don't have values
		MetricResult::error()->getValue();
	}
}
