<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Metrics;

enum MetricState: string {
	case Ready = 'ready';
	case Pending = 'pending';
	case Disabled = 'disabled';
	case Error = 'error';
}
