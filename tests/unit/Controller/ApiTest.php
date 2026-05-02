<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Controller;

use OCA\AppVersions\Controller\ApiController;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Discovery\DiscoveryAggregator;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\Pat\PatDeeplinkBuilder;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatValidator;
use OCP\IGroupManager;
use OCP\IRequest;
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

	private function buildController(?IRequest $request = null): ApiController {
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
			$this->createMock(DiscoveryAggregator::class),
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
}
