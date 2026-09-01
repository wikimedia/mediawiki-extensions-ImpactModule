<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics;

use LogicException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\ImpactModule\Metrics\Cache\NoCachePolicy;
use MediaWiki\Extension\ImpactModule\Metrics\Cache\TtlCachePolicy;
use MediaWiki\Extension\ImpactModule\Metrics\Metric\IMetric;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\Assert\Assert;
use Wikimedia\JsonCodec\JsonCodecInterface;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Compute registered metrics
 *
 * This is the main entrypoint to metric data.
 */
class MetricComputer {

	public function __construct(
		private readonly Config $config,
		private readonly JsonCodecInterface $jsonCodec,
		private readonly WANObjectCache $cache,
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

	private function getMetricUncached( IMetric $metric, UserIdentity $user ): MetricResult {
		if ( !$metric->isAvailableForUser( $user ) ) {
			return MetricResult::disabled();
		}

		try {
			return $metric->computeMetric( $user );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Failed to compute metric {metric} due to {errorName}', [
				'exception' => $e,
				'errorName' => $e->getMessage(),
				'metric' => $metric->getId(),
			] );
			if ( $this->config->get( 'ImpactModuleThrowOnFailures' ) ) {
				throw $e;
			}
			return MetricResult::error();
		}
	}

	public function getMetricResult( string $metricId, UserIdentity $user ): MetricResult {
		Assert::precondition( $user->isRegistered(), 'Metrics only support registered users' );

		$metric = $this->metricFactory->getMetric( $metricId );
		$cachePolicy = $metric->getCachePolicy();
		if ( $cachePolicy instanceof NoCachePolicy ) {
			return $this->getMetricUncached( $metric, $user );
		}

		if ( !$cachePolicy instanceof TtlCachePolicy ) {
			throw new LogicException(
				'No other cache policy than TtlCachePolicy and NoCachePolicy recognised'
			);
		}

		$cacheOptions = [];
		$cacheVersion = $cachePolicy->getCacheVersion();
		if ( $cacheVersion !== null ) {
			$cacheOptions['version'] = $cacheVersion;
		}
		$serialisedResult = $this->cache->getWithSetCallback(
			$this->cache->makeKey(
				'ImpactModule-getMetric',
				$metricId, $user->getId(),
				MetricResult::CACHE_VERSION,
				...$cachePolicy->getCacheKeyComponents(),
			),
			$cachePolicy->getTTLInSeconds(),
			function ( $oldValue, &$ttl ) use ( $metric, $user ) {
				$metricResult = $this->getMetricUncached( $metric, $user );
				if ( $metricResult->getState() !== MetricState::Ready ) {
					// errors should not be cached
					$ttl = WANObjectCache::TTL_UNCACHEABLE;
				}
				return $this->jsonCodec->toJsonArray( $metricResult, MetricResult::class );
			},
			$cacheOptions
		);
		return $this->jsonCodec->newFromJsonArray( $serialisedResult, MetricResult::class );
	}
}
