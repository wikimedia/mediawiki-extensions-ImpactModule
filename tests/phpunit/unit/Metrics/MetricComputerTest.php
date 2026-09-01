<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Unit\Metrics;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ImpactModule\Metrics\Cache\CachePolicy;
use MediaWiki\Extension\ImpactModule\Metrics\Metric\IMetric;
use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricFactory;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use MediaWiki\Extension\ImpactModule\Metrics\MetricState;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use Psr\Log\NullLogger;
use RuntimeException;
use Wikimedia\JsonCodec\JsonCodec;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\MetricComputer
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\Cache\CachePolicy
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\Cache\TtlCachePolicy
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\Cache\NoCachePolicy
 */
class MetricComputerTest extends MediaWikiUnitTestCase {

	private HashBagOStuff $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new HashBagOStuff();
	}

	private function getAvailableMetricMock( UserIdentity $user, CachePolicy $cachePolicy ) {
		$metric = $this->createMock( IMetric::class );
		$metric->expects( $this->atLeastOnce() )
			->method( 'isAvailableForUser' )
			->with( $user )
			->willReturn( true );
		$metric->expects( $this->atLeastOnce() )
			->method( 'getCachePolicy' )
			->willReturn( $cachePolicy );
		return $metric;
	}

	private function getFactoryMock( string $metricId, IMetric $metric ) {
		$factory = $this->createNoOpMock( MetricFactory::class, [ 'getMetric' ] );
		$factory->expects( $this->atLeastOnce() )
			->method( 'getMetric' )
			->with( $metricId )
			->willReturn( $metric );
		return $factory;
	}

	private function getComputer( $factory, array $configOverrides = [] ): MetricComputer {
		return new MetricComputer(
			new HashConfig( $configOverrides + [
				// this causes errors to propagate, which makes test failures more visible, which
				// is desirable
				'ImpactModuleThrowOnFailures' => true,
			] ),
			new JsonCodec(),
			new WANObjectCache( [ 'cache' => $this->cache ] ),
			new NullLogger(),
			$factory
		);
	}

	public function testGetMetricResultDisabled() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$metric = $this->createNoOpMock(
			IMetric::class,
			[ 'isAvailableForUser', 'getCachePolicy' ]
		);
		$metric->expects( $this->exactly( 2 ) )
			->method( 'isAvailableForUser' )
			->with( $user )
			->willReturn( false );
		$metric->expects( $this->exactly( 2 ) )
			->method( 'getCachePolicy' )
			->willReturn( CachePolicy::newCachePolicy( 60 ) );

		$this->assertSame(
			MetricState::Disabled,
			$this->getComputer( $this->getFactoryMock( 'metric-id', $metric ) )
				->getMetricResult( 'metric-id', $user )
				->getState()
		);

		// Despite CachePolicy says TTL is 60 seconds, non-ready results are uncacheable (asserted
		// via expects twice on isAvailableForUser).
		$this->assertSame(
			MetricState::Disabled,
			$this->getComputer( $this->getFactoryMock( 'metric-id', $metric ) )
				->getMetricResult( 'metric-id', $user )
				->getState()
		);
	}

	public static function provideGetMetricResultSuccess() {
		yield 'normal cache' => [ self::once(), CachePolicy::newCachePolicy( 60 ) ];
		yield 'no cache' => [ self::exactly( 2 ), CachePolicy::newNoCachePolicy() ];
	}

	/**
	 * @dataProvider provideGetMetricResultSuccess
	 */
	public function testGetMetricResultSuccess(
		InvocationOrder $computeMetricExpectation,
		CachePolicy $cachePolicy
	) {
		$user = new UserIdentityValue( 1, 'Admin' );

		$result = MetricResult::ready( 42 );

		$metric = $this->getAvailableMetricMock( $user, $cachePolicy );
		$metric->expects( $computeMetricExpectation )
			->method( 'computeMetric' )
			->with( $user )
			->willReturn( $result );

		$computer = $this->getComputer( $this->getFactoryMock( 'metric-id', $metric ) );

		$this->assertEquals(
			$result,
			$computer->getMetricResult( 'metric-id', $user )
		);

		// This should go via cache, verified via the `once` expectation on `computeMetric`
		$this->assertEquals(
			$result,
			$computer->getMetricResult( 'metric-id', $user )
		);
	}

	public static function provideGetMetricResultSuccessTwoPolicies() {
		return [
			'bumped version' => [
				CachePolicy::newCachePolicy( 60 )
					->setCacheVersion( 1 ),
				CachePolicy::newCachePolicy( 60 )
					->setCacheVersion( 2 ),
			],
			'different key components' => [
				CachePolicy::newCachePolicy( 60 )
					->setCacheKeyComponents( 'a' ),
				CachePolicy::newCachePolicy( 60 )
					->setCacheKeyComponents( 'b' ),
			],
		];
	}

	/**
	 * @dataProvider provideGetMetricResultSuccessTwoPolicies
	 */
	public function testGetMetricResultSuccessTwoPolicies(
		CachePolicy $policyA, CachePolicy $policyB
	) {
		$user = new UserIdentityValue( 1, 'Admin' );

		$resultA = MetricResult::ready( 42 );
		$resultB = MetricResult::ready( 43 );

		$metricA = $this->getAvailableMetricMock( $user, $policyA );
		$metricA->expects( $this->once() )
			->method( 'computeMetric' )
			->with( $user )
			->willReturn( $resultA );

		$metricB = $this->getAvailableMetricMock( $user, $policyB );
		$metricB->expects( $this->once() )
			->method( 'computeMetric' )
			->with( $user )
			->willReturn( $resultB );

		// Intentionally shares metricId; the goal is to verify a bumped cache version ignores any
		// cache content.
		$computerA = $this->getComputer( $this->getFactoryMock( 'metric-id', $metricA ) );
		$computerB = $this->getComputer( $this->getFactoryMock( 'metric-id', $metricB ) );

		// Cache miss, then hit (verified via `computeMetric` expectations)
		$this->assertEquals( $resultA, $computerA->getMetricResult( 'metric-id', $user ) );
		$this->assertEquals( $resultA, $computerA->getMetricResult( 'metric-id', $user ) );

		// Cache miss, then hit (same metric-id, but different cache version)
		// Verified via `computeMetric` expectations
		$this->assertEquals( $resultB, $computerB->getMetricResult( 'metric-id', $user ) );
		$this->assertEquals( $resultB, $computerB->getMetricResult( 'metric-id', $user ) );
	}

	public function testGetMetricResultFailure() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$metric = $this->getAvailableMetricMock( $user, CachePolicy::newCachePolicy( 60 ) );
		$metric->expects( $this->exactly( 2 ) )
			->method( 'computeMetric' )
			->with( $user )
			->willThrowException( new RuntimeException( 'Metric computation failure' ) );

		$computer = $this->getComputer(
			$this->getFactoryMock( 'metric-id', $metric ),
			[ 'ImpactModuleThrowOnFailures' => false ]
		);

		$this->assertSame(
			MetricState::Error,
			$computer->getMetricResult( 'metric-id', $user )->getState()
		);

		// Errors are uncacheable, despite what the CachePolicy says
		$this->assertSame(
			MetricState::Error,
			$computer->getMetricResult( 'metric-id', $user )->getState()
		);
	}

	public function testGetMetricResultFailureThrown() {
		$user = new UserIdentityValue( 1, 'Admin' );

		$metric = $this->getAvailableMetricMock( $user, CachePolicy::newCachePolicy( 60 ) );
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
				$metric = $this->getAvailableMetricMock( $user, CachePolicy::newCachePolicy( 60 ) );
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

	public function testUnknownCachePolicy() {
		$user = new UserIdentityValue( 1, 'Admin' );
		$metric = $this->createNoOpMock( IMetric::class, [ 'getCachePolicy' ] );
		$metric->expects( $this->once() )
			->method( 'getCachePolicy' )
			->willReturn( new class extends CachePolicy {
			} );

		$computer = $this->getComputer( $this->getFactoryMock( 'metric', $metric ) );
		$this->expectException( \LogicException::class );
		$computer->getMetricResult( 'metric', $user );
	}
}
