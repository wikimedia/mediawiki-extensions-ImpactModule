<?php

namespace MediaWiki\Extension\ImpactModule\Tests\Structure;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @coversNothing
 */
class ImpactModuleExtensionJsonTest extends ExtensionJsonTestBase {
	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

	/** @inheritDoc */
	protected ?string $serviceNamePrefix = 'ImpactModule';

	/** @inheritDoc */
	protected static bool $requireHookHandlers = true;
}
