<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\ListVersions;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ListVersionsTest extends TestCase {
	public function testHumanListingPrintsInstalledAndAvailableVersionsWithCompatibilityMarkers(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects(self::once())
			->method('getAppVersions')
			->with('openregister', null)
			->willReturn([
				'installedVersion' => '1.0.0',
				'availableVersions' => [
					['version' => '1.0.0', 'changelog' => null, 'recordedSha' => null],
					['version' => '1.1.0', 'changelog' => null, 'recordedSha' => null],
					['version' => '0.9.0', 'changelog' => null, 'recordedSha' => null],
				],
				'versions' => [],
				'source' => 'appstore',
				'sourceId' => 'appstore',
				'statusCode' => Http::STATUS_OK,
				'hasError' => false,
			]);

		$tester = new CommandTester(new ListVersions($installer));
		$exitCode = $tester->execute(['appId' => 'openregister']);
		$display = $tester->getDisplay();

		self::assertSame(0, $exitCode);
		self::assertStringContainsString('1.0.0', $display);
		self::assertStringContainsString('installed', $display);
		self::assertStringContainsString('newer', $display);
		self::assertStringContainsString('older', $display);
	}

	public function testJsonListingEmitsMachineReadableEnvelopeWithRequiredFields(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getAppVersions')->willReturn([
			'installedVersion' => '1.0.0',
			'availableVersions' => [['version' => '1.1.0', 'changelog' => null, 'recordedSha' => null]],
			'versions' => [],
			'source' => 'appstore',
			'sourceId' => 'appstore',
			'statusCode' => Http::STATUS_OK,
			'hasError' => false,
		]);

		$tester = new CommandTester(new ListVersions($installer));
		$exitCode = $tester->execute(['appId' => 'openregister', '--json' => true]);

		self::assertSame(0, $exitCode);
		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('1.0.0', $decoded['installedVersion']);
		self::assertSame('appstore', $decoded['sourceId']);
		self::assertArrayHasKey('availableVersions', $decoded);
		self::assertArrayNotHasKey('statusCode', $decoded);
		self::assertArrayNotHasKey('hasError', $decoded);
	}

	public function testUnknownAppExitsNonZeroAndNamesTheProblemOnStderr(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getAppVersions')->willReturn([
			'installedVersion' => null,
			'availableVersions' => [],
			'versions' => [],
			'source' => 'appstore',
			'sourceId' => 'appstore',
			'statusCode' => Http::STATUS_OK,
			'hasError' => true,
			'error' => 'App is not available in the Nextcloud App Store.',
		]);

		$tester = new CommandTester(new ListVersions($installer));
		$exitCode = $tester->execute(['appId' => 'nope'], ['capture_stderr_separately' => true]);

		self::assertNotSame(0, $exitCode);
		self::assertStringContainsString('App is not available', $tester->getErrorOutput());
	}

	public function testSelfManagedAppErrorEnvelopeExitsNonZero(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getAppVersions')->willReturn([
			'installedVersion' => null,
			'availableVersions' => [],
			'versions' => [],
			'source' => 'none',
			'sourceId' => 'none',
			'statusCode' => Http::STATUS_FORBIDDEN,
			'hasError' => true,
			'error' => 'This app cannot be managed from Versioniq.',
		]);

		$tester = new CommandTester(new ListVersions($installer));
		$exitCode = $tester->execute(['appId' => 'versioniq'], ['capture_stderr_separately' => true]);

		self::assertNotSame(0, $exitCode);
		self::assertStringContainsString('cannot be managed', $tester->getErrorOutput());
	}

	public function testSourceOverrideIsForwardedToTheService(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects(self::once())
			->method('getAppVersions')
			->with('pipelinq', 'github:ConductionNL/pipelinq')
			->willReturn([
				'installedVersion' => null,
				'availableVersions' => [],
				'versions' => [],
				'source' => 'github-release',
				'sourceId' => 'github:ConductionNL/pipelinq',
				'statusCode' => Http::STATUS_OK,
				'hasError' => false,
			]);

		$tester = new CommandTester(new ListVersions($installer));
		$exitCode = $tester->execute(['appId' => 'pipelinq', '--source' => 'github:ConductionNL/pipelinq']);

		self::assertSame(0, $exitCode);
	}
}
