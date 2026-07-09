<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use OCA\AppVersions\Service\ExternalReleaseInstallerService;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\SelectedReleaseInstallerService;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression tests for the trust gate in InstallerService::resolveBinding.
 *
 * Before PR #21 the gate was kind-specific (only KIND_GITHUB_RELEASE went
 * through TrustedSourceList), which silently disabled the allowlist for any
 * new binding kind. This test suite guards against re-introducing that
 * shape: every non-appstore binding — regardless of kind — must be
 * authorised BEFORE any HTTP fetch or filesystem write happens.
 */
final class InstallerServiceBindingGuardTest extends TestCase {
	private function buildService(TrustedSourceList $trusted, SourceBindingStore $store): InstallerService {
		return new InstallerService(
			$this->createMock(IAppManager::class),
			$this->createMock(IConfig::class),
			$this->createMock(SourceRegistry::class),
			$store,
			$trusted,
			$this->createMock(SelectedReleaseInstallerService::class),
			$this->createMock(ExternalReleaseInstallerService::class),
		);
	}

	private function invokeResolveBinding(InstallerService $service, string $appId, ?string $sourceOverride): SourceBinding {
		$method = new ReflectionMethod(InstallerService::class, 'resolveBinding');

		return $method->invoke($service, $appId, $sourceOverride);
	}

	public function testUntrustedGiteaSourceOverrideIsRejectedBeforeAnyFetch(): void {
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->once())
			->method('assertBindingAllowed')
			->willThrowException(new UntrustedSourceException('gitea:evil.host/foo/bar', 'not on allowlist'));

		$store = $this->createMock(SourceBindingStore::class);
		// The store must NOT be consulted for a source-override path.
		$store->expects($this->never())->method('get');

		$service = $this->buildService($trusted, $store);

		$this->expectException(UntrustedSourceException::class);
		$this->invokeResolveBinding($service, 'opencatalogi', 'gitea:evil.host/foo/bar');
	}

	public function testUntrustedGithubSourceOverrideIsRejectedBeforeAnyFetch(): void {
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->once())
			->method('assertBindingAllowed')
			->willThrowException(new UntrustedSourceException('github:evil/x', 'not on allowlist'));

		$store = $this->createMock(SourceBindingStore::class);
		$store->expects($this->never())->method('get');

		$service = $this->buildService($trusted, $store);

		$this->expectException(UntrustedSourceException::class);
		$this->invokeResolveBinding($service, 'opencatalogi', 'github:evil/x');
	}

	public function testTrustedGiteaSourceOverrideIsAcceptedAfterGuardPasses(): void {
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->once())->method('assertBindingAllowed'); // does not throw

		$store = $this->createMock(SourceBindingStore::class);
		$store->expects($this->never())->method('get');

		$service = $this->buildService($trusted, $store);

		$binding = $this->invokeResolveBinding($service, 'opencatalogi', 'gitea:codeberg.org/Conduction/opencatalogi');
		$this->assertSame(SourceBinding::KIND_GITEA_RELEASE, $binding->kind);
	}

	public function testStoredUntrustedGiteaBindingIsRejectedOnReadPath(): void {
		$stored = SourceBinding::gitea('evil.host', 'foo', 'bar');

		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->once())
			->method('assertBindingAllowed')
			->with($stored)
			->willThrowException(new UntrustedSourceException($stored->getId(), 'not on allowlist'));

		$store = $this->createMock(SourceBindingStore::class);
		$store->method('get')->willReturn($stored);

		$service = $this->buildService($trusted, $store);

		$this->expectException(UntrustedSourceException::class);
		$this->invokeResolveBinding($service, 'opencatalogi', null);
	}

	public function testStoredTrustedGiteaBindingIsAccepted(): void {
		$stored = SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi');

		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->once())->method('assertBindingAllowed')->with($stored);

		$store = $this->createMock(SourceBindingStore::class);
		$store->method('get')->willReturn($stored);

		$service = $this->buildService($trusted, $store);

		$binding = $this->invokeResolveBinding($service, 'opencatalogi', null);
		$this->assertSame($stored, $binding);
	}

	public function testAppstoreFallbackSkipsGuard(): void {
		$trusted = $this->createMock(TrustedSourceList::class);
		// No stored binding + no override → fallback to appstore, no assert
		// call needed because the primitive short-circuits appstore internally
		// via extractIdentifier() returning null. This test locks that
		// fallback in — if resolveBinding were ever to eagerly call
		// assertBindingAllowed on the appstore constant, this expectation
		// would be violated.
		$trusted->expects($this->never())->method('assertBindingAllowed');

		$store = $this->createMock(SourceBindingStore::class);
		$store->method('get')->willReturn(null);

		$service = $this->buildService($trusted, $store);

		$binding = $this->invokeResolveBinding($service, 'opencatalogi', null);
		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
	}
}
