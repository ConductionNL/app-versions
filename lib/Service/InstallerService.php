<?php

/**
 * AppVersions Installer Service
 *
 * Discovers installed apps, resolves available versions from the Nextcloud app
 * store (scoped to the current update channel) and orchestrates the download
 * + install of a selected release.
 *
 * @category Service
 * @package  OCA\AppVersions\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppVersions\Service;

use Exception;
use OCA\AppVersions\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

class InstallerService {
	/**
	 * Stores dependencies required for app discovery, metadata fetching and install flow.
	 *
	 * @param IAppManager $appManager
	 * @param IConfig $config
	 * @param IClientService $clientService
	 * @param SelectedReleaseInstallerService $releaseInstaller
	 */
	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private IClientService $clientService,
		private SelectedReleaseInstallerService $releaseInstaller,
	) {
	}

	/**
	 * Returns installed apps enriched with metadata for frontend cards.
	 *
	 * @return array<int, array{id:string,label:string,description:string,summary:string,preview:string,isCore:bool}>
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-list-installed-apps
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

		return array_map(
			static function(string $appId) use ($appList, $alwaysEnabledApps): array {
				$app = $appList[$appId] ?? [];
				$name = isset($app['name']) && is_string($app['name']) && trim($app['name']) !== ''
					? trim($app['name'])
					: $appId;
				$description = isset($app['description']) && is_string($app['description'])
					? trim($app['description'])
					: '';
				$summary = isset($app['summary']) && is_string($app['summary'])
					? trim($app['summary'])
					: '';
				$preview = isset($app['preview']) && is_string($app['preview'])
					? trim($app['preview'])
					: '';

				return [
					'id' => $appId,
					'label' => $name,
					'description' => $description,
					'summary' => $summary,
					'preview' => $preview,
					'isCore' => in_array($appId, $alwaysEnabledApps, true),
				];
			},
			$installedApps
		);
	}

	/**
	 * Returns installed version and available versions for an app id.
	 *
	 * @param string $appId
	 * @return array{installedVersion: ?string, availableVersions: array<int, array{version:string}>, versions: array<int, array{version:string}>, source: string, statusCode: int, hasError: bool}
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-fetch-available-versions
	 */
	public function getAppVersions(string $appId): array {
		$appId = trim($appId);
		if ($appId === '') {
			return [
				'installedVersion' => null,
				'availableVersions' => [],
				'versions' => [],
				'source' => 'none',
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'hasError' => true,
			];
		}
		if ($this->isSelfManagedApp($appId) || $this->isCoreProtectedApp($appId)) {
			return [
				'installedVersion' => null,
				'availableVersions' => [],
				'versions' => [],
				'source' => 'none',
				'error' => $this->isCoreProtectedApp($appId)
					? 'This core app cannot be managed from App Versions.'
					: 'This app cannot be managed from App Versions.',
				'statusCode' => Http::STATUS_FORBIDDEN,
				'hasError' => true,
			];
		}

		$availableVersions = [];
		$source = 'none';

		try {
			[$availableVersions, $source] = $this->tryAppStoreVersions($appId);
		} catch (Exception) {
			$availableVersions = [];
			$source = 'none';
		}

		$installedVersion = null;
		try {
			$installed = $this->appManager->getAppVersion($appId);
			if (is_string($installed) && $installed !== '') {
				$installedVersion = $installed;
			}
		} catch (Exception) {
			$installedVersion = null;
		}

		if (!empty($availableVersions)) {
			return [
				'installedVersion' => $installedVersion,
				'availableVersions' => $availableVersions,
				'versions' => $availableVersions,
				'source' => $source,
				'statusCode' => Http::STATUS_OK,
				'hasError' => false,
			];
		}

		return [
			'installedVersion' => $installedVersion,
			'error' => 'No available versions found.',
			'source' => $installedVersion !== null ? 'installed' : 'none',
			'availableVersions' => [],
			'versions' => [],
			'statusCode' => Http::STATUS_OK,
			'hasError' => true,
		];
	}

	/**
	 * Installs the selected release version.
	 *
	 * @param string $appId
	 * @param string $targetVersion
	 * @param bool $includeDebug
	 * @return array{statusCode:int,payload:array}
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
	 */
	public function installAppVersion(string $appId, string $targetVersion, bool $includeDebug): array {
		$appId = trim($appId);
		$targetVersion = trim($targetVersion);
		if ($appId === '' || $targetVersion === '') {
			return [
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'payload' => [
					'message' => 'Missing app id or version.',
				],
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
			$this->appManager->clearAppsCache();
			$installedVersion = $this->appManager->getAppVersion($appId, false);
		} catch (Exception) {
			$installedVersion = '';
		}

		if ($installedVersion !== '' && version_compare($targetVersion, $installedVersion, '=') === 0) {
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion,
				'toVersion' => $targetVersion,
				'message' => 'App already has this version installed.',
			];
			if ($includeDebug) {
				$payload['debug'] = [];
			}

			return [
				'statusCode' => Http::STATUS_OK,
				'payload' => $payload,
			];
		}

		$release = $this->findReleaseByVersion($appId, $targetVersion);
		if ($release === null) {
			$payload = [
				'appId' => $appId,
				'toVersion' => $targetVersion,
				'message' => 'Requested version not found in app store metadata.',
			];
			if ($includeDebug) {
				$payload['debug'] = [];
			}

			return [
				'statusCode' => Http::STATUS_NOT_FOUND,
				'payload' => $payload,
			];
		}

		$maintenanceWasSet = false;
		$debug = [];
		$installStatus = 'not-started';
		$dryRun = $includeDebug;

		try {
			if (!$this->config->getSystemValueBool('maintenance', false)) {
				$maintenanceWasSet = true;
				$this->config->setSystemValue('maintenance', true);
			}

			$result = $this->releaseInstaller->installFromSelectedRelease($appId, $release, $dryRun);
			$debug = $result['debug'] ?? [];
			$installStatus = $result['status'] ?? 'unknown';
			if (!$dryRun) {
				$this->appManager->clearAppsCache();
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
				$this->appManager->clearAppsCache();
				$appVersion = $this->appManager->getAppVersion($appId, false);
			}

			$configuredVersion = (string) $this->config->getAppValue($appId, 'installed_version', $appVersion ?? '');
			if ($configuredVersion !== '') {
				$appVersion = $configuredVersion;
			}

			if ($appVersion !== $targetVersion) {
				$payload = [
					'appId' => $appId,
					'fromVersion' => $installedVersion === '' ? null : $installedVersion,
					'toVersion' => $targetVersion,
					'installedVersion' => $appVersion,
					'updateType' => ($installedVersion === '' ? 'install' : ($appVersion !== $installedVersion
						? (version_compare($appVersion, $installedVersion, '>') === 1 ? 'upgrade' : 'downgrade')
						: 'none')),
					'message' => 'Target version was requested but installed version did not change.',
					'dryRun' => $dryRun,
					'installStatus' => $installStatus,
				];
				if ($includeDebug) {
					$payload['debug'] = $debug;
				}

				return [
					'statusCode' => Http::STATUS_OK,
					'payload' => $payload,
				];
			}

			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $dryRun ? $targetVersion : $appVersion,
				'installedVersion' => $appVersion,
				'updateType' => $dryRun ? 'dry-run' : ($installedVersion === '' ? 'install' : ($appVersion !== $installedVersion
					? (version_compare($appVersion, $installedVersion, '>') === 1 ? 'upgrade' : 'downgrade')
					: 'none')),
				'message' => $dryRun
					? 'Dry run mode: no changes were applied.'
					: ($installedVersion === '' ? 'App installed.' : ($appVersion === $installedVersion
						? 'App already at selected version.'
						: (version_compare($appVersion, $installedVersion, '<') === -1 ? 'App downgraded.' : 'App updated.'))),
				'dryRun' => $dryRun,
				'installStatus' => $installStatus,
			];
			if ($includeDebug) {
				$payload['debug'] = $debug;
			}

			return [
				'statusCode' => Http::STATUS_OK,
				'payload' => $payload,
			];
		} catch (Exception $e) {
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $e->getMessage(),
				'installStatus' => $installStatus,
			];
			if ($includeDebug) {
				$payload['debug'] = $this->releaseInstaller->getDebugLog();
			}

			return [
				'statusCode' => Http::STATUS_INTERNAL_SERVER_ERROR,
				'payload' => $payload,
			];
		} finally {
			if ($maintenanceWasSet) {
				$this->config->setSystemValue('maintenance', false);
			}
		}
	}

	private function isSelfManagedApp(string $appId): bool {
		return trim($appId) === Application::APP_ID;
	}

	private function isCoreProtectedApp(string $appId): bool {
		return in_array(trim($appId), $this->appManager->getAlwaysEnabledApps(), true);
	}

	/**
	 * Locates the release payload for the requested version.
	 *
	 * @param string $appId
	 * @param string $version
	 * @return array<string, mixed>|null
	 */
	private function findReleaseByVersion(string $appId, string $version): ?array {
		$appPayload = $this->getAppPayloadFromStore($appId);
		if (!is_array($appPayload) || !isset($appPayload['releases']) || !is_array($appPayload['releases'])) {
			return null;
		}

		foreach ($appPayload['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			if (($release['version'] ?? null) === $version) {
				$release['certificate'] = $appPayload['certificate'] ?? null;
				return $release;
			}
		}

		return null;
	}

	/**
	 * Fetches full app payload from app store endpoints.
	 *
	 * @param string $appId
	 * @return array<string, mixed>|null
	 */
	private function getAppPayloadFromStore(string $appId): ?array {
		$endpoints = ['https://garm3.nextcloud.com/api/v1/apps.json'];
		$maxPages = 20;
		$client = $this->clientService->newClient();

		foreach ($endpoints as $endpointBase) {
			for ($page = 1; $page <= $maxPages; $page++) {
				$endpoint = $endpointBase . '?filter=' . rawurlencode($appId) . '&page=' . $page;
				try {
					$response = $client->get($endpoint);
					$status = $response->getStatusCode();
					if ($status !== Http::STATUS_OK) {
						continue;
					}

					$body = trim((string) $response->getBody());
					if ($body === '') {
						return null;
					}

					$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
					if (!is_array($decoded)) {
						return null;
					}

					$appPayload = $this->extractAppPayloadFromPlatform($decoded, $appId);
					if (is_array($appPayload)) {
						return $appPayload;
					}

					if (!$this->hasPossibleNextPage($decoded, $page)) {
						break;
					}
				} catch (Exception) {
					continue;
				}
			}
		}

		$platformVersion = $this->getPlatformVersion();
		$platformEndpoint = 'https://garm3.nextcloud.com/api/v1/platform/' . rawurlencode($platformVersion) . '/apps.json';

		for ($page = 1; $page <= $maxPages; $page++) {
			$endpoint = $platformEndpoint . '?page=' . $page;
			try {
				$response = $client->get($endpoint);
				$status = $response->getStatusCode();
				if ($status !== Http::STATUS_OK) {
					continue;
				}

				$body = trim((string) $response->getBody());
				if ($body === '') {
					continue;
				}

				$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					continue;
				}

				$appPayload = $this->extractAppPayloadFromPlatform($decoded, $appId);
				if (is_array($appPayload)) {
					return $appPayload;
				}

				if (!$this->hasPossibleNextPage($decoded, $page)) {
					break;
				}
			} catch (Exception) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Fetches available release versions from app-store payloads.
	 *
	 * @param string $appId
	 * @return array{0: array<int, array{version: string}>, 1: string}
	 */
	private function tryAppStoreVersions(string $appId): array {
		$endpoints = ['https://garm3.nextcloud.com/api/v1/apps.json'];
		$maxPages = 20;
		$client = $this->clientService->newClient();

		foreach ($endpoints as $endpointBase) {
			for ($page = 1; $page <= $maxPages; $page++) {
				$endpoint = $endpointBase . '?filter=' . rawurlencode($appId) . '&page=' . $page;
				try {
					$response = $client->get($endpoint);
					$status = $response->getStatusCode();
					$body = (string) $response->getBody();

					if ($status !== Http::STATUS_OK) {
						continue;
					}

					if (trim($body) === '') {
						break;
					}

					$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
					if (!is_array($decoded)) {
						break;
					}

						$versions = $this->extractVersionsFromFilteredPayload($decoded, $appId);

					if ($this->hasAppInPayload($decoded, $appId)) {
						if (!empty($versions)) {
							return [$versions, 'app-store'];
						}

						return [[], 'app-store'];
					}

					if (!$this->hasPossibleNextPage($decoded, $page)) {
						break;
					}
				} catch (Exception) {
					break;
				}
			}
		}

		$platformVersion = $this->getPlatformVersion();
		$platformEndpoint = 'https://garm3.nextcloud.com/api/v1/platform/' . rawurlencode($platformVersion) . '/apps.json';

		for ($page = 1; $page <= $maxPages; $page++) {
			$endpoint = $platformEndpoint . '?page=' . $page;
			try {
				$response = $client->get($endpoint);
				$status = $response->getStatusCode();
				$body = (string) $response->getBody();

				if ($status !== Http::STATUS_OK) {
					continue;
				}

				if (trim($body) === '') {
					continue;
				}

				$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					continue;
				}

				if (str_contains($endpoint, '/platform/')) {
						$versions = $this->extractVersionsFromPlatformPayload($decoded, $appId);
				} else {
					$versions = $this->normalizeVersions($decoded);
				}

				if (!empty($versions)) {
					return [$versions, 'app-store'];
				}
			} catch (Exception) {
				break;
			}
		}

		return [[], 'app-store'];
	}

	/**
	 * Evaluates whether a payload has a next page to continue polling.
	 *
	 * @param array<mixed> $payload
	 * @param int $currentPage
	 * @return bool
	 */
	private function hasPossibleNextPage(array $payload, int $currentPage): bool {
		if (!is_array($payload)) {
			return false;
		}

		if (isset($payload['page'])) {
			$current = (int) $payload['page'];
			if ($current > 0 && $current !== $currentPage) {
				return false;
			}
		}

		if (isset($payload['pages'], $payload['pages']['next']) && is_bool($payload['pages']['next'])) {
			return $payload['pages']['next'];
		}

		if (isset($payload['pagination']['next_page'], $payload['pagination']['next_page'])) {
			return $payload['pagination']['next_page'] !== null;
		}

		if (isset($payload['nextPage']) && is_string($payload['nextPage'])) {
			return $payload['nextPage'] !== '';
		}

		if (is_array($payload['apps'] ?? null)) {
			return count($payload['apps']) > 0;
		}

		if (is_array($payload['data'] ?? null)) {
			return count($payload['data']) > 0;
		}

		return false;
	}

	/**
	 * Checks whether the given app id appears in a store payload.
	 *
	 * @param array<mixed> $payload
	 * @param string $appId
	 * @return bool
	 */
	private function hasAppInPayload(array $payload, string $appId): bool {
		return $this->extractAppPayloadFromPlatform($payload, $appId) !== null;
	}

	/**
	 * Returns major.minor.0 server version for platform-specific requests.
	 *
	 * @return string
	 */
	private function getPlatformVersion(): string {
		$version = $this->config->getSystemValueString('version');
		$parts = explode('.', $version);
		$major = $parts[0] ?? '0';
		$minor = $parts[1] ?? '0';

		if (!ctype_digit((string) $major) || !ctype_digit((string) $minor)) {
			return '0.0.0';
		}

		return $major . '.' . $minor . '.0';
	}

	/**
	 * Extracts release versions from platform endpoint payload.
	 *
	 * @param array<mixed> $payload
	 * @param string $appId
	 * @return array<int, array{version: string}>
	 */
	private function extractVersionsFromPlatformPayload(array $payload, string $appId): array {
		$appPayload = $this->extractAppPayloadFromPlatform($payload, $appId);
		if ($appPayload === null || !isset($appPayload['releases']) || !is_array($appPayload['releases'])) {
			return [];
		}

		return $this->normalizeVersions($appPayload['releases']);
	}

	/**
	 * Extracts release versions from filtered endpoint payload.
	 *
	 * @param array<mixed> $payload
	 * @param string $appId
	 * @return array<int, array{version: string}>
	 */
	private function extractVersionsFromFilteredPayload(array $payload, string $appId): array {
		$appPayload = $this->extractAppPayloadFromPlatform($payload, $appId);
		if (is_array($appPayload) && isset($appPayload['releases']) && is_array($appPayload['releases'])) {
			return $this->normalizeVersions($appPayload['releases']);
		}

		return [];
	}

	/**
	 * Extracts app payload by id from known payload shapes.
	 *
	 * @param array<mixed> $payload
	 * @param string $appId
	 * @return array<string, mixed>|null
	 */
	private function extractAppPayloadFromPlatform(array $payload, string $appId): ?array {
		if (is_array($payload['data'] ?? null) && array_is_list($payload['data'])) {
			foreach ($payload['data'] as $entry) {
				if (is_array($entry) && ($entry['id'] ?? null) === $appId) {
					return $entry;
				}
			}
		}

		if (array_is_list($payload)) {
			foreach ($payload as $entry) {
				if (is_array($entry) && ($entry['id'] ?? null) === $appId) {
					return $entry;
				}
			}

			return null;
		}

		if (!is_array($payload['apps'] ?? null)) {
			return null;
		}

		foreach ($payload['apps'] as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			if (($entry['id'] ?? null) === $appId) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Normalizes version payloads into unique version labels.
	 *
	 * @param array<mixed> $payload
	 * @return array<int, array{version: string}>
	 */
	private function normalizeVersions(array $payload): array {
		$releasePayload = [];
		if (array_is_list($payload)) {
			$releasePayload = $payload;
		} elseif (array_key_exists('data', $payload) && is_array($payload['data'])) {
			$releasePayload = $this->extractVersionPayload($payload['data']);
		} else {
			$releasePayload = $this->extractVersionPayload($payload);
		}

		$normalizedVersions = [];
		foreach ($releasePayload as $release) {
			if (is_string($release)) {
				$normalizedVersions[] = ['version' => $release];
				continue;
			}

			if (!is_array($release)) {
				continue;
			}

			$version = $release['version'] ?? $release['ver'] ?? $release['name'] ?? $release['tag_name'] ?? null;
			if (is_string($version) && $version !== '') {
				$normalizedVersions[] = ['version' => $version];
			}
		}

		$flattened = [];
		foreach ($normalizedVersions as $entry) {
			$flattened[] = $entry['version'];
		}

		$flattened = array_values(array_unique($flattened));
		usort($flattened, static function(string $a, string $b): int {
			return version_compare($b, $a);
		});

		return array_map(static fn(string $version): array => ['version' => $version], $flattened);
	}

	/**
	 * Returns release list from nested payload structures.
	 *
	 * @param array<mixed> $payload
	 * @return array<int, mixed>
	 */
	private function extractVersionPayload(array $payload): array {
		if (is_array($payload['releases'] ?? null)) {
			return $payload['releases'];
		}

		if (is_array($payload['apps'] ?? null) && is_array($payload['apps'][0] ?? null)) {
			$firstApp = $payload['apps'][0];
			if (is_array($firstApp['releases'] ?? null)) {
				return $firstApp['releases'];
			}
		}

		if (is_array($payload['versions'] ?? null)) {
			return $payload['versions'];
		}

		return [];
	}
}
