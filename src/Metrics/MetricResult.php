<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics;

use LogicException;
use MediaWiki\Extension\ImpactModule\Metrics\Value\IMetricValue;
use MediaWiki\Extension\ImpactModule\Metrics\Value\PrimitiveMetricValue;
use Wikimedia\JsonCodec\JsonCodecable;
use Wikimedia\JsonCodec\JsonCodecableTrait;

/**
 * Representation of a metric result
 *
 * @see IMetricValue Unlike IMetricValue, MetricResult also includes the status of the
 * computation (was it successful).
 */
class MetricResult implements JsonCodecable {
	use JsonCodecableTrait;

	public const int CACHE_VERSION = 1;

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

	/** @inheritDoc */
	public function toJsonArray(): array {
		$result = [ 'state' => $this->getState() ];
		if ( $this->getState() === MetricState::Ready ) {
			$result['value'] = $this->getValue();
		}
		return $result;
	}

	/** @inheritDoc */
	public static function newFromJsonArray( array $json ): self {
		return new self( $json['state'], $json['value'] ?? null );
	}
}
