<?php

/**
 * AppVersions Installer Service Test
 *
 * Unit tests for OCA\AppVersions\Service\InstallerService. Covers the
 * pure-logic validation paths that do not touch Nextcloud's internal
 * app directory or the app store — those live in the Newman integration
 * collection under tests/integration/.
 *
 * @category Tests
 * @package  OCA\AppVersions\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Service;

use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\SelectedReleaseInstallerService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class InstallerServiceTest extends TestCase {
	private function service(): InstallerService {
		return new InstallerService(
			$this->createMock(IAppManager::class),
			$this->createMock(IConfig::class),
			$this->createMock(IClientService::class),
			$this->createMock(SelectedReleaseInstallerService::class),
		);
	}

	public function testGetAppVersionsReturnsBadRequestEnvelopeForEmptyAppId(): void {
		$result = $this->service()->getAppVersions('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result['statusCode']);
		self::assertTrue($result['hasError']);
		self::assertNull($result['installedVersion']);
		self::assertSame([], $result['availableVersions']);
		self::assertSame([], $result['versions']);
		self::assertSame('none', $result['source']);
	}

	public function testGetAppVersionsTrimsAppIdBeforeValidating(): void {
		// Whitespace-only appId still hits the empty guard.
		$result = $this->service()->getAppVersions('   ');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result['statusCode']);
		self::assertTrue($result['hasError']);
	}

	public function testInstallAppVersionReturnsBadRequestForEmptyInputs(): void {
		$result = $this->service()->installAppVersion('', '', false);

		self::assertSame(Http::STATUS_BAD_REQUEST, $result['statusCode']);
		self::assertArrayHasKey('payload', $result);
	}
}
