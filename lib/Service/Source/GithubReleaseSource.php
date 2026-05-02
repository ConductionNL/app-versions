<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Source;

use Exception;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Lists GitHub releases for an `owner/repo` and resolves a release into a
 * downloadable archive URL. Public repos only — PAT-authenticated requests
 * are added in proposal 2.
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

		$endpoint = sprintf('%s/repos/%s/releases?per_page=100', self::API_BASE, $ownerRepo);

		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => [
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => self::USER_AGENT,
					'X-GitHub-Api-Version' => '2022-11-28',
				],
				'timeout' => 30,
			]);
		} catch (Exception $error) {
			$this->logger->warning('GithubReleaseSource: list failed', [
				'ownerRepo' => $ownerRepo,
				'message' => $error->getMessage(),
			]);

			return ['versions' => [], 'error' => $this->humanizeError($error->getMessage())];
		}

		$status = $response->getStatusCode();
		if ($status === 404) {
			return ['versions' => [], 'error' => 'GitHub repository not found.'];
		}
		if ($status === 403) {
			return ['versions' => [], 'error' => 'GitHub rate limit exceeded — try again later, or configure a PAT.'];
		}
		if ($status !== 200) {
			return ['versions' => [], 'error' => sprintf('GitHub API returned HTTP %d.', $status)];
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['versions' => [], 'error' => 'GitHub API returned malformed JSON.'];
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			return ['versions' => [], 'error' => 'GitHub API returned an unexpected payload shape.'];
		}

		$versions = [];
		foreach ($decoded as $release) {
			if (!is_array($release)) {
				continue;
			}
			$tag = $release['tag_name'] ?? null;
			if (!is_string($tag) || $tag === '') {
				continue;
			}
			$versions[] = ['version' => $this->normalizeVersion($tag)];
		}

		$versions = $this->dedupeAndSort($versions);

		return ['versions' => $versions, 'error' => null];
	}

	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}

		$assetPattern = $binding->getAssetPattern();
		$endpoint = sprintf('%s/repos/%s/releases?per_page=100', self::API_BASE, $ownerRepo);

		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => [
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => self::USER_AGENT,
					'X-GitHub-Api-Version' => '2022-11-28',
				],
				'timeout' => 30,
			]);
		} catch (Exception $error) {
			$this->logger->warning('GithubReleaseSource: resolve failed', [
				'ownerRepo' => $ownerRepo,
				'message' => $error->getMessage(),
			]);

			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			return null;
		}

		foreach ($decoded as $release) {
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
