<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Integration\Metrics;

use MediaWiki\Extension\ImpactModule\ImpactModuleServices;
use MediaWiki\Extension\ImpactModule\Metrics\Metric\IMetric;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\MetricFactory
 */
class MetricFactoryTest extends MediaWikiIntegrationTestCase {

	public function testSupportedVsEnabled() {
		$this->overrideConfigValue( 'ImpactModuleEnabledMetrics', [] );

		$factory = ImpactModuleServices::wrap( $this->getServiceContainer() )
			->getMetricFactory();
		$this->assertNotEmpty( $factory->getSupportedIds() );
		$this->assertSame( [], $factory->getEnabledIds() );
		$this->assertFalse( $factory->isEnabled(
			$factory->getSupportedIds()[0]
		) );
	}

	public function testAllMetricsAreConstructable() {
		$factory = ImpactModuleServices::wrap( $this->getServiceContainer() )
			->getMetricFactory();

		// enable all registered metrics
		$this->overrideConfigValue( 'ImpactModuleEnabledMetrics', $factory->getSupportedIds() );
		foreach ( $factory->getSupportedIds() as $metricId ) {
			$this->assertInstanceOf( IMetric::class, $factory->getMetric( $metricId ) );
		}
	}
}
