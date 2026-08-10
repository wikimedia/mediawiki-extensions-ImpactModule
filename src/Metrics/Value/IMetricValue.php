<?php

namespace MediaWiki\Extension\ImpactModule\Metrics\Value;

use JsonSerializable;
use MediaWiki\Extension\ImpactModule\Metrics\MetricResult;
use Stringable;

/**
 * A representation of a value calculated by an IMetric
 *
 * This interface prescribes requirements of a value returned by a metric.
 *
 * @see MetricResult Unlike IMetricValue, MetricResult also includes the status of the
 * computation (was it successful).
 */
interface IMetricValue extends JsonSerializable, Stringable {

}
