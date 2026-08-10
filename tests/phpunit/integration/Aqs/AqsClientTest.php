<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ImpactModule\Tests\Integration\Aqs;

use MediaWiki\Extension\ImpactModule\Aqs\AqsError;
use MediaWiki\Extension\ImpactModule\Aqs\AqsPageType;
use MediaWiki\Extension\ImpactModule\ImpactModuleServices;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Json\FormatJson;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use StatusValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\ImpactModule\Aqs\AqsClient
 * @covers \MediaWiki\Extension\ImpactModule\Aqs\AqsError
 * @covers \MediaWiki\Extension\ImpactModule\Aqs\AqsResponseParser
 * @group Database
 */
class AqsClientTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	public function testHTTPNotFound() {
		ConvertibleTimestamp::setFakeTime( '20260116000000' );
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )
			->willReturn( StatusValue::newFatal( 'http' ) );
		$mockRequest->method( 'getStatus' )
			->willReturn( 404 );
		$this->installMockHttp( $mockRequest );

		$client = ImpactModuleServices::wrap( $this->getServiceContainer() )->getAqsClient();
		$data = $client->getEditsPerDay(
			$this->getTestUser()->getUserIdentity(),
			AqsPageType::All,
			2
		);
		$this->assertSame( [
			'2026-01-13' => 0,
			'2026-01-14' => 0,
		], $data );
	}

	public function testHTTPError() {
		$httpStatus = StatusValue::newFatal( 'http' );
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )
			->willReturn( $httpStatus );
		$mockRequest->method( 'getStatus' )
			->willReturn( 500 );
		$this->installMockHttp( $mockRequest );

		$client = ImpactModuleServices::wrap( $this->getServiceContainer() )->getAqsClient();

		$this->expectException( AqsError::class );
		try {
			$client->getEditsPerDay(
				$this->getTestUser()->getUserIdentity(),
				AqsPageType::All,
				2
			);
		} catch ( AqsError $e ) {
			$this->assertSame( $httpStatus, $e->getStatusValue() );
			throw $e;
		}
	}

	public static function provideTestNonArray() {
		return [
			'integer' => [ '123' ],
			'string' => [ '"this is a JSON string"' ],
			'invalid JSON' => [ 'most definitely not JSON' ],
		];
	}

	/**
	 * @dataProvider provideTestNonArray
	 */
	public function testNonArray( string $data ) {
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )
			->willReturn( StatusValue::newGood() );
		$mockRequest->method( 'getContent' )
			// needs to be something FormatJson parses
			->willReturn( $data );
		$this->installMockHttp( $mockRequest );

		$client = ImpactModuleServices::wrap( $this->getServiceContainer() )->getAqsClient();

		$this->expectException( AqsError::class );
		$client->getEditsPerDay(
			$this->getTestUser()->getUserIdentity(),
			AqsPageType::All,
			2
		);
	}

	public function testEditsPerDayOK() {
		ConvertibleTimestamp::setFakeTime( '20260116000000' );
		$mockRequest = $this->createMock( MWHttpRequest::class );
		$mockRequest->method( 'execute' )
			->willReturn( StatusValue::newGood() );
		$mockRequest->method( 'getContent' )
			->willReturn( FormatJson::encode( [
				'context' => [
					'endpoint' => 'edits/v3/per_editor',
					'user_central_id' => 34722510,
					'page_type' => 'all',
					'granularity' => 'daily',
					'start' => '2026-01-01T00:00:00.000Z',
					'end' => '2026-12-01T00:00:00.000Z',
				],
				'items' => [
					[
						'timestamp' => '2026-01-01T00:00:00.000Z',
						'edit_count' => 23,
					],
					[
						'timestamp' => '2026-01-10T00:00:00.000Z',
						'edit_count' => 12,
					],
					[
						'timestamp' => '2026-02-01T00:00:00.000Z',
						'edit_count' => 4,
					],
				],
			] ) );
		$this->installMockHttp( $mockRequest );

		$client = ImpactModuleServices::wrap( $this->getServiceContainer() )->getAqsClient();

		$data = $client->getEditsPerDay(
			$this->getTestUser()->getUserIdentity(),
			AqsPageType::All,
			15
		);
		$this->assertSame( [
			'2025-12-31' => 0,
			'2026-01-01' => 23,
			'2026-01-02' => 0,
			'2026-01-03' => 0,
			'2026-01-04' => 0,
			'2026-01-05' => 0,
			'2026-01-06' => 0,
			'2026-01-07' => 0,
			'2026-01-08' => 0,
			'2026-01-09' => 0,
			'2026-01-10' => 12,
			'2026-01-11' => 0,
			'2026-01-12' => 0,
			'2026-01-13' => 0,
			'2026-01-14' => 0,
		], $data );
	}
}
