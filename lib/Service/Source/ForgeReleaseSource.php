<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\AppVersions\Service\Source;

use Exception;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatResolver;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Lists releases for an `owner/repo` on a git forge (GitHub, Codeberg/Forgejo)
 * and resolves a release into a downloadable archive URL. The forge to talk to
 * is read from the binding's `forge` field via {@see ForgeRegistry}; the only
 * per-forge differences are the API base URL and the auth-header scheme, both
 * carried by {@see Forge}. Release JSON is identical across forges.
 *
 * Falls back to unauthenticated requests when no applicable PAT exists; uses a
 * PAT (resolved via `PatResolver`, scoped to the binding's forge) when one
 * matches and is visible to the current admin.
 *
 * @psalm-api
 */
class ForgeReleaseSource implements SourceInterface {
	private const USER_AGENT = 'Nextcloud-AppVersions';

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private PatResolver $patResolver,
		private PatManager $patManager,
		private IUserSession $userSession,
		private ForgeRegistry $forgeRegistry,
	) {
	}

	public function getKind(): string {
		return SourceBinding::KIND_GITHUB_RELEASE;
	}

	public function getInstallerKind(): string {
		return self::INSTALLER_EXTERNAL;
	}

	/**
	 * Lists release tags (PAT-authenticated when matched), deduped newest-first; see "GitHub releases as a source".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function listVersions(string $appId, SourceBinding $binding): array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return ['versions' => [], 'error' => 'Source binding is not a forge-release binding.'];
		}
		$forge = $this->forgeRegistry->get($binding->getForge());

		$result = $this->fetchReleases($forge, $ownerRepo);
		if ($result['ok'] === false) {
			$error = $result['error'] ?? $this->forgeName($forge) . ' API request failed.';

			return ['versions' => [], 'error' => $error];
		}

		$releases = $result['releases'] ?? [];

		$versions = [];
		/** @var mixed $release */
		foreach ($releases as $release) {
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

	/**
	 * Resolves a release into a download payload, enforcing unambiguous asset selection; see "External install integrity checks".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}
		$forge = $this->forgeRegistry->get($binding->getForge());

		$result = $this->fetchReleases($forge, $ownerRepo);
		if ($result['ok'] === false) {
			return null;
		}

		$releases = $result['releases'] ?? [];

		$assetPattern = $binding->getAssetPattern();
		/** @var mixed $release */
		foreach ($releases as $release) {
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
	private function fetchReleases(Forge $forge, string $ownerRepo): array {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$pat = $uid !== null ? $this->patResolver->findFor($forge->id, $ownerRepo, $uid) : null;

		$endpoint = $forge->releasesEndpoint($ownerRepo);

		if ($pat === null) {
			return $this->performFetch($forge, $endpoint, null);
		}

		/** @var array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string} */
		return $this->patManager->useToken($pat, fn (string $token): array => $this->performFetch($forge, $endpoint, $token));
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function performFetch(Forge $forge, string $endpoint, ?string $token): array {
		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => self::USER_AGENT,
		];
		// GitHub-specific content negotiation; harmless to omit on Forgejo.
		if ($forge->id === ForgeRegistry::FORGE_GITHUB) {
			$headers['Accept'] = 'application/vnd.github+json';
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}
		if ($token !== null) {
			$headers['Authorization'] = $forge->authHeaderValue($token);
		}

		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => $headers,
				'timeout' => 30,
				// IClient throws on 4xx by default; we want to inspect the
				// status code ourselves to produce useful errors.
				'http_errors' => false,
				// SSRF defence-in-depth: only public forge hosts are configured.
				'nextcloud' => ['allow_local_address' => false],
			]);
		} catch (Exception $error) {
			$this->logger->warning('ForgeReleaseSource: fetch failed', [
				'forge' => $forge->id,
				'endpoint' => $endpoint,
				'message' => $error->getMessage(),
			]);

			return ['ok' => false, 'error' => $this->humanizeError($forge, $error->getMessage())];
		}

		$status = $response->getStatusCode();
		$name = $this->forgeName($forge);
		if ($status === 404) {
			return ['ok' => false, 'error' => $name . ' repository not found.'];
		}
		if ($status === 401) {
			return ['ok' => false, 'error' => $name . ' authentication failed — the configured PAT may be revoked or expired.'];
		}
		if ($status === 403) {
			return ['ok' => false, 'error' => $name . ' rate limit exceeded — try again later, or configure a PAT.'];
		}
		if ($status !== 200) {
			return ['ok' => false, 'error' => sprintf('%s API returned HTTP %d.', $name, $status)];
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['ok' => false, 'error' => $name . ' API returned malformed JSON.'];
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			return ['ok' => false, 'error' => $name . ' API returned an unexpected payload shape.'];
		}

		return ['ok' => true, 'releases' => $decoded];
	}

	/**
	 * @param array<array-key, mixed> $release
	 * @return array<string, mixed>|null
	 */
	private function buildReleasePayload(array $release, string $assetPattern): ?array {
		/** @var mixed $assets */
		$assets = $release['assets'] ?? [];
		if (!is_array($assets) || !array_is_list($assets)) {
			return null;
		}

		$matchingAssets = [];
		$shaUrl = null;
		/** @var mixed $asset */
		foreach ($assets as $asset) {
			if (!is_array($asset)) {
				continue;
			}
			/** @var mixed $name */
			$name = $asset['name'] ?? '';
			/** @var mixed $url */
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

		/** @var mixed $tag */
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

		usort(
			$unique,
			/**
			 * @param array{version: string} $a
			 * @param array{version: string} $b
			 */
			static fn (array $a, array $b): int => version_compare($b['version'], $a['version'])
		);

		return $unique;
	}

	private function humanizeError(Forge $forge, string $raw): string {
		$name = $this->forgeName($forge);
		if (stripos($raw, 'rate limit') !== false) {
			return $name . ' rate limit exceeded — try again later, or configure a PAT.';
		}
		$host = parse_url($forge->apiBaseUrl, PHP_URL_HOST);
		if (is_string($host) && stripos($raw, 'could not resolve host') !== false) {
			return sprintf('Could not reach %s — check network connectivity.', $host);
		}

		return $name . ' API request failed.';
	}

	/**
	 * Human display name for a forge, used in error messages.
	 */
	private function forgeName(Forge $forge): string {
		return $forge->id === ForgeRegistry::FORGE_GITHUB ? 'GitHub' : ucfirst($forge->id);
	}
}
