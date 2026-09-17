<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Specials;

use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricState;
use MediaWiki\Extension\ImpactModule\User\CentralUserIdResolver;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialImpact extends SpecialPage {

	public function __construct(
		private readonly CentralUserIdResolver $centralUserIdResolver,
		private readonly MetricComputer $metricComputer
	) {
		parent::__construct( 'NewImpact' );
	}

	/**
	 * Honour the debugCentralId query parameter, if development allows it.
	 *
	 * Locally, CentralIdLookup returns local user IDs, which production AQS
	 * knows nothing about. Passing the central ID of a real editor makes the
	 * AQS-powered metrics return data one can develop against. Overriding the
	 * resolver rather than the client covers both the check of whether a metric
	 * is available and the request it then makes, which is what makes the
	 * override take effect at all. See $wgImpactModuleAllowDebugCentralId.
	 *
	 * @return int|null The central user ID in use, or null when not overridden
	 */
	private function applyDebugCentralId(): ?int {
		if ( !$this->getConfig()->get( 'ImpactModuleAllowDebugCentralId' ) ) {
			return null;
		}

		$debugCentralId = $this->getRequest()->getInt( 'debugCentralId' );
		if ( $debugCentralId <= 0 ) {
			return null;
		}

		$this->centralUserIdResolver->setDebugCentralUserId( $debugCentralId );
		return $debugCentralId;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->requireLogin();
		parent::execute( $subPage );

		// Must happen before any metric is asked whether it is available
		$debugCentralId = $this->applyDebugCentralId();

		// This will eventually be replaced with a much nicer client
		$metrics = $this->metricComputer->getMetricResults( $this->getUser() );
		$result = '';
		if ( $debugCentralId !== null ) {
			// Development-only notice, deliberately not translated
			$result .= "'''Debug:''' AQS-powered metrics below describe central user ID "
				. $debugCentralId . ', not you.' . PHP_EOL . PHP_EOL;
		}
		foreach ( $metrics as $metricName => $metricResult ) {
			$metricString = match ( $metricResult->getState() ) {
				MetricState::Ready => strval( $metricResult->getValue() ),
				MetricState::Pending => 'pending',
				MetricState::Disabled => 'disabled',
				MetricState::Error => 'error',
			};
			$result .= '* <code>' . $metricName . '</code>: ' . $metricString . PHP_EOL;
		}
		$this->getOutput()->addWikiTextAsContent( $result );
	}
}
