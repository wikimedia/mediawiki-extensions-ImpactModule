<?php

namespace MediaWiki\Extension\ImpactModule\Metrics;

use MediaWiki\Config\Config;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compute registered metrics
 *
 * This is the main entrypoint to metric data.
 */
class MetricComputer {

	public function __construct(
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly MetricFactory $metricFactory
	) {
	}

	/**
	 * @return array<string, MetricResult>
	 */
	public function getMetricResults( UserIdentity $user ): array {
		$result = [];
		foreach ( $this->metricFactory->getEnabledIds() as $metricId ) {
			$result[$metricId] = $this->getMetricResult( $metricId, $user );
		}
		return $result;
	}

	public function getMetricResult( string $metricId, UserIdentity $user ): MetricResult {
		$metric = $this->metricFactory->getMetric( $metricId );

		if ( !$metric->isAvailableForUser( $user ) ) {
			return MetricResult::disabled();
		}

		try {
			return $metric->computeMetric( $user );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Failed to compute metric {metric} due to {errorName}', [
				'exception' => $e,
				'errorName' => $e->getMessage(),
				'metric' => $metricId,
			] );
			if ( $this->config->get( 'ImpactModuleThrowOnFailures' ) ) {
				throw $e;
			}
			return MetricResult::error();
		}
	}
}
