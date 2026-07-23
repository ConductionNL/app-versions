<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Controller;

use OCA\AppVersions\Controller\ApiController;
use OCA\AppVersions\Db\AuditEntry;
use OCA\AppVersions\Db\AuditEntryMapper;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Advisory\AdvisoryService;
use OCA\AppVersions\Service\Discovery\DiscoveryAggregator;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\Pat\PatDeeplinkBuilder;
use OCA\AppVersions\Service\Pat\PatExpiryEvaluator;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatValidator;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ApiTest extends TestCase {
	private function fakeServerVersion(): ServerVersion {
		// ServerVersion is `readonly` and cannot be mocked; bypass the
		// constructor to get an inert instance for tests that don't touch it.
		return (new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
	}

	private function buildController(?IRequest $request = null, ?AuditEntryMapper $auditEntryMapper = null): ApiController {
		return new ApiController(
			'app_versions',
			$request ?? $this->createMock(IRequest::class),
			$this->createMock(InstallerService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->fakeServerVersion(),
			$this->createMock(PatMapper::class),
			$this->createMock(PatManager::class),
			$this->createMock(PatValidator::class),
			$this->createMock(PatDeeplinkBuilder::class),
			$this->createMock(PatExpiryEvaluator::class),
			$this->createMock(DiscoveryAggregator::class),
			$this->createMock(AdvisoryService::class),
			$auditEntryMapper ?? $this->createMock(AuditEntryMapper::class),
		);
	}

	/**
	 * Builds a controller whose isAdmin() returns true, with the given installer
	 * service and request wired in.
	 */
	private function buildAdminController(InstallerService $installer, IRequest $request, ?AuditEntryMapper $auditEntryMapper = null): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		return new ApiController(
			'app_versions',
			$request,
			$installer,
			$groupManager,
			$session,
			$this->fakeServerVersion(),
			$this->createMock(PatMapper::class),
			$this->createMock(PatManager::class),
			$this->createMock(PatValidator::class),
			$this->createMock(PatDeeplinkBuilder::class),
			$this->createMock(PatExpiryEvaluator::class),
			$this->createMock(DiscoveryAggregator::class),
			$this->createMock(AdvisoryService::class),
			$auditEntryMapper ?? $this->createMock(AuditEntryMapper::class),
		);
	}

	public function testReadBinaryBoolAcceptsCommonTruthyAndFalsyValues(): void {
		$controller = $this->buildController();

		$method = new ReflectionMethod(ApiController::class, 'readBinaryBool');
		$invoke = static fn (mixed $value, bool $default): bool => (bool)$method->invoke($controller, $value, $default);

		$this->assertTrue($invoke('1', false));
		$this->assertTrue($invoke('true', false));
		$this->assertTrue($invoke(true, false));
		$this->assertTrue($invoke(1, false));
		$this->assertFalse($invoke('0', true));
		$this->assertFalse($invoke('false', true));
		$this->assertFalse($invoke(false, true));
		$this->assertFalse($invoke(0, true));
		$this->assertSame(true, $invoke('garbage', true));
		$this->assertSame(false, $invoke('garbage', false));
	}

	public function testStringParamTrimsAndFallsBack(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'present' => '  hello  ',
				'array' => ['unexpected'],
				default => $default,
			}
		);

		$controller = $this->buildController($request);

		$method = new ReflectionMethod(ApiController::class, 'stringParam');

		$this->assertSame('hello', $method->invoke($controller, 'present', 'default'));
		$this->assertSame('default', $method->invoke($controller, 'missing', 'default'));
		$this->assertSame('default', $method->invoke($controller, 'array', 'default'));
	}

	public function testInstallerServiceClassExists(): void {
		// Smoke test that InstallerService autoloads cleanly from the new namespace structure.
		$this->assertTrue(class_exists(InstallerService::class));
	}

	public function testAddTrustedSourceForbiddenForNonAdmin(): void {
		// The default mocked IGroupManager/IUserSession make isAdmin() false.
		$response = $this->buildController()->addTrustedSource();

		$this->assertSame(403, $response->getStatus());
	}

	public function testRemoveTrustedSourceForbiddenForNonAdmin(): void {
		$response = $this->buildController()->removeTrustedSource();

		$this->assertSame(403, $response->getStatus());
	}

	public function testAddTrustedSourceAsAdminReturnsPatterns(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'forge' => 'github',
				'owner' => 'ConductionNL',
				'repo' => null,
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('addTrustedPattern')
			->with('github', 'ConductionNL', null)
			->willReturn(['github:ConductionNL/*']);

		$response = $this->buildAdminController($installer, $request)->addTrustedSource();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['trustedPatterns' => ['github:ConductionNL/*']], $response->getData());
	}

	public function testRemoveTrustedSourceAsAdminReturnsRemainingPatterns(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'pattern' => 'github:ConductionNL/*',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('removeTrustedPattern')
			->with('github:ConductionNL/*')
			->willReturn([]);

		$response = $this->buildAdminController($installer, $request)->removeTrustedSource();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['trustedPatterns' => []], $response->getData());
	}

	public function testAuditLogForbiddenForNonAdmin(): void {
		$response = $this->buildController()->auditLog();

		$this->assertSame(403, $response->getStatus());
	}

	public function testAuditLogAsAdminUsesDefaultPaginationAndNoAppFilter(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $default
		);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('findPage')
			->with(null, 50, 0)
			->willReturn([]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, $mapper)->auditLog();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['entries']);
		$this->assertSame(50, $data['limit']);
		$this->assertSame(0, $data['offset']);
	}

	public function testAuditLogFiltersByAppIdAndClampsLimit(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'appId' => 'openregister',
				'limit' => '9999',
				'offset' => '50',
				default => $default,
			}
		);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('findPage')
			->with('openregister', 200, 50)
			->willReturn([]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, $mapper)->auditLog();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(200, $response->getData()['limit']);
	}

	public function testAuditLogReturnsSerializedEntries(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $default
		);

		$entry = new AuditEntry();
		$entry->setId(1);
		$entry->setActorUid('alice');
		$entry->setAppId('openregister');
		$entry->setOperation('install');
		$entry->setFromVersion('2.5.0');
		$entry->setToVersion('2.3.0');
		$entry->setSourceId('appstore');
		$entry->setStatus('success');
		$entry->setMessage(null);
		$entry->setCreatedAt('2026-07-23 12:00:00');

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('findPage')->willReturn([$entry]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, $mapper)->auditLog();

		$data = $response->getData();
		$this->assertCount(1, $data['entries']);
		$this->assertSame($entry, $data['entries'][0]);
		$this->assertSame([
			'id' => 1,
			'actorUid' => 'alice',
			'appId' => 'openregister',
			'operation' => 'install',
			'fromVersion' => '2.5.0',
			'toVersion' => '2.3.0',
			'sourceId' => 'appstore',
			'status' => 'success',
			'message' => null,
			'createdAt' => '2026-07-23 12:00:00',
		], $entry->jsonSerialize());
	}
}
