<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Unit\User;

use MediaWiki\Extension\ImpactModule\User\CentralUserIdResolver;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ImpactModule\User\CentralUserIdResolver
 */
class CentralUserIdResolverTest extends MediaWikiUnitTestCase {

	public function testEachSubjectIsLookedUpOnce() {
		$admin = new UserIdentityValue( 1, 'Admin' );
		$other = new UserIdentityValue( 2, 'Other' );

		$centralIdLookup = $this->createMock( CentralIdLookup::class );
		$centralIdLookup->expects( $this->exactly( 2 ) )
			->method( 'centralIdFromLocalUser' )
			->willReturnCallback( static fn ( $user ) => $user->getId() + 100 );

		$resolver = new CentralUserIdResolver( $centralIdLookup );

		// Asking twice per subject, but each is resolved only once
		$this->assertSame( 101, $resolver->getCentralUserId( $admin ) );
		$this->assertSame( 101, $resolver->getCentralUserId( $admin ) );
		$this->assertSame( 102, $resolver->getCentralUserId( $other ) );
		$this->assertSame( 102, $resolver->getCentralUserId( $other ) );
	}

	public function testSubjectWithoutACentralId() {
		$centralIdLookup = $this->createMock( CentralIdLookup::class );
		$centralIdLookup->method( 'centralIdFromLocalUser' )
			->willReturn( 0 );

		$resolver = new CentralUserIdResolver( $centralIdLookup );
		$this->assertSame(
			0,
			$resolver->getCentralUserId( new UserIdentityValue( 1, 'Admin' ) ),
			'reported rather than thrown, so callers can decide what it means'
		);
	}

	public function testDebugOverrideNeedsNoLookupAtAll() {
		$resolver = new CentralUserIdResolver(
			$this->createNoOpMock( CentralIdLookup::class )
		);
		$resolver->setDebugCentralUserId( 999 );

		$this->assertSame(
			999,
			$resolver->getCentralUserId( new UserIdentityValue( 1, 'Admin' ) )
		);
	}

	public function testDebugOverrideAppliesAfterSomethingWasResolved() {
		$admin = new UserIdentityValue( 1, 'Admin' );

		$centralIdLookup = $this->createMock( CentralIdLookup::class );
		$centralIdLookup->method( 'centralIdFromLocalUser' )
			->willReturn( 12345 );

		$resolver = new CentralUserIdResolver( $centralIdLookup );
		$this->assertSame( 12345, $resolver->getCentralUserId( $admin ) );

		$resolver->setDebugCentralUserId( 999 );
		$this->assertSame(
			999,
			$resolver->getCentralUserId( $admin ),
			'the memo does not shadow the override'
		);
	}
}
