<?php

declare(strict_types=1);

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

	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$payload = $this->fetchAppPayload($appId);
		if (!is_array($payload) || !isset($payload['releases']) || !is_array($payload['releases'])) {
			return null;
		}

		foreach ($payload['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			if (($release['version'] ?? null) === $version) {
				$release['certificate'] = $payload['certificate'] ?? null;
				$release['kind'] = 'appstore';

				return $release;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>|null
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
	 * @param array<mixed> $payload
	 * @return array<string, mixed>|null
	 */
	private function extractAppPayload(array $payload, string $appId): ?array {
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
			if (is_array($entry) && ($entry['id'] ?? null) === $appId) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * @param array<mixed> $payload
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
		if (is_array($payload['apps'] ?? null)) {
			return count($payload['apps']) > 0;
		}
		if (is_array($payload['data'] ?? null)) {
			return count($payload['data']) > 0;
		}

		return false;
	}

	private function getPlatformVersion(): string {
		$version = $this->config->getSystemValueString('version');
		$parts = explode('.', $version);
		$major = $parts[0] ?? '0';
		$minor = $parts[1] ?? '0';
		if (!ctype_digit((string)$major) || !ctype_digit((string)$minor)) {
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
		foreach ($releases as $release) {
			if (is_string($release)) {
				$versions[] = $release;
				continue;
			}
			if (!is_array($release)) {
				continue;
			}
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
