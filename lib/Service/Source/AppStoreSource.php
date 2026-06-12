<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

use Exception;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * Adapter for the Nextcloud App Store as a release source. Wraps the existing
 * app-store fetch logic that previously lived inline in `InstallerService` so
 * the installer can treat App Store and GitHub origins uniformly.
 *
 * The App Store path uses the full code-signing chain — install is dispatched
 * to `SelectedReleaseInstallerService`, not the external installer.
 *
 * @psalm-api
 */
class AppStoreSource implements SourceInterface {
	private const PRIMARY_ENDPOINT = 'https://garm3.nextcloud.com/api/v1/apps.json';
	private const PLATFORM_ENDPOINT = 'https://garm3.nextcloud.com/api/v1/platform/%s/apps.json';
	private const MAX_PAGES = 20;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	public function getKind(): string {
		return SourceBinding::KIND_APPSTORE;
	}

	public function getInstallerKind(): string {
		return self::INSTALLER_SIGNED;
	}

	/**
	 * Lists App Store releases for an app, normalized newest-first; see "Fetch Available Versions".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function listVersions(string $appId, SourceBinding $binding): array {
		try {
			$payload = $this->fetchAppPayload($appId);
		} catch (Exception $error) {
			return ['versions' => [], 'error' => 'Could not fetch versions from the app store: ' . $error->getMessage()];
		}

		if ($payload === null) {
			return ['versions' => [], 'error' => 'App is not available in the Nextcloud App Store.'];
		}

		$releases = $payload['releases'] ?? [];
		if (!is_array($releases)) {
			return ['versions' => [], 'error' => 'App store returned an unexpected payload shape.'];
		}

		return ['versions' => $this->normalizeVersions($releases), 'error' => null];
	}

	/**
	 * Resolves a single App Store release (with certificate) for install; see "Install Specific Version".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$payload = $this->fetchAppPayload($appId);
		if ($payload === null || !isset($payload['releases']) || !is_array($payload['releases'])) {
			return null;
		}

		/** @var mixed $release */
		foreach ($payload['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			if (($release['version'] ?? null) === $version) {
				/** @var array<string, mixed> $resolved */
				$resolved = array_merge(
					$release,
					[
						'certificate' => $payload['certificate'] ?? null,
						'kind' => 'appstore',
					],
				);

				return $resolved;
			}
		}

		return null;
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private function fetchAppPayload(string $appId): ?array {
		$client = $this->clientService->newClient();

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$endpoint = self::PRIMARY_ENDPOINT . '?filter=' . rawurlencode($appId) . '&page=' . $page;
			try {
				$response = $client->get($endpoint);
				if ($response->getStatusCode() !== 200) {
					continue;
				}
				$body = trim((string)$response->getBody());
				if ($body === '') {
					return null;
				}
				/** @var mixed $decoded */
				$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					return null;
				}
				$appPayload = $this->extractAppPayload($decoded, $appId);
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

		$platformVersion = $this->getPlatformVersion();
		$platformEndpoint = sprintf(self::PLATFORM_ENDPOINT, rawurlencode($platformVersion));

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$endpoint = $platformEndpoint . '?page=' . $page;
			try {
				$response = $client->get($endpoint);
				if ($response->getStatusCode() !== 200) {
					continue;
				}
				$body = trim((string)$response->getBody());
				if ($body === '') {
					continue;
				}
				/** @var mixed $decoded */
				$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					continue;
				}
				$appPayload = $this->extractAppPayload($decoded, $appId);
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
	 * @param array<array-key, mixed> $payload
	 * @return array<array-key, mixed>|null
	 */
	private function extractAppPayload(array $payload, string $appId): ?array {
		$data = $this->arrayField($payload, 'data');
		if ($data !== null && array_is_list($data)) {
			$match = $this->findById($data, $appId);
			if ($match !== null) {
				return $match;
			}
		}

		if (array_is_list($payload)) {
			return $this->findById($payload, $appId);
		}

		$apps = $this->arrayField($payload, 'apps');
		if ($apps === null) {
			return null;
		}

		return $this->findById($apps, $appId);
	}

	/**
	 * Returns the named field as an array, or null when absent/non-array.
	 *
	 * @param array<array-key, mixed> $payload
	 * @return array<array-key, mixed>|null
	 */
	private function arrayField(array $payload, string $key): ?array {
		/** @var mixed $value */
		$value = $payload[$key] ?? null;

		return is_array($value) ? $value : null;
	}

	/**
	 * Finds the first list entry whose `id` matches the given app id.
	 *
	 * @param array<array-key, mixed> $entries
	 * @return array<array-key, mixed>|null
	 */
	private function findById(array $entries, string $appId): ?array {
		/** @var mixed $entry */
		foreach ($entries as $entry) {
			if (is_array($entry) && ($entry['id'] ?? null) === $appId) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function hasPossibleNextPage(array $payload, int $currentPage): bool {
		if (isset($payload['page'])) {
			$current = (int)$payload['page'];
			if ($current > 0 && $current !== $currentPage) {
				return false;
			}
		}
		if (isset($payload['pages']['next']) && is_bool($payload['pages']['next'])) {
			return $payload['pages']['next'];
		}
		if (isset($payload['pagination']['next_page'])) {
			return $payload['pagination']['next_page'] !== null;
		}
		if (isset($payload['nextPage']) && is_string($payload['nextPage'])) {
			return $payload['nextPage'] !== '';
		}
		$apps = $this->arrayField($payload, 'apps');
		if ($apps !== null) {
			return count($apps) > 0;
		}
		$data = $this->arrayField($payload, 'data');
		if ($data !== null) {
			return count($data) > 0;
		}

		return false;
	}

	private function getPlatformVersion(): string {
		$version = $this->config->getSystemValueString('version');
		$parts = explode('.', $version);
		$major = $parts[0] ?? '0';
		$minor = $parts[1] ?? '0';
		if (!ctype_digit($major) || !ctype_digit($minor)) {
			return '0.0.0';
		}

		return $major . '.' . $minor . '.0';
	}

	/**
	 * @param array<mixed> $releases
	 * @return list<array{version: string}>
	 */
	private function normalizeVersions(array $releases): array {
		$versions = [];
		/** @var mixed $release */
		foreach ($releases as $release) {
			if (is_string($release)) {
				$versions[] = $release;
				continue;
			}
			if (!is_array($release)) {
				continue;
			}
			/** @var mixed $version */
			$version = $release['version'] ?? $release['ver'] ?? $release['name'] ?? $release['tag_name'] ?? null;
			if (is_string($version) && $version !== '') {
				$versions[] = $version;
			}
		}

		$versions = array_values(array_unique($versions));
		usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

		return array_map(static fn (string $v): array => ['version' => $v], $versions);
	}
}
