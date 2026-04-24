<?php

/**
 * AppVersions API Controller Test
 *
 * Unit tests for OCA\AppVersions\Controller\ApiController.
 *
 * @category Tests
 * @package  OCA\AppVersions\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Controller;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Controller\ApiController;
use OCA\AppVersions\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	public function testAdminCheckReturnsFalseWhenNotSignedIn(): void {
		$request = $this->createMock(IRequest::class);
		$installerService = $this->createMock(InstallerService::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$userSession = $this->createMock(IUserSession::class);
		$serverVersion = $this->createMock(ServerVersion::class);

		$userSession->method('getUser')->willReturn(null);

		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$installerService,
			$groupManager,
			$userSession,
			$serverVersion,
		);

		$response = $controller->adminCheck();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['isAdmin' => false], $response->getData());
	}
}
