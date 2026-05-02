<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class SourceBindingStoreTest extends TestCase {
	public function testGetReturnsNullWhenUnset(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$store = new SourceBindingStore($config);

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsBindingForValidJson(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
			'assetPattern' => '*.tar.gz',
		], JSON_THROW_ON_ERROR));

		$store = new SourceBindingStore($config);
		$binding = $store->get('openregister');

		$this->assertNotNull($binding);
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
	}

	public function testGetReturnsNullOnMalformedJson(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('{not valid json');

		$store = new SourceBindingStore($config);

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullOnInvalidBinding(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			// missing owner/repo
		], JSON_THROW_ON_ERROR));

		$store = new SourceBindingStore($config);

		$this->assertNull($store->get('openregister'));
	}

	public function testSetWritesJson(): void {
		$captured = null;
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('setAppValue')
			->with(
				Application::APP_ID,
				'source.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = new SourceBindingStore($config);
		$store->set('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		$decoded = json_decode((string)$captured, true);
		$this->assertIsArray($decoded);
		$this->assertSame('github-release', $decoded['kind']);
		$this->assertSame('ConductionNL', $decoded['owner']);
		$this->assertSame('openregister', $decoded['repo']);
	}

	public function testClearDeletesValue(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('deleteAppValue')
			->with(Application::APP_ID, 'source.openregister');

		$store = new SourceBindingStore($config);
		$store->clear('openregister');
	}
}
