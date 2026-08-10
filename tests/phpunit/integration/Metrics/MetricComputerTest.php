<?php

namespace MediaWiki\Extension\ImpactModule\Tests\Integration\Metrics;

use MediaWiki\Extension\ImpactModule\ImpactModuleServices;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Metrics\MetricComputer
 * @group Database
 */
class MetricComputerTest extends MediaWikiIntegrationTestCase {

	public function testComputeNoMetrics() {
		$this->overrideConfigValue( 'ImpactModuleEnabledMetrics', [] );

		$computer = ImpactModuleServices::wrap( $this->getServiceContainer() )
			->getMetricComputer();
		$this->assertSame(
			[],
			$computer->getMetricResults( $this->getTestUser()->getUserIdentity() )
		);
	}

	public function testComputeEnabledMetrics() {
		$imServices = ImpactModuleServices::wrap( $this->getServiceContainer() );
		$computer = $imServices->getMetricComputer();
		$factory = $imServices->getMetricFactory();

		$data = $computer->getMetricResults( $this->getTestUser()->getUserIdentity() );
		$this->assertSame(
			$factory->getEnabledIds(),
			array_keys( $data )
		);
	}
}
