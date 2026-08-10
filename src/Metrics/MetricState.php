<?php

namespace MediaWiki\Extension\ImpactModule\Metrics;

enum MetricState: string {
	case Ready = 'ready';
	case Pending = 'pending';
	case Disabled = 'disabled';
	case Error = 'error';
}
