<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class TrustedSourceListTest extends TestCase {
	private function withPatterns(string $stored): TrustedSourceList {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $app === Application::APP_ID && $key === 'trusted_sources'
				? $stored
				: $default
		);

		return new TrustedSourceList($config);
	}

	public function testDefaultAllowsConductionNl(): void {
		$list = $this->withPatterns('');

		$this->assertTrue($list->isAllowed('github:ConductionNL/openregister'));
		$this->assertTrue($list->isAllowed('github:ConductionNL/anything'));
	}

	public function testDefaultRejectsRandomOwner(): void {
		$list = $this->withPatterns('');

		$this->assertFalse($list->isAllowed('github:randomuser/randomapp'));
	}

	public function testAppStoreSourceAlwaysAllowed(): void {
		$list = $this->withPatterns('["only-this/repo"]');

		$this->assertTrue($list->isAllowed('appstore'));
	}

	public function testAssertAllowedThrowsOnReject(): void {
		$list = $this->withPatterns('');

		$this->expectException(UntrustedSourceException::class);
		$list->assertAllowed('github:randomuser/randomapp');
	}

	public function testCustomGlobsAreUsed(): void {
		$list = $this->withPatterns('["myorg/*", "single/repo"]');

		$this->assertTrue($list->isAllowed('github:myorg/foo'));
		$this->assertTrue($list->isAllowed('github:single/repo'));
		$this->assertFalse($list->isAllowed('github:other/repo'));
		$this->assertFalse($list->isAllowed('github:single/other'));
	}

	public function testEmptyArrayFallsBackToDefault(): void {
		$list = $this->withPatterns('[]');

		$this->assertTrue($list->isAllowed('github:ConductionNL/foo'));
	}

	public function testInvalidJsonFallsBackToDefault(): void {
		$list = $this->withPatterns('not json');

		$this->assertTrue($list->isAllowed('github:ConductionNL/foo'));
		$this->assertFalse($list->isAllowed('github:other/foo'));
	}

	public function testMalformedSourceIdRejected(): void {
		$list = $this->withPatterns('');

		$this->assertFalse($list->isAllowed('github:nope-no-slash'));
		$this->assertFalse($list->isAllowed('not-a-source'));
		$this->assertFalse($list->isAllowed('github:'));
		$this->assertFalse($list->isAllowed('github://repo'));
	}
}
