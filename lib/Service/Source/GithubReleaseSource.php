<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

use Exception;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatResolver;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Lists GitHub releases for an `owner/repo` and resolves a release into a
 * downloadable archive URL. Falls back to unauthenticated requests when no
 * applicable PAT exists; uses a PAT (resolved via `PatResolver`) when one
 * matches the binding's `owner/repo` and is visible to the current admin.
 *
 * Source binding shape:
 *   {
 *     "kind": "github-release",
 *     "owner": "ConductionNL",
 *     "repo": "openregister",
 *     "assetPattern": "*.tar.gz"
 *   }
 */
class GithubReleaseSource implements SourceInterface {
	private const API_BASE = 'https://api.github.com';
	private const USER_AGENT = 'Nextcloud-AppVersions';

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private PatResolver $patResolver,
		private PatManager $patManager,
		private IUserSession $userSession,
	) {
	}

	public function getKind(): string {
		return SourceBinding::KIND_GITHUB_RELEASE;
	}

	public function getInstallerKind(): string {
		return self::INSTALLER_EXTERNAL;
	}

	public function listVersions(string $appId, SourceBinding $binding): array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return ['versions' => [], 'error' => 'Source binding is not a github-release binding.'];
		}

		$result = $this->fetchReleases($ownerRepo);
		if (!$result['ok']) {
			return ['versions' => [], 'error' => $result['error']];
		}

		$versions = [];
		foreach ($result['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			$tag = $release['tag_name'] ?? null;
			if (!is_string($tag) || $tag === '') {
				continue;
			}
			$versions[] = ['version' => $this->normalizeVersion($tag)];
		}

		return ['versions' => $this->dedupeAndSort($versions), 'error' => null];
	}

	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}

		$result = $this->fetchReleases($ownerRepo);
		if (!$result['ok']) {
			return null;
		}

		$assetPattern = $binding->getAssetPattern();
		foreach ($result['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			$tag = $release['tag_name'] ?? null;
			if (!is_string($tag)) {
				continue;
			}
			if ($this->normalizeVersion($tag) !== $version && $tag !== $version) {
				continue;
			}

			return $this->buildReleasePayload($release, $assetPattern);
		}

		return null;
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function fetchReleases(string $ownerRepo): array {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$pat = $uid !== null ? $this->patResolver->findFor($ownerRepo, $uid) : null;

		$endpoint = sprintf('%s/repos/%s/releases?per_page=100', self::API_BASE, $ownerRepo);

		if ($pat === null) {
			return $this->performFetch($endpoint, null);
		}

		/** @var array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string} */
		return $this->patManager->useToken($pat, fn (string $token): array => $this->performFetch($endpoint, $token));
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function performFetch(string $endpoint, ?string $token): array {
		$headers = [
			'Accept' => 'application/vnd.github+json',
			'User-Agent' => self::USER_AGENT,
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ($token !== null) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => $headers,
				'timeout' => 30,
				// IClient throws on 4xx by default; we want to inspect the
				// status code ourselves to produce useful errors.
				'http_errors' => false,
			]);
		} catch (Exception $error) {
			$this->logger->warning('GithubReleaseSource: fetch failed', [
				'endpoint' => $endpoint,
				'message' => $error->getMessage(),
			]);

			return ['ok' => false, 'error' => $this->humanizeError($error->getMessage())];
		}

		$status = $response->getStatusCode();
		if ($status === 404) {
			return ['ok' => false, 'error' => 'GitHub repository not found.'];
		}
		if ($status === 401) {
			return ['ok' => false, 'error' => 'GitHub authentication failed — the configured PAT may be revoked or expired.'];
		}
		if ($status === 403) {
			return ['ok' => false, 'error' => 'GitHub rate limit exceeded — try again later, or configure a PAT.'];
		}
		if ($status !== 200) {
			return ['ok' => false, 'error' => sprintf('GitHub API returned HTTP %d.', $status)];
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['ok' => false, 'error' => 'GitHub API returned malformed JSON.'];
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			return ['ok' => false, 'error' => 'GitHub API returned an unexpected payload shape.'];
		}

		return ['ok' => true, 'releases' => $decoded];
	}

	/**
	 * @param array<string, mixed> $release
	 * @return array<string, mixed>|null
	 */
	private function buildReleasePayload(array $release, string $assetPattern): ?array {
		$assets = $release['assets'] ?? [];
		if (!is_array($assets) || !array_is_list($assets)) {
			return null;
		}

		$matchingAssets = [];
		$shaUrl = null;
		foreach ($assets as $asset) {
			if (!is_array($asset)) {
				continue;
			}
			$name = $asset['name'] ?? '';
			$url = $asset['browser_download_url'] ?? '';
			if (!is_string($name) || !is_string($url) || $name === '' || $url === '') {
				continue;
			}
			if (fnmatch($assetPattern, $name, FNM_NOESCAPE)) {
				$matchingAssets[] = ['name' => $name, 'url' => $url];
			}
			// Capture .sha256 sibling if present anywhere in the release.
			if (str_ends_with($name, '.sha256')) {
				$shaUrl = $url;
			}
		}

		if (count($matchingAssets) === 0) {
			return [
				'error' => sprintf('No release asset matches pattern "%s".', $assetPattern),
			];
		}

		if (count($matchingAssets) > 1) {
			$names = array_map(static fn (array $a): string => $a['name'], $matchingAssets);

			return [
				'error' => sprintf(
					'Multiple matching assets for pattern "%s" (%s) — set explicit assetPattern.',
					$assetPattern,
					implode(', ', $names)
				),
			];
		}

		$tag = $release['tag_name'] ?? '';

		return [
			'kind' => 'github-release',
			'download' => $matchingAssets[0]['url'],
			'assetName' => $matchingAssets[0]['name'],
			'sha256Url' => $shaUrl,
			'version' => is_string($tag) ? $this->normalizeVersion($tag) : '',
			'tagName' => is_string($tag) ? $tag : '',
		];
	}

	private function normalizeVersion(string $tag): string {
		if (str_starts_with($tag, 'v') || str_starts_with($tag, 'V')) {
			return substr($tag, 1);
		}

		return $tag;
	}

	/**
	 * @param list<array{version: string}> $versions
	 * @return list<array{version: string}>
	 */
	private function dedupeAndSort(array $versions): array {
		$seen = [];
		$unique = [];
		foreach ($versions as $entry) {
			if (isset($seen[$entry['version']])) {
				continue;
			}
			$seen[$entry['version']] = true;
			$unique[] = $entry;
		}

		usort($unique, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

		return $unique;
	}

	private function humanizeError(string $raw): string {
		if (stripos($raw, 'rate limit') !== false) {
			return 'GitHub rate limit exceeded — try again later, or configure a PAT.';
		}
		if (stripos($raw, 'could not resolve host') !== false) {
			return 'Could not reach api.github.com — check network connectivity.';
		}

		return 'GitHub API request failed.';
	}
}
