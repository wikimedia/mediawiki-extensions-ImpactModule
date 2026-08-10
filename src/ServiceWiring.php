<?php

declare( strict_types = 1 );

use MediaWiki\Extension\ImpactModule\Aqs\AqsClient;
use MediaWiki\Extension\ImpactModule\ImpactModuleServices;
use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

return [
	'ImpactModuleAqsClient' => static function ( MediaWikiServices $services ): AqsClient {
		return new AqsClient(
			$services->getCentralIdLookup(),
			$services->getHttpRequestFactory(),
			$services->getMainConfig()->get( 'ImpactModuleAqsBaseURL' )
		);
	},
	'ImpactModuleMetricComputer' => static function ( MediaWikiServices $services ): MetricComputer {
		$imServices = ImpactModuleServices::wrap( $services );

		return new MetricComputer(
			$services->getMainConfig(),
			$services->getMainWANObjectCache(),
			$imServices->getLogger(),
			$imServices->getMetricFactory(),
		);
	},
	'ImpactModuleMetricFactory' => static function ( MediaWikiServices $services ): MetricFactory {
		return new MetricFactory(
			$services->getMainConfig(),
			$services->getObjectFactory(),
		);
	},
	'ImpactModuleLogger' => static function ( MediaWikiServices $services ): LoggerInterface {
		return LoggerFactory::getInstance( 'ImpactModule' );
	},
];
