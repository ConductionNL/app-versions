<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service;

use Exception;
use InvalidArgumentException;
use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IConfig;

/**
 * Coordinates the version-management flow:
 *   - lists installed apps
 *   - resolves the active source binding for an app (sticky GitHub or App Store fallback)
 *   - delegates version listing to the matching source driver
 *   - dispatches install to either the signed installer (App Store) or the
 *     external installer (GitHub releases), then writes the binding on success.
 */
class InstallerService {
	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private SourceRegistry $sourceRegistry,
		private SourceBindingStore $bindingStore,
		private TrustedSourceList $trustedSources,
		private SelectedReleaseInstallerService $signedInstaller,
		private ExternalReleaseInstallerService $externalInstaller,
	) {
	}

	/**
	 * Returns installed apps enriched with metadata for frontend cards.
	 *
	 * @return list<array{id:string,label:string,description:string,summary:string,preview:string,isCore:bool,boundSourceId:?string}>
	 */
	public function getInstalledApps(): array {
		$installedApps = array_values(array_filter(
			$this->appManager->getInstalledApps(),
			fn (string $appId): bool => !$this->isSelfManagedApp($appId)
		));
		sort($installedApps);
		$alwaysEnabledApps = $this->appManager->getAlwaysEnabledApps();
		$appList = [];
		foreach ((new \OC_App())->listAllApps() as $app) {
			if (!isset($app['id']) || !is_string($app['id'])) {
				continue;
			}
			$appList[$app['id']] = $app;
		}

		$bindingStore = $this->bindingStore;

		return array_map(
			static function (string $appId) use ($appList, $alwaysEnabledApps, $bindingStore): array {
				$app = $appList[$appId] ?? [];
				$name = isset($app['name']) && is_string($app['name']) && trim($app['name']) !== ''
					? trim($app['name'])
					: $appId;
				$description = isset($app['description']) && is_string($app['description']) ? trim($app['description']) : '';
				$summary = isset($app['summary']) && is_string($app['summary']) ? trim($app['summary']) : '';
				$preview = isset($app['preview']) && is_string($app['preview']) ? trim($app['preview']) : '';
				$binding = $bindingStore->get($appId);

				return [
					'id' => $appId,
					'label' => $name,
					'description' => $description,
					'summary' => $summary,
					'preview' => $preview,
					'isCore' => in_array($appId, $alwaysEnabledApps, true),
					'boundSourceId' => $binding?->getId(),
				];
			},
			$installedApps
		);
	}

	/**
	 * @return array{installedVersion: ?string, availableVersions: list<array{version:string}>, versions: list<array{version:string}>, source: string, sourceId: string, statusCode: int, hasError: bool, error?: string}
	 */
	public function getAppVersions(string $appId, ?string $sourceOverride = null): array {
		$appId = trim($appId);
		if ($appId === '') {
			return $this->errorEnvelope('Missing app id.', Http::STATUS_BAD_REQUEST);
		}
		if ($this->isSelfManagedApp($appId) || $this->isCoreProtectedApp($appId)) {
			return $this->errorEnvelope(
				$this->isCoreProtectedApp($appId)
					? 'This core app cannot be managed from App Versions.'
					: 'This app cannot be managed from App Versions.',
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$binding = $this->resolveBinding($appId, $sourceOverride);
		} catch (InvalidArgumentException $error) {
			return $this->errorEnvelope($error->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (UntrustedSourceException $error) {
			return $this->errorEnvelope($error->getMessage(), Http::STATUS_FORBIDDEN);
		}

		$source = $this->sourceRegistry->get($binding);
		$result = $source->listVersions($appId, $binding);

		$installedVersion = null;
		try {
			$installed = $this->appManager->getAppVersion($appId);
			if (is_string($installed) && $installed !== '') {
				$installedVersion = $installed;
			}
		} catch (Exception) {
			$installedVersion = null;
		}

		$envelope = [
			'installedVersion' => $installedVersion,
			'availableVersions' => $result['versions'],
			'versions' => $result['versions'],
			'source' => $binding->kind,
			'sourceId' => $binding->getId(),
			'statusCode' => Http::STATUS_OK,
			'hasError' => $result['error'] !== null && $result['versions'] === [],
		];
		if ($result['error'] !== null) {
			$envelope['error'] = $result['error'];
		}

		return $envelope;
	}

	/**
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	public function installAppVersion(
		string $appId,
		string $targetVersion,
		bool $includeDebug,
		?string $sourceOverride = null,
	): array {
		$appId = trim($appId);
		$targetVersion = trim($targetVersion);
		if ($appId === '' || $targetVersion === '') {
			return [
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'payload' => ['message' => 'Missing app id or version.'],
			];
		}
		if ($this->isSelfManagedApp($appId) || $this->isCoreProtectedApp($appId)) {
			return [
				'statusCode' => Http::STATUS_FORBIDDEN,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => $this->isCoreProtectedApp($appId)
						? 'This core app cannot be installed or updated from App Versions.'
						: 'This app cannot be installed or updated from App Versions.',
				],
			];
		}

		try {
			$binding = $this->resolveBinding($appId, $sourceOverride);
			$this->trustedSources->assertBindingAllowed($binding);
		} catch (InvalidArgumentException $error) {
			return ['statusCode' => Http::STATUS_BAD_REQUEST, 'payload' => ['message' => $error->getMessage()]];
		} catch (UntrustedSourceException $error) {
			return ['statusCode' => Http::STATUS_FORBIDDEN, 'payload' => ['message' => $error->getMessage()]];
		}

		try {
			$this->appManager->clearAppsCache();
			$installedVersion = $this->appManager->getAppVersion($appId, false);
		} catch (Exception) {
			$installedVersion = '';
		}

		if ($installedVersion !== '' && version_compare($targetVersion, $installedVersion, '=') === 0) {
			return [
				'statusCode' => Http::STATUS_OK,
				'payload' => [
					'appId' => $appId,
					'fromVersion' => $installedVersion,
					'toVersion' => $targetVersion,
					'message' => 'App already has this version installed.',
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}

		$source = $this->sourceRegistry->get($binding);
		$release = $source->resolveRelease($appId, $targetVersion, $binding);
		if ($release === null) {
			return [
				'statusCode' => Http::STATUS_NOT_FOUND,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => 'Requested version not found in source metadata.',
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}
		if (isset($release['error']) && is_string($release['error'])) {
			return [
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => $release['error'],
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}

		$maintenanceWasSet = false;
		$dryRun = $includeDebug;
		try {
			if (!$this->config->getSystemValueBool('maintenance', false)) {
				$maintenanceWasSet = true;
				$this->config->setSystemValue('maintenance', true);
			}

			if ($source->getInstallerKind() === \OCA\AppVersions\Service\Source\SourceInterface::INSTALLER_SIGNED) {
				$result = $this->signedInstaller->installFromSelectedRelease($appId, $release, $dryRun);
				$integrityWarning = null;
			} else {
				$result = $this->externalInstaller->installFromExternalRelease($appId, $targetVersion, $release, $binding, $dryRun);
				$integrityWarning = $result['integrityWarning'] ?? null;
			}

			if (!$dryRun) {
				$this->appManager->clearAppsCache();
				$this->bindingStore->set($appId, $binding);
			}

			$this->appManager->clearAppsCache();
			$appVersion = null;
			try {
				$appPath = $this->appManager->getAppPath($appId, true);
				$appInfo = $this->appManager->getAppInfoByPath($appPath . '/appinfo/info.xml');
				if (is_array($appInfo) && isset($appInfo['version']) && is_string($appInfo['version'])) {
					$appVersion = $appInfo['version'];
				}
			} catch (Exception) {
				$appVersion = null;
			}
			if ($appVersion === null) {
				$appVersion = $this->appManager->getAppVersion($appId, false);
			}
			$configuredVersion = (string)$this->config->getAppValue($appId, 'installed_version', $appVersion ?? '');
			if ($configuredVersion !== '') {
				$appVersion = $configuredVersion;
			}

			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $dryRun ? $targetVersion : $appVersion,
				'installedVersion' => $appVersion,
				'updateType' => $this->classifyUpdateType($installedVersion, $appVersion ?? '', $dryRun),
				'message' => $this->classifyMessage($installedVersion, $appVersion ?? '', $dryRun),
				'dryRun' => $dryRun,
				'installStatus' => $result['status'] ?? 'unknown',
				'sourceId' => $binding->getId(),
			];
			if ($integrityWarning !== null) {
				$payload['integrityWarning'] = $integrityWarning;
			}
			if ($includeDebug) {
				$payload['debug'] = $result['debug'] ?? [];
			}

			return ['statusCode' => Http::STATUS_OK, 'payload' => $payload];
		} catch (UntrustedSourceException $error) {
			return [
				'statusCode' => Http::STATUS_FORBIDDEN,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => $error->getMessage(),
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		} catch (Exception $error) {
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $error->getMessage(),
				'sourceId' => $binding->getId(),
			];
			if ($includeDebug) {
				$payload['debug'] = $source->getInstallerKind() === \OCA\AppVersions\Service\Source\SourceInterface::INSTALLER_SIGNED
					? $this->signedInstaller->getDebugLog()
					: $this->externalInstaller->getDebugLog();
			}

			return ['statusCode' => Http::STATUS_INTERNAL_SERVER_ERROR, 'payload' => $payload];
		} finally {
			if ($maintenanceWasSet) {
				$this->config->setSystemValue('maintenance', false);
			}
		}
	}

	public function bindSource(string $appId, SourceBinding $binding): void {
		$this->trustedSources->assertBindingAllowed($binding);
		$this->bindingStore->set($appId, $binding);
	}

	public function getBinding(string $appId): ?SourceBinding {
		return $this->bindingStore->get($appId);
	}

	public function getTrustedSources(): TrustedSourceList {
		return $this->trustedSources;
	}

	public function getSourceRegistry(): SourceRegistry {
		return $this->sourceRegistry;
	}

	private function resolveBinding(string $appId, ?string $sourceOverride): SourceBinding {
		if ($sourceOverride !== null && trim($sourceOverride) !== '') {
			$binding = SourceRegistry::parseSourceId($sourceOverride);
			if ($binding->kind === SourceBinding::KIND_GITHUB_RELEASE) {
				$this->trustedSources->assertBindingAllowed($binding);
			}

			return $binding;
		}

		$stored = $this->bindingStore->get($appId);
		if ($stored !== null) {
			if ($stored->kind === SourceBinding::KIND_GITHUB_RELEASE) {
				$this->trustedSources->assertBindingAllowed($stored);
			}

			return $stored;
		}

		return SourceBinding::appStore();
	}

	private function classifyUpdateType(string $previousVersion, string $newVersion, bool $dryRun): string {
		if ($dryRun) {
			return 'dry-run';
		}
		if ($previousVersion === '') {
			return 'install';
		}
		if ($newVersion === '' || $newVersion === $previousVersion) {
			return 'none';
		}

		return version_compare($newVersion, $previousVersion, '>') === 1 ? 'upgrade' : 'downgrade';
	}

	private function classifyMessage(string $previousVersion, string $newVersion, bool $dryRun): string {
		if ($dryRun) {
			return 'Dry run mode: no changes were applied.';
		}
		if ($previousVersion === '') {
			return 'App installed.';
		}
		if ($newVersion === '' || $newVersion === $previousVersion) {
			return 'App already at selected version.';
		}

		return version_compare($newVersion, $previousVersion, '<') === -1 ? 'App downgraded.' : 'App updated.';
	}

	/**
	 * @return array{installedVersion: ?string, availableVersions: list<array{version:string}>, versions: list<array{version:string}>, source: string, sourceId: string, statusCode: int, hasError: bool, error: string}
	 */
	private function errorEnvelope(string $message, int $statusCode): array {
		return [
			'installedVersion' => null,
			'availableVersions' => [],
			'versions' => [],
			'source' => 'none',
			'sourceId' => 'none',
			'statusCode' => $statusCode,
			'hasError' => true,
			'error' => $message,
		];
	}

	private function isSelfManagedApp(string $appId): bool {
		return trim($appId) === Application::APP_ID;
	}

	private function isCoreProtectedApp(string $appId): bool {
		return in_array(trim($appId), $this->appManager->getAlwaysEnabledApps(), true);
	}
}
