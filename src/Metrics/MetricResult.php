<?php

namespace MediaWiki\Extension\ImpactModule\Metrics;

use JsonSerializable;
use LogicException;
use MediaWiki\Extension\ImpactModule\Metrics\Value\IMetricValue;
use MediaWiki\Extension\ImpactModule\Metrics\Value\PrimitiveMetricValue;

/**
 * Representation of a metric result
 *
 * @see IMetricValue Unlike IMetricValue, MetricResult also includes the status of the
 * computation (was it successful).
 */
class MetricResult implements JsonSerializable {

	private function __construct(
		private readonly MetricState $state,
		private readonly ?IMetricValue $value
	) {
	}

	public static function ready( int|float|IMetricValue $value ): self {
		if ( !$value instanceof IMetricValue ) {
			$value = new PrimitiveMetricValue( $value );
		}
		return new self( MetricState::Ready, $value );
	}

	public static function pending(): self {
		return new self( MetricState::Pending, null );
	}

	public static function disabled(): self {
		return new self( MetricState::Disabled, null );
	}

	public static function error(): self {
		return new self( MetricState::Error, null );
	}

	public function getState(): MetricState {
		return $this->state;
	}

	public function getValue(): IMetricValue {
		if ( $this->getState() !== MetricState::Ready ) {
			throw new LogicException( __METHOD__ . ' cannot be called when result is not ready' );
		}
		if ( $this->value === null ) {
			// this should not be possible
			throw new LogicException( 'Value is null despite status is ready' );
		}
		return $this->value;
	}

	public function jsonSerialize(): mixed {
		$result = [ 'state' => $this->getState() ];
		if ( $this->getState() === MetricState::Ready ) {
			$result['value'] = $this->getValue();
		}
		return $result;
	}
}
