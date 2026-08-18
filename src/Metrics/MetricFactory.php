<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ImpactModule\Metrics\Metric\ExampleMetric;
use MediaWiki\Extension\ImpactModule\Metrics\Metric\IMetric;
use Wikimedia\ObjectFactory\ObjectFactory;

class MetricFactory {

	private const METRICS_SPECS = [
		ExampleMetric::ID => [
			'class' => ExampleMetric::class,
		],
	];

	/**
	 * @var array<string,IMetric> A map between metric IDs and their constructed instance
	 */
	private array $constructedMetrics = [];

	public function __construct(
		private readonly Config $config,
		private readonly ObjectFactory $objectFactory
	) {
	}

	public function getSupportedIds(): array {
		return array_keys( self::METRICS_SPECS );
	}

	public function getEnabledIds(): array {
		return array_intersect(
			$this->getSupportedIds(),
			(array)$this->config->get( 'ImpactModuleEnabledMetrics' )
		);
	}

	public function isEnabled( string $metricId ): bool {
		return in_array( $metricId, $this->getEnabledIds() );
	}

	private function getSpecsById( string $metricId ): array {
		if ( !$this->isEnabled( $metricId ) ) {
			throw new \InvalidArgumentException( 'Metric ' . $metricId . ' is not enabled' );
		}

		return self::METRICS_SPECS[$metricId];
	}

	public function getMetric( string $metricId ): IMetric {
		if ( !array_key_exists( $metricId, $this->constructedMetrics ) ) {
			$this->constructedMetrics[$metricId] = $this->objectFactory->createObject(
				$this->getSpecsById( $metricId ),
				[
					'assertClass' => IMetric::class,
				]
			);
		}

		return $this->constructedMetrics[$metricId];
	}
}
