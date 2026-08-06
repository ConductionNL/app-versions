<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\AutoUpdate;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\AppVersions\Service\AutoUpdate\AutoUpdateWindow;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class AutoUpdateSettingsStoreTest extends TestCase {
	public function testIsEnabledDefaultsToFalse(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_ENABLED, false)
			->willReturn(false);

		$store = new AutoUpdateSettingsStore($config);

		$this->assertFalse($store->isEnabled());
	}

	public function testGetWindowDefaultsToTheStandardWindowWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(AutoUpdateWindow::DEFAULT_WINDOW);

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame('01:00-05:00', $store->getWindow());
	}

	public function testGetWindowFallsBackWhenStoredValueIsEmpty(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame(AutoUpdateWindow::DEFAULT_WINDOW, $store->getWindow());
	}

	public function testGetWindowReturnsAStoredCustomWindow(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('23:00-03:00');

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame('23:00-03:00', $store->getWindow());
	}

	public function testSetEnabledWritesTheConfigValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueBool')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_ENABLED, true);

		$store = new AutoUpdateSettingsStore($config);
		$store->setEnabled(true);
	}

	public function testSetWindowWritesTheConfigValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_WINDOW, '22:00-04:00');

		$store = new AutoUpdateSettingsStore($config);
		$store->setWindow('22:00-04:00');
	}
}
