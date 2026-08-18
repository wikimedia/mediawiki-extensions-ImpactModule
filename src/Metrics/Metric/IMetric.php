<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Metric;

use MediaWiki\Extension\ImpactModule\Metrics\MetricFactory;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use MediaWiki\User\UserIdentity;

/**
 * A representation of a computable metric
 *
 * Implementations are encouraged to define an ID constant for use in MetricFactory for
 * registration. However, this is not enforced.
 *
 * @internal Users of the Impact data should work with the metric ID, and refrain from calling
 * anything on this interface directly.
 * @see MetricFactory::METRICS_SPECS (registrations of IMetric implementations)
 */
interface IMetric {

	/**
	 * Get a metric ID, under which it is registered
	 *
	 * This is intended for logging outputs, to make it possible to determine which metric
	 * caused a given log message, so that the issue can be debugged and fixed.
	 *
	 * @return string
	 */
	public function getId(): string;

	/**
	 * Is a metric computable for a user?
	 *
	 * @param UserIdentity $user Subject of the metric
	 * @return bool
	 */
	public function isAvailableForUser( UserIdentity $user ): bool;

	/**
	 * Compute the metric
	 *
	 * @param UserIdentity $user
	 * @return MetricResult
	 */
	public function computeMetric( UserIdentity $user ): MetricResult;
}
