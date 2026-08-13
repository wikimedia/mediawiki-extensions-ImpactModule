<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Cache;

class NoCachePolicy extends CachePolicy {

	// The empty constructor is here to restrict visibility; should be only called from the parent
	protected function __construct() {
	}
}
