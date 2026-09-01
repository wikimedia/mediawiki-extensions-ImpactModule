<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Value;

use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use Stringable;
use Wikimedia\JsonCodec\JsonCodecable;

/**
 * A representation of a value calculated by an IMetric
 *
 * This interface prescribes requirements of a value returned by a metric.
 *
 * @see MetricResult Unlike IMetricValue, MetricResult also includes the status of the
 * computation (was it successful).
 */
interface IMetricValue extends JsonCodecable, Stringable {

}
