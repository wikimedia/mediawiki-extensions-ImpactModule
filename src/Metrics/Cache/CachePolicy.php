<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Cache;

use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;

/**
 * Parent class for all cache policies
 *
 * Should only contain static factory methods. All implementations should have protected
 * constructors (to ensure construction only happens here).
 *
 * When adding a new implementation, make sure MetricComputer understands it.
 *
 * @see MetricComputer
 */
abstract class CachePolicy {

	public static function newNoCachePolicy(): NoCachePolicy {
		return new NoCachePolicy();
	}

	public static function newCachePolicy( int $ttlInSeconds ): TtlCachePolicy {
		return new TtlCachePolicy( $ttlInSeconds );
	}
}
