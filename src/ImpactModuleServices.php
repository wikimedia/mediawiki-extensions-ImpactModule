<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule;

use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

/**
 * A simple wrapper for MediaWikiServices, to support type safety when accessing
 * services defined by this extension.
 */
final class ImpactModuleServices {

	private MediaWikiServices $coreServices;

	public function __construct( MediaWikiServices $coreServices ) {
		$this->coreServices = $coreServices;
	}

	/**
	 * Static version of the constructor, for nicer syntax.
	 */
	public static function wrap( MediaWikiServices $coreServices ): self {
		return new self( $coreServices );
	}

	public function getMetricComputer(): MetricComputer {
		return $this->coreServices->getService( 'ImpactModuleMetricComputer' );
	}

	public function getMetricFactory(): MetricFactory {
		return $this->coreServices->getService( 'ImpactModuleMetricFactory' );
	}

	public function getLogger(): LoggerInterface {
		return $this->coreServices->getService( 'ImpactModuleLogger' );
	}
}
