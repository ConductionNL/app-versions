<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Tests\Unit\Service\Advisory;

use OCA\AppVersions\Service\Advisory\AdvisoryService;
use OCA\AppVersions\Service\Advisory\AdvisorySourceInterface;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCA\AppVersions\Service\Source\SourceInterface;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryServiceTest extends TestCase {
	private function service(): AdvisoryService {
		return new AdvisoryService(
			$this->createMock(SourceRegistry::class),
			$this->createMock(SourceBindingStore::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}> $advisories
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}
	 */
	private function advisory(string $id, array $affected, ?string $firstPatched): array {
		return [
			'id' => $id,
			'severity' => 'high',
			'summary' => 'Example advisory ' . $id,
			'affected' => $affected,
			'firstPatchedVersion' => $firstPatched,
		];
	}

	public function testNoAdvisoriesYieldsNoneState(): void {
		$result = $this->service()->evaluate('1.2.0', [], ['1.2.0', '1.3.0']);

		$this->assertSame(AdvisoryService::STATE_NONE, $result['state']);
		$this->assertSame([], $result['advisories']);
		$this->assertNull($result['recommendedVersion']);
	}

	public function testInstalledVersionUnaffectedYieldsAdvisoryAvailable(): void {
		// Advisory affects only < 1.1.0; installed 1.2.0 is safe.
		$advisories = [$this->advisory('GHSA-aaaa', ['< 1.1.0'], '1.1.0')];

		$result = $this->service()->evaluate('1.2.0', $advisories, ['1.2.0', '1.3.0']);

		$this->assertSame(AdvisoryService::STATE_AVAILABLE, $result['state']);
		$this->assertCount(1, $result['advisories']);
		$this->assertNull($result['recommendedVersion']);
	}

	public function testPinnedToVulnerableFlaggedWithRecommendation(): void {
		// Installed 1.0.5 is within ">= 1.0.0, < 1.2.3"; fixed in 1.2.3.
		$advisories = [$this->advisory('GHSA-bbbb', ['>= 1.0.0', '< 1.2.3'], '1.2.3')];

		$result = $this->service()->evaluate('1.0.5', $advisories, ['1.0.5', '1.1.0', '1.2.3', '1.3.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertSame('1.2.3', $result['recommendedVersion']);
		$this->assertSame('GHSA-bbbb', $result['advisories'][0]['id']);
		// Summary/severity are surfaced; internal range fields are dropped.
		$this->assertArrayNotHasKey('affected', $result['advisories'][0]);
	}

	public function testRecommendationSkipsAvailableVersionsStillInRange(): void {
		// Installed 1.0.0 affected by "< 1.2.3"; 1.1.0 is still vulnerable, so
		// the nearest resolving version must be 1.2.3, not 1.1.0.
		$advisories = [$this->advisory('GHSA-cccc', ['< 1.2.3'], null)];

		$result = $this->service()->evaluate('1.0.0', $advisories, ['1.1.0', '1.2.3', '2.0.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertSame('1.2.3', $result['recommendedVersion']);
	}

	public function testStuckOnVulnerableWhenNoResolvingVersionExists(): void {
		// Affected by "<= 3.0.0" and no newer version is offered by the source.
		$advisories = [$this->advisory('GHSA-dddd', ['<= 3.0.0'], null)];

		$result = $this->service()->evaluate('3.0.0', $advisories, ['2.9.0', '3.0.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertNull($result['recommendedVersion']);
	}

	public function testEmptyAffectedRangeMeansAllVersionsAffected(): void {
		$advisories = [$this->advisory('GHSA-eeee', [], '9.9.9')];

		$result = $this->service()->evaluate('1.0.0', $advisories, ['1.0.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
	}

	public function testCorrelateWiresSourceAndInstalledVersionEndToEnd(): void {
		$binding = SourceBinding::appStore();

		$source = new class implements SourceInterface, AdvisorySourceInterface {
			public function getKind(): string {
				return SourceBinding::KIND_APPSTORE;
			}

			public function getInstallerKind(): string {
				return self::INSTALLER_SIGNED;
			}

			public function listVersions(string $appId, SourceBinding $binding): array {
				return ['versions' => [['version' => '1.2.3'], ['version' => '1.0.5']], 'error' => null];
			}

			public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
				return null;
			}

			public function listAdvisories(string $appId, SourceBinding $binding): array {
				return [
					'advisories' => [[
						'id' => 'GHSA-ffff',
						'severity' => 'critical',
						'summary' => 'RCE',
						'affected' => ['< 1.2.3'],
						'firstPatchedVersion' => '1.2.3',
					]],
					'error' => null,
				];
			}
		};

		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($source);

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturn($binding);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.5');

		$service = new AdvisoryService($registry, $bindingStore, $appManager, $this->createMock(LoggerInterface::class));
		$result = $service->correlate('someapp');

		$this->assertSame('someapp', $result['appId']);
		$this->assertSame('1.0.5', $result['installedVersion']);
		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertSame('1.2.3', $result['recommendedVersion']);
		$this->assertNull($result['error']);
	}

	public function testCorrelateReturnsNoneWhenSourceCannotAnswerAdvisories(): void {
		$binding = SourceBinding::appStore();

		// A source that implements only SourceInterface (no advisory capability).
		$source = $this->createMock(SourceInterface::class);

		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($source);

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturn($binding);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		$service = new AdvisoryService($registry, $bindingStore, $appManager, $this->createMock(LoggerInterface::class));
		$result = $service->correlate('plainapp');

		$this->assertSame(AdvisoryService::STATE_NONE, $result['state']);
		$this->assertNull($result['error']);
	}
}
