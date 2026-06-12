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
use OCA\AppVersions\Service\Installer\EnvironmentCheck;
use OCA\AppVersions\Service\Installer\FailureClassifier;
use OCA\AppVersions\Service\Installer\InstallFailure;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCA\AppVersions\Service\Source\SourceInterface;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Coordinates the version-management flow:
 *   - lists installed apps
 *   - resolves the active source binding for an app (sticky GitHub or App Store fallback)
 *   - delegates version listing to the matching source driver
 *   - dispatches install to either the signed installer (App Store) or the
 *     external installer (GitHub releases), then writes the binding on success.
 *
 * @psalm-api
 */
class InstallerService {
	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private IAppConfig $appConfig,
		private SourceRegistry $sourceRegistry,
		private SourceBindingStore $bindingStore,
		private TrustedSourceList $trustedSources,
		private SelectedReleaseInstallerService $signedInstaller,
		private ExternalReleaseInstallerService $externalInstaller,
		private FailureClassifier $failureClassifier,
		private EnvironmentCheck $environmentCheck,
	) {
	}

	/**
	 * Returns installed apps enriched with metadata for frontend cards; see "List Installed Apps".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @return list<array{id:string,label:string,description:string,summary:string,preview:string,isCore:bool,boundSourceId:?string,manageable:bool,warning:?string}>
	 */
	public function getInstalledApps(): array {
		$installedApps = array_values(array_filter(
			$this->appManager->getEnabledApps(),
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
		$appManager = $this->appManager;
		$environmentCheck = $this->environmentCheck;

		return array_map(
			static function (string $appId) use ($appList, $alwaysEnabledApps, $bindingStore, $appManager, $environmentCheck): array {
				$app = $appList[$appId] ?? [];
				$name = isset($app['name']) && is_string($app['name']) && trim($app['name']) !== ''
					? trim($app['name'])
					: $appId;
				$description = isset($app['description']) && is_string($app['description']) ? trim($app['description']) : '';
				$summary = isset($app['summary']) && is_string($app['summary']) ? trim($app['summary']) : '';
				$preview = isset($app['preview']) && is_string($app['preview']) ? trim($app['preview']) : '';
				$binding = $bindingStore->get($appId);

				// Proactively flag apps whose folder cannot be managed here
				// (e.g. a bind-mounted dev checkout that is not writable).
				$env = ['manageable' => true, 'warning' => null];
				try {
					$env = $environmentCheck->inspect($appManager->getAppPath($appId));
				} catch (\Throwable) {
					// Path unknown — leave defaults; install-time guard still applies.
				}

				return [
					'id' => $appId,
					'label' => $name,
					'description' => $description,
					'summary' => $summary,
					'preview' => $preview,
					'isCore' => in_array($appId, $alwaysEnabledApps, true),
					'boundSourceId' => $binding?->getId(),
					'manageable' => $env['manageable'],
					'warning' => $env['warning'],
				];
			},
			$installedApps
		);
	}

	/**
	 * Resolves the active source and lists versions for an app; see "Fetch Available Versions" and "Explicit source override".
	 *
	 * @spec openspec/specs/version-management/spec.md
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
			if ($installed !== '') {
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
	 * Installs a target version via the matching installer and persists the binding on success; see "Install Specific Version" and "Source binding".
	 *
	 * @spec openspec/specs/version-management/spec.md
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

		if ($installedVersion !== '' && version_compare($targetVersion, $installedVersion, '=')) {
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

		// Pre-flight: replacing an existing app renames its folder, which needs a
		// writable parent directory. A bind-mounted dev checkout (owned by another
		// uid) is not writable by the web-server user, so the install can never
		// succeed — fail fast before downloading anything.
		try {
			$existingPath = $this->appManager->getAppPath($appId);
		} catch (Exception) {
			$existingPath = null;
		}
		if ($existingPath !== null && !$this->environmentCheck->isDestinationWritable($existingPath)) {
			$category = FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION;

			return [
				'statusCode' => $this->failureClassifier->httpStatusFor($category),
				'payload' => [
					'appId' => $appId,
					'fromVersion' => $installedVersion === '' ? null : $installedVersion,
					'toVersion' => $targetVersion,
					'message' => $this->failureClassifier->messageFor($category),
					'category' => $category,
					'stage' => FailureClassifier::STAGE_REQUESTED,
					'hint' => $this->failureClassifier->hintFor($category),
					'installStatus' => 'failed',
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
			$configuredVersion = $this->appConfig->getValueString($appId, 'installed_version', $appVersion);
			if ($configuredVersion !== '') {
				$appVersion = $configuredVersion;
			}

			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $dryRun ? $targetVersion : $appVersion,
				'installedVersion' => $appVersion,
				'updateType' => $this->classifyUpdateType($installedVersion, $appVersion, $dryRun),
				'message' => $this->classifyMessage($installedVersion, $appVersion, $dryRun),
				'dryRun' => $dryRun,
				'installStatus' => $result['status'] ?? 'unknown',
				'sourceId' => $binding->getId(),
			];
			if ($integrityWarning !== null) {
				$payload['integrityWarning'] = $integrityWarning;
			}
			if ($includeDebug) {
				$payload['debug'] = (array)($result['debug'] ?? []);
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
		} catch (InstallFailure $failure) {
			// The installer already handled filesystem recovery; report the
			// honest outcome (reverted / installed-but-broken) instead of a 500.
			$debugLog = $this->installerDebugLog($source);
			$isBroken = $failure->getOutcome() === InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN;
			$category = $isBroken
				? FailureClassifier::CATEGORY_FINALIZE
				: $this->failureClassifier->categoryFor($failure, $failure->getStage());
			$hint = $isBroken
				? $this->failureClassifier->finalizeHint($failure->getRestoreState())
				: $this->failureClassifier->revertedHint();
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $failure->getMessage(),
				'category' => $category,
				'stage' => $failure->getStage(),
				'hint' => $hint,
				'installStatus' => $failure->getOutcome(),
				'sourceId' => $binding->getId(),
			];
			if ($includeDebug) {
				$payload['debug'] = $debugLog;
			}

			return ['statusCode' => $this->failureClassifier->httpStatusFor($category), 'payload' => $payload];
		} catch (Exception $error) {
			// Classified failure: attach stage/category/hint regardless of debug,
			// and derive the HTTP status from the category (no blanket 500).
			$debugLog = $this->installerDebugLog($source);
			$lastStage = $this->lastStageOf($debugLog);
			$classification = $this->failureClassifier->classify($error, $lastStage);
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $error->getMessage(),
				'category' => $classification['category'],
				'stage' => $lastStage,
				'hint' => $classification['hint'],
				'installStatus' => 'failed',
				'sourceId' => $binding->getId(),
			];
			if ($includeDebug) {
				$payload['debug'] = $debugLog;
			}

			return ['statusCode' => $classification['statusCode'], 'payload' => $payload];
		} finally {
			if ($maintenanceWasSet) {
				$this->config->setSystemValue('maintenance', false);
			}
		}
	}

	/**
	 * Validates against the allowlist and persists a source binding; see "Source management API".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function bindSource(string $appId, SourceBinding $binding): void {
		$this->trustedSources->assertBindingAllowed($binding);
		$this->bindingStore->set($appId, $binding);
	}

	/**
	 * Returns the persisted source binding for an app; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getBinding(string $appId): ?SourceBinding {
		return $this->bindingStore->get($appId);
	}

	public function getTrustedSources(): TrustedSourceList {
		return $this->trustedSources;
	}

	public function getSourceRegistry(): SourceRegistry {
		return $this->sourceRegistry;
	}

	/**
	 * Returns the breadcrumb log of whichever installer handled the request.
	 * The two installers type their logs differently, so we use the looser
	 * common type and validate shape in {@see lastStageOf()}.
	 *
	 * @return array<int, mixed>
	 */
	private function installerDebugLog(SourceInterface $source): array {
		return $source->getInstallerKind() === SourceInterface::INSTALLER_SIGNED
			? $this->signedInstaller->getDebugLog()
			: $this->externalInstaller->getDebugLog();
	}

	/**
	 * The last stage reached in a breadcrumb log, used to classify failures.
	 *
	 * @param array<int, mixed> $debugLog
	 */
	private function lastStageOf(array $debugLog): ?string {
		$last = end($debugLog);
		if (!is_array($last)) {
			return null;
		}
		/** @var mixed $stage */
		$stage = $last['stage'] ?? null;

		return is_string($stage) ? $stage : null;
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

		return version_compare($newVersion, $previousVersion, '>') ? 'upgrade' : 'downgrade';
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

		return version_compare($newVersion, $previousVersion, '<') ? 'App downgraded.' : 'App updated.';
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
