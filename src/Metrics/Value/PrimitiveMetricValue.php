<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Value;

use Wikimedia\JsonCodec\JsonCodecableTrait;

/**
 * A metric value used with primitive data types
 *
 * WARNING: Think twice before adding `string` to the list of types. With the `string` type added,
 * MetricResult::ready( $obj ) called with a stringable $obj that is not an instance of
 * IMetricValue, will result in an implicit conversion of $obj to string, because
 * PrimitiveMetricValue is capable of accepting it.
 */
class PrimitiveMetricValue implements IMetricValue {
	use JsonCodecableTrait;

	public function __construct(
		private readonly int|float $value
	) {
	}

	public function __toString(): string {
		return strval( $this->value );
	}

	/** @inheritDoc */
	public function toJsonArray(): array {
		return [
			'value' => $this->value,
		];
	}

	/** @inheritDoc */
	public static function newFromJsonArray( array $json ): self {
		return new self( $json['value'] );
	}
}
