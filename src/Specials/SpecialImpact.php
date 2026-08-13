<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Specials;

use MediaWiki\Extension\ImpactModule\Metrics\MetricComputer;
use MediaWiki\Extension\ImpactModule\Metrics\MetricState;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialImpact extends SpecialPage {

	public function __construct(
		private readonly MetricComputer $metricComputer
	) {
		parent::__construct( 'NewImpact' );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->requireLogin();
		parent::execute( $subPage );

		// This will eventually be replaced with a much nicer client
		$metrics = $this->metricComputer->getMetricResults( $this->getUser() );
		$result = '';
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
