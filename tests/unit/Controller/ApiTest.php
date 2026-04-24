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
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	private IRequest&MockObject $request;
	private InstallerService&MockObject $installerService;
	private IGroupManager&MockObject $groupManager;
	private IUserSession&MockObject $userSession;
	private ServerVersion&MockObject $serverVersion;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->installerService = $this->createMock(InstallerService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->serverVersion = $this->createMock(ServerVersion::class);
	}

	private function controller(): ApiController {
		return new ApiController(
			Application::APP_ID,
			$this->request,
			$this->installerService,
			$this->groupManager,
			$this->userSession,
			$this->serverVersion,
		);
	}

	private function signInAs(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}

	// --- adminCheck -------------------------------------------------------

	public function testAdminCheckReturnsFalseWhenNotSignedIn(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller()->adminCheck();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['isAdmin' => false], $response->getData());
	}

	public function testAdminCheckReturnsTrueWhenAdmin(): void {
		$this->signInAs('alice', true);

		$response = $this->controller()->adminCheck();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['isAdmin' => true], $response->getData());
	}

	// --- apps -------------------------------------------------------------

	public function testAppsReturnsForbiddenWhenNotAdmin(): void {
		$this->signInAs('bob', false);
		$this->installerService->expects(self::never())->method('getInstalledApps');

		$response = $this->controller()->apps();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Forbidden'], $response->getData());
	}

	public function testAppsDelegatesToServiceWhenAdmin(): void {
		$this->signInAs('alice', true);
		$this->installerService->method('getInstalledApps')->willReturn([
			['id' => 'files', 'label' => 'Files', 'description' => '', 'summary' => '', 'preview' => '', 'isCore' => true],
		]);

		$response = $this->controller()->apps();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertArrayHasKey('apps', $response->getData());
		self::assertCount(1, $response->getData()['apps']);
	}

	// --- updateChannel ----------------------------------------------------

	public function testUpdateChannelReturnsForbiddenWhenNotAdmin(): void {
		$this->signInAs('bob', false);
		$this->serverVersion->expects(self::never())->method('getChannel');

		$response = $this->controller()->updateChannel();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Forbidden'], $response->getData());
	}

	public function testUpdateChannelReturnsChannelWhenAdmin(): void {
		$this->signInAs('alice', true);
		$this->serverVersion->method('getChannel')->willReturn('stable');

		$response = $this->controller()->updateChannel();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['updateChannel' => 'stable'], $response->getData());
	}

	// --- appVersions ------------------------------------------------------

	public function testAppVersionsReturnsForbiddenWhenNotAdmin(): void {
		$this->signInAs('bob', false);
		$this->installerService->expects(self::never())->method('getAppVersions');

		$response = $this->controller()->appVersions('notes');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAppVersionsPropagatesStatusCodeFromService(): void {
		$this->signInAs('alice', true);
		$this->installerService->method('getAppVersions')->with('notes')->willReturn([
			'installedVersion' => '4.10.0',
			'availableVersions' => [],
			'versions' => [],
			'source' => 'store',
			'statusCode' => Http::STATUS_BAD_GATEWAY,
			'hasError' => true,
		]);

		$response = $this->controller()->appVersions('notes');

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		// Controller strips the internal-only fields before returning.
		$data = $response->getData();
		self::assertArrayNotHasKey('statusCode', $data);
		self::assertArrayNotHasKey('hasError', $data);
		self::assertSame('store', $data['source']);
	}

	// --- installVersion --------------------------------------------------

	public function testInstallVersionReturnsForbiddenWhenNotAdmin(): void {
		$this->signInAs('bob', false);
		$this->installerService->expects(self::never())->method('installAppVersion');

		$response = $this->controller()->installVersion('notes', '4.10.2');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testInstallVersionUsesTargetVersionBodyParam(): void {
		$this->signInAs('alice', true);
		$this->request->method('getParam')->willReturnMap([
			['targetVersion', null, '4.10.2'],
			['version', null, '4.10.3'],
			['debug', '0', '0'],
		]);

		$this->installerService->expects(self::once())
			->method('installAppVersion')
			->with('notes', '4.10.2', false)
			->willReturn([
				'statusCode' => Http::STATUS_OK,
				'payload' => ['appId' => 'notes', 'toVersion' => '4.10.2'],
			]);

		$response = $this->controller()->installVersion('notes', 'ignored');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('4.10.2', $data['requestedVersion']);
		self::assertSame('ignored', $data['routeVersion']);
	}

	public function testInstallVersionFallsBackToVersionBodyParam(): void {
		$this->signInAs('alice', true);
		$this->request->method('getParam')->willReturnMap([
			['targetVersion', null, ''],
			['version', null, '4.10.3'],
			['debug', '0', '0'],
		]);

		$this->installerService->expects(self::once())
			->method('installAppVersion')
			->with('notes', '4.10.3', false)
			->willReturn([
				'statusCode' => Http::STATUS_OK,
				'payload' => ['appId' => 'notes', 'toVersion' => '4.10.3'],
			]);

		$response = $this->controller()->installVersion('notes', 'route-version');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('4.10.3', $response->getData()['requestedVersion']);
	}

	public function testInstallVersionFallsBackToRouteVersionWhenBodyMissing(): void {
		$this->signInAs('alice', true);
		$this->request->method('getParam')->willReturnMap([
			['targetVersion', null, null],
			['version', null, null],
			['debug', '0', '0'],
		]);

		$this->installerService->expects(self::once())
			->method('installAppVersion')
			->with('notes', 'route-4.10.0', false)
			->willReturn([
				'statusCode' => Http::STATUS_OK,
				'payload' => ['appId' => 'notes', 'toVersion' => 'route-4.10.0'],
			]);

		$response = $this->controller()->installVersion('notes', 'route-4.10.0');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('route-4.10.0', $response->getData()['requestedVersion']);
	}

	public function testInstallVersionParsesDebugFlag(): void {
		$this->signInAs('alice', true);
		$this->request->method('getParam')->willReturnMap([
			['targetVersion', null, '4.10.2'],
			['version', null, null],
			['debug', '0', 'true'],
		]);

		$this->installerService->expects(self::once())
			->method('installAppVersion')
			->with('notes', '4.10.2', true)
			->willReturn([
				'statusCode' => Http::STATUS_OK,
				'payload' => ['appId' => 'notes', 'toVersion' => '4.10.2'],
			]);

		$this->controller()->installVersion('notes', 'ignored');
	}
}
