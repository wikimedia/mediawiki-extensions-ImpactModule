<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\User;

use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;

/**
 * Resolves the central user ID that per-editor endpoints are keyed by.
 *
 * Everything that needs the subject's central ID goes through one of these, so
 * that a metric deciding whether it can be computed and the client fetching the
 * data for it cannot disagree about who the subject is.
 *
 * The answer is whatever the rest of MediaWiki would give, which depends on the
 * wiki: the default provider reports local user IDs, while CentralAuth reports
 * 0 for an account that is not attached here. Answers are remembered for the
 * request, because the availability check and the request that follows it ask
 * about the same subject.
 */
class CentralUserIdResolver {

	private ?int $debugCentralUserId = null;
	/** @var array<int,int> Local user ID => central user ID */
	private array $resolved = [];

	public function __construct(
		private readonly CentralIdLookup $centralIdLookup
	) {
	}

	/**
	 * Report the given central user ID for every subject.
	 *
	 * Development and test purpose only: locally, CentralIdLookup hands out
	 * IDs that production services know nothing about, so pointing at the central
	 * ID of a real, active editor is what makes them return data to develop
	 * against. Callers are responsible for checking that this is allowed, see
	 * $wgImpactModuleAllowDebugCentralId.
	 */
	public function setDebugCentralUserId( int $centralUserId ): void {
		$this->debugCentralUserId = $centralUserId;
	}

	/**
	 * @param UserIdentity $user Subject of the metric
	 * @return int The central user ID, or 0 when the user has none
	 */
	public function getCentralUserId( UserIdentity $user ): int {
		// Checked before the memo, so that overriding after something was
		// already resolved still takes effect
		if ( $this->debugCentralUserId !== null ) {
			return $this->debugCentralUserId;
		}

		$localId = $user->getId();
		if ( !array_key_exists( $localId, $this->resolved ) ) {
			$this->resolved[$localId] = $this->centralIdLookup->centralIdFromLocalUser( $user );
		}
		return $this->resolved[$localId];
	}
}
