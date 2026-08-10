<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Aqs;

use InvalidArgumentException;
use RuntimeException;
use StatusValue;

class AqsError extends RuntimeException {

	private function __construct(
		string $message,
		private readonly ?StatusValue $status
	) {
		parent::__construct( $message );
	}

	public static function newFromStatus( StatusValue $statusValue ): self {
		if ( $statusValue->isOK() ) {
			throw new InvalidArgumentException( __METHOD__ . ' requires a failed status' );
		}
		return new self(
			'AQS request failed' . PHP_EOL . PHP_EOL . strval( $statusValue ),
			$statusValue
		);
	}

	public static function newFromMessage( string $error ): self {
		return new self( $error, null );
	}

	public function getStatusValue(): ?StatusValue {
		return $this->status;
	}
}
