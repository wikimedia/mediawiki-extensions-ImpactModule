<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Metric;

use MediaWiki\Extension\ImpactModule\Metrics\Cache\CachePolicy;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use MediaWiki\User\UserIdentity;

/**
 * An example metric to have something to play with
 */
class ExampleMetric implements IMetric {

	public const string ID = 'example-metric';

	public function getId(): string {
		return self::ID;
	}

	public function isAvailableForUser( UserIdentity $user ): bool {
		return true;
	}

	public function getCachePolicy(): CachePolicy {
		return CachePolicy::newNoCachePolicy();
	}

	public function computeMetric( UserIdentity $user ): MetricResult {
		// after all, this is the answer to everything
		return MetricResult::ready( 42 );
	}
}
