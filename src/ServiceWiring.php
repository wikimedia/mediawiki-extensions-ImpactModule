<?php

declare( strict_types = 1 );

use MediaWiki\Extension\ImpactModule\Aqs\AqsClient;
use MediaWiki\Extension\ImpactModule\ImpactModuleServices;
use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricFactory;
use MediaWiki\Extension\ImpactModule\User\CentralUserIdResolver;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

return [
	'ImpactModuleAqsClient' => static function ( MediaWikiServices $services ): AqsClient {
		$imServices = ImpactModuleServices::wrap( $services );

		return new AqsClient(
			$imServices->getCentralUserIdResolver(),
			$services->getHttpRequestFactory(),
			$services->getMainConfig()->get( 'ImpactModuleAqsBaseURL' )
		);
	},
	'ImpactModuleMetricComputer' => static function ( MediaWikiServices $services ): MetricComputer {
		$imServices = ImpactModuleServices::wrap( $services );

		return new MetricComputer(
			$services->getMainConfig(),
			$services->getJsonCodec(),
			$services->getMainWANObjectCache(),
			$imServices->getLogger(),
			$imServices->getMetricFactory(),
		);
	},
	'ImpactModuleCentralUserIdResolver' => static function (
		MediaWikiServices $services
	): CentralUserIdResolver {
		return new CentralUserIdResolver( $services->getCentralIdLookup() );
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
