<?php

namespace MediaWiki\Extension\ImpactModule\Metrics\Value;

/**
 * A metric value used with primitive data types
 *
 * WARNING: Think twice before adding `string` to the list of types. With the `string` type added,
 * MetricResult::ready( $obj ) called with a stringable $obj that is not an instance of
 * IMetricValue, will result in an implicit conversion of $obj to string, because
 * PrimitiveMetricValue is capable of accepting it.
 */
class PrimitiveMetricValue implements IMetricValue {

	public function __construct(
		private readonly int|float $value
	) {
	}

	public function __toString(): string {
		return strval( $this->value );
	}

	public function jsonSerialize(): mixed {
		return $this->value;
	}
}
