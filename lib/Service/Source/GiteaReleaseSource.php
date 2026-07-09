<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Source;

use Exception;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Lists releases from a Gitea/Forgejo instance (including Codeberg) for a
 * `owner/repo` under a configurable `host`, and resolves a release into a
 * downloadable archive URL.
 *
 * Gitea's release API is intentionally close to GitHub's — same response
 * shape for tag_name, assets, browser_download_url — but rooted at
 * `/api/v1/repos/{owner}/{repo}/releases` instead of `/repos/…`. This driver
 * mirrors {@see GithubReleaseSource} but talks to any Gitea-family instance;
 * the canonical consumer is Codeberg (host = `codeberg.org`).
 *
 * PAT support is intentionally omitted in this initial version — the target
 * use case is public dev / pre-releases published to Codeberg without going
 * through the Nextcloud App Store, and public read of releases needs no auth.
 * Adding PAT support later means extending PatResolver to key on
 * `host/owner/repo` (not `owner/repo` as it does today) so tokens for
 * different Gitea instances don't collide.
 *
 * Source binding shape:
 *   {
 *     "kind": "gitea-release",
 *     "host": "codeberg.org",
 *     "owner": "Conduction",
 *     "repo": "opencatalogi",
 *     "assetPattern": "*.tar.gz"
 *   }
 */
class GiteaReleaseSource implements SourceInterface {
	private const USER_AGENT = 'Nextcloud-AppVersions';

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getKind(): string {
		return SourceBinding::KIND_GITEA_RELEASE;
	}

	public function getInstallerKind(): string {
		return self::INSTALLER_EXTERNAL;
	}

	public function listVersions(string $appId, SourceBinding $binding): array {
		$hostOwnerRepo = $binding->getHostOwnerRepo();
		if ($hostOwnerRepo === null) {
			return ['versions' => [], 'error' => 'Source binding is not a gitea-release binding.'];
		}

		$result = $this->fetchReleases($hostOwnerRepo);
		if (!$result['ok']) {
			return ['versions' => [], 'error' => $result['error']];
		}

		$versions = [];
		foreach ($result['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			// Defensive: some Gitea/Forgejo versions surface `draft: true`
			// entries to public API consumers where GitHub's API would hide
			// them server-side. Filtering here keeps parity with what an
			// admin sees in the release-page UI.
			if ($release['draft'] ?? false) {
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
		$hostOwnerRepo = $binding->getHostOwnerRepo();
		if ($hostOwnerRepo === null) {
			return null;
		}

		$result = $this->fetchReleases($hostOwnerRepo);
		if (!$result['ok']) {
			return null;
		}

		$assetPattern = $binding->getAssetPattern();
		foreach ($result['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			// Same defensive draft filter as listVersions() — resolveRelease
			// should never surface a draft asset even if Gitea leaks it.
			if ($release['draft'] ?? false) {
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
	 * @param array{host: string, ownerRepo: string} $hostOwnerRepo
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function fetchReleases(array $hostOwnerRepo): array {
		$host = $hostOwnerRepo['host'];
		$ownerRepo = $hostOwnerRepo['ownerRepo'];

		// Gitea/Forgejo cap `limit` at 50 for /releases (Codeberg confirmed)
		// where GitHub allows up to 100 via `per_page`. Neither driver
		// paginates today, so a repo with >50 releases will truncate here.
		// For Nextcloud apps that ship <20 releases (the norm) this is not
		// an issue; if a real deployment needs deeper history the fix is a
		// `page=N` follow-up loop, not raising `limit` above 50.
		$endpoint = sprintf('https://%s/api/v1/repos/%s/releases?limit=50', $host, $ownerRepo);

		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => self::USER_AGENT,
				],
				'timeout' => 30,
				// IClient throws on 4xx by default; we want to inspect the
				// status code ourselves to produce useful errors.
				'http_errors' => false,
			]);
		} catch (Exception $error) {
			$this->logger->warning('GiteaReleaseSource: fetch failed', [
				'endpoint' => $endpoint,
				'message' => $error->getMessage(),
			]);

			return ['ok' => false, 'error' => $this->humanizeError($error->getMessage(), $host)];
		}

		$status = $response->getStatusCode();
		if ($status === 404) {
			return ['ok' => false, 'error' => sprintf('Gitea repository not found on %s.', $host)];
		}
		if ($status === 401) {
			return ['ok' => false, 'error' => sprintf('Authentication failed against %s.', $host)];
		}
		if ($status === 403) {
			return ['ok' => false, 'error' => sprintf('Access forbidden on %s — the repository may be private.', $host)];
		}
		if ($status !== 200) {
			return ['ok' => false, 'error' => sprintf('%s API returned HTTP %d.', $host, $status)];
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['ok' => false, 'error' => sprintf('%s API returned malformed JSON.', $host)];
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			return ['ok' => false, 'error' => sprintf('%s API returned an unexpected payload shape.', $host)];
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
			'kind' => 'gitea-release',
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

	private function humanizeError(string $raw, string $host): string {
		if (stripos($raw, 'could not resolve host') !== false) {
			return sprintf('Could not reach %s — check network connectivity.', $host);
		}
		if (stripos($raw, 'connection refused') !== false) {
			return sprintf('Connection refused by %s.', $host);
		}

		return sprintf('%s API request failed.', $host);
	}
}
