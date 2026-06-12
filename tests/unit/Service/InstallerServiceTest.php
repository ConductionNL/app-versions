<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service;

use Exception;
use OCA\AppVersions\Service\ExternalReleaseInstallerService;
use OCA\AppVersions\Service\Installer\EnvironmentCheck;
use OCA\AppVersions\Service\Installer\FailureClassifier;
use OCA\AppVersions\Service\Installer\InstallFailure;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\SelectedReleaseInstallerService;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCA\AppVersions\Service\Source\SourceInterface;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InstallerServiceTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private SourceRegistry&MockObject $sourceRegistry;
	private SourceBindingStore&MockObject $bindingStore;
	private TrustedSourceList&MockObject $trustedSources;
	private SelectedReleaseInstallerService&MockObject $signedInstaller;
	private ExternalReleaseInstallerService&MockObject $externalInstaller;
	private EnvironmentCheck&MockObject $environmentCheck;
	private FailureClassifier $failureClassifier;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->sourceRegistry = $this->createMock(SourceRegistry::class);
		$this->bindingStore = $this->createMock(SourceBindingStore::class);
		$this->trustedSources = $this->createMock(TrustedSourceList::class);
		$this->signedInstaller = $this->createMock(SelectedReleaseInstallerService::class);
		$this->externalInstaller = $this->createMock(ExternalReleaseInstallerService::class);
		$this->environmentCheck = $this->createMock(EnvironmentCheck::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$this->failureClassifier = new FailureClassifier($factory);

		// Common happy-path stubs: not core, fresh-ish install, trusted binding.
		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->bindingStore->method('get')->willReturn(null);
		$this->config->method('getSystemValueBool')->willReturn(true); // maintenance already on
	}

	private function service(): InstallerService {
		return new InstallerService(
			$this->appManager,
			$this->config,
			$this->appConfig,
			$this->sourceRegistry,
			$this->bindingStore,
			$this->trustedSources,
			$this->signedInstaller,
			$this->externalInstaller,
			$this->failureClassifier,
			$this->environmentCheck,
		);
	}

	private function stubSignedSourceReturning(): SourceInterface&MockObject {
		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.0.0']);
		$this->sourceRegistry->method('get')->willReturn($source);
		$this->signedInstaller->method('getDebugLog')->willReturn([]);

		return $source;
	}

	public function testPreflightGuardAbortsBeforeDownloadOnNonWritableDestination(): void {
		$this->appManager->method('getAppPath')->willReturn('/var/www/html/apps-extra/pipelinq');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(false);

		// The guard must fire before any release resolution / download.
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('pipelinq', '2.0.0', false);

		self::assertSame(Http::STATUS_CONFLICT, $result['statusCode']);
		self::assertNotSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION, $result['payload']['category']);
		self::assertSame(FailureClassifier::STAGE_REQUESTED, $result['payload']['stage']);
		self::assertNotSame('', $result['payload']['hint']);
	}

	public function testStructuredFailureFieldsPresentWithDebugOff(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(new Exception('Could not download selected release: timeout.'));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_BAD_GATEWAY, $result['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_DOWNLOAD, $result['payload']['category']);
		self::assertArrayHasKey('stage', $result['payload']);
		self::assertArrayHasKey('hint', $result['payload']);
		self::assertNotSame('', $result['payload']['hint']);
		// Debug was OFF, so no debug breadcrumb array is attached…
		self::assertArrayNotHasKey('debug', $result['payload']);
		// …but the structured fields are still present.
		self::assertSame('failed', $result['payload']['installStatus']);
	}

	public function testFinalizeFailureReportsInstalledButBroken(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::finalizeFailed('migration step failed', FailureClassifier::RESTORE_CLEAN));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN, $result['payload']['installStatus']);
		self::assertSame(FailureClassifier::CATEGORY_FINALIZE, $result['payload']['category']);
		self::assertStringContainsString('database', strtolower((string)$result['payload']['hint']));
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result['statusCode']);
	}

	public function testFinalizeFailureWithFailedRestoreUsesIndeterminateHint(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::finalizeFailed('migration step failed', FailureClassifier::RESTORE_FAILED));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN, $result['payload']['installStatus']);
		self::assertStringContainsString('indeterminate', strtolower((string)$result['payload']['hint']));
	}

	public function testFreshInstallFinalizeFailureDoesNotClaimLostPreviousFiles(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		// No prior version existed (RESTORE_NONE) — the hint must not tell the
		// admin that previous files could not be restored.
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::finalizeFailed('migration step failed', FailureClassifier::RESTORE_NONE));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN, $result['payload']['installStatus']);
		$hint = strtolower((string)$result['payload']['hint']);
		self::assertStringNotContainsString('indeterminate', $hint);
		self::assertStringNotContainsString('previous app files could not', $hint);
		self::assertStringContainsString('fresh install', $hint);
	}

	public function testPreFinalizeFailureReportsReverted(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::reverted('Could not copy app file "x".', 'copy'));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_REVERTED, $result['payload']['installStatus']);
		self::assertNotSame('', $result['payload']['hint']);
	}
}
