<?php

namespace MediaWiki\Extension\ImpactModule\Tests\Unit\Metrics;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ImpactModule\Metrics\Metric\IMetric;
use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricFactory;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use MediaWiki\Extension\ImpactModule\Metrics\MetricState;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\MetricComputer
 */
class MetricComputerTest extends MediaWikiUnitTestCase {

	private function getAvailableMetricMock( UserIdentity $user ) {
		$metric = $this->createMock( IMetric::class );
		$metric->expects( $this->once() )
			->method( 'isAvailableForUser' )
			->with( $user )
			->willReturn( true );
		return $metric;
	}

	private function getFactoryMock( string $metricId, IMetric $metric ) {
		$factory = $this->createNoOpMock( MetricFactory::class, [ 'getMetric' ] );
		$factory->expects( $this->once() )
			->method( 'getMetric' )
			->with( $metricId )
			->willReturn( $metric );
		return $factory;
	}

	private function getComputer( $factory, array $configOverrides = [] ): MetricComputer {
		return new MetricComputer(
			new HashConfig( $configOverrides ),
			new NullLogger(),
			$factory
		);
	}

	public function testGetMetricResultDisabled() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$metric = $this->createNoOpMock( IMetric::class, [ 'isAvailableForUser' ] );
		$metric->expects( $this->once() )
			->method( 'isAvailableForUser' )
			->with( $user )
			->willReturn( false );

		$this->assertSame(
			MetricState::Disabled,
			$this->getComputer( $this->getFactoryMock( 'metric-id', $metric ) )
				->getMetricResult( 'metric-id', $user )
				->getState()
		);
	}

	public function testGetMetricResultSuccess() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$result = MetricResult::ready( 42 );

		$metric = $this->getAvailableMetricMock( $user );
		$metric->expects( $this->once() )
			->method( 'computeMetric' )
			->with( $user )
			->willReturn( $result );

		$this->assertSame(
			$result,
			$this->getComputer( $this->getFactoryMock( 'metric-id', $metric ) )
				->getMetricResult( 'metric-id', $user )
		);
	}

	public function testGetMetricResultFailure() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$metric = $this->getAvailableMetricMock( $user );
		$metric->expects( $this->once() )
			->method( 'computeMetric' )
			->with( $user )
			->willThrowException( new RuntimeException( 'Metric computation failure' ) );

		$this->assertSame(
			MetricState::Error,
			$this->getComputer(
				$this->getFactoryMock( 'metric-id', $metric ),
				[ 'ImpactModuleThrowOnFailures' => false ]
			)->getMetricResult( 'metric-id', $user )
				->getState()
		);
	}

	public function testGetMetricResultFailureThrown() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$metric = $this->getAvailableMetricMock( $user );
		$metric->expects( $this->once() )
			->method( 'computeMetric' )
			->with( $user )
			->willThrowException( new RuntimeException( 'Metric computation failure' ) );

		$computer = $this->getComputer(
			$this->getFactoryMock( 'metric-id', $metric ),
			[ 'ImpactModuleThrowOnFailures' => true ]
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Metric computation failure' );
		$computer->getMetricResult( 'metric-id', $user );
	}

	public function testGetMetricResults() {
		$metricIdsAndResults = [
			'metric-1' => MetricResult::ready( 42 ),
			'metric-2' => MetricResult::pending(),
		];
		$user = new UserIdentityValue( 1, 'Admin' );

		$factory = $this->createNoOpMock( MetricFactory::class, [ 'getEnabledIds', 'getMetric' ] );
		$factory->expects( $this->once() )
			->method( 'getEnabledIds' )
			->willReturn( array_keys( $metricIdsAndResults ) );
		$factory->expects( $this->exactly( count( $metricIdsAndResults ) ) )
			->method( 'getMetric' )
			->willReturnCallback( function ( string $metricId ) use ( $metricIdsAndResults, $user ) {
				$this->assertArrayHasKey( $metricId, $metricIdsAndResults );
				$metric = $this->getAvailableMetricMock( $user );
				$metric->expects( $this->once() )
					->method( 'computeMetric' )
					->with( $user )
					->willReturn( $metricIdsAndResults[$metricId] );
				return $metric;
			} );

		$this->assertEquals(
			$metricIdsAndResults,
			$this->getComputer( $factory )->getMetricResults( $user )
		);
	}
}
