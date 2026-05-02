<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Discovery;

use Exception;
use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Searches for installable apps in private GitHub repos that the current
 * admin's PATs can see. Each PAT scopes the search to its `target_pattern`
 * via the GitHub Search API's `org:` / `repo:` qualifier.
 *
 * Implementation note: instead of `/search/code` (slow + costly), we use
 * `/search/repositories` with a topic filter. Repos that don't yet have
 * the `nextcloud-app` topic but DO match the query string still surface;
 * the `installable` flag only flips false when the repo is outside the
 * trusted-source allowlist.
 */
class GithubPrivateDiscovery implements DiscoveryProviderInterface {
	public const ID = 'github-private';
	private const SEARCH_ENDPOINT = 'https://api.github.com/search/repositories';
	private const USER_AGENT = 'Nextcloud-AppVersions';

	public function __construct(
		private PatMapper $patMapper,
		private PatManager $patManager,
		private TrustedSourceList $trustedSources,
		private IUserSession $userSession,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'GitHub (private, your PATs)';
	}

	public function isEnabled(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->patMapper->findVisibleTo($user->getUID()) !== [];
	}

	public function search(string $query): DiscoveryResult {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return DiscoveryResult::failed('Not authenticated.');
		}

		$pats = $this->patMapper->findVisibleTo($user->getUID());
		if ($pats === []) {
			return DiscoveryResult::empty();
		}

		$hits = [];
		$lastError = null;
		foreach ($pats as $pat) {
			try {
				$patHits = $this->searchOne($pat, $query);
				$hits = array_merge($hits, $patHits);
			} catch (Exception $error) {
				$this->logger->warning('GithubPrivateDiscovery: search failed for PAT', [
					'patId' => $pat->getId(),
					'errorMessage' => $error->getMessage(),
				]);
				$lastError = 'One or more PAT-scoped searches failed (' . $error->getMessage() . ').';
			}
		}

		// De-duplicate by owner/repo within this provider's results.
		$seen = [];
		$unique = [];
		foreach ($hits as $hit) {
			$key = $hit->sourceBinding['owner'] . '/' . $hit->sourceBinding['repo'];
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$unique[] = $hit;
		}

		return new DiscoveryResult($unique, $unique === [] ? $lastError : null);
	}

	/**
	 * @return list<DiscoveryHit>
	 */
	private function searchOne(Pat $pat, string $query): array {
		$scopeClause = $this->buildScopeClause($pat->getTargetPattern());
		if ($scopeClause === null) {
			return [];
		}

		$q = trim($query) . ' ' . $scopeClause . ' fork:false';
		$url = self::SEARCH_ENDPOINT . '?' . http_build_query(['q' => $q, 'per_page' => 30]);

		/** @var array<int, array<string, mixed>>|null $items */
		$items = $this->patManager->useToken($pat, function (string $token) use ($url): ?array {
			try {
				$response = $this->clientService->newClient()->get($url, [
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Accept' => 'application/vnd.github+json',
						'User-Agent' => self::USER_AGENT,
						'X-GitHub-Api-Version' => '2022-11-28',
					],
					'timeout' => 30,
					'http_errors' => false,
				]);
			} catch (Exception) {
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

			if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
				return null;
			}

			$out = [];
			foreach ($decoded['items'] as $item) {
				if (is_array($item)) {
					$out[] = $item;
				}
			}

			return $out;
		});

		if ($items === null) {
			return [];
		}

		$hits = [];
		foreach ($items as $repo) {
			$hit = $this->buildHit($repo);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}

	/**
	 * Maps a `target_pattern` glob to a GitHub Search qualifier.
	 *   ConductionNL/*           → org:ConductionNL
	 *   ConductionNL/openregister → repo:ConductionNL/openregister
	 *   *                         → null (impossible to scope safely)
	 */
	private function buildScopeClause(string $pattern): ?string {
		$pattern = trim($pattern);
		if ($pattern === '' || $pattern === '*' || $pattern === '*/*') {
			return null;
		}

		if (str_ends_with($pattern, '/*')) {
			$owner = substr($pattern, 0, -2);
			if ($owner === '' || str_contains($owner, '*')) {
				return null;
			}

			return 'org:' . $owner . ' user:' . $owner;
		}

		if (str_contains($pattern, '/') && !str_contains($pattern, '*')) {
			return 'repo:' . $pattern;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $repo
	 */
	private function buildHit(array $repo): ?DiscoveryHit {
		$fullName = $repo['full_name'] ?? '';
		if (!is_string($fullName) || !str_contains($fullName, '/')) {
			return null;
		}
		[$owner, $repoName] = explode('/', $fullName, 2);

		$sourceId = 'github:' . $fullName;
		$installable = $this->trustedSources->isAllowed($sourceId);
		$reason = $installable
			? null
			: sprintf('Add `%s/*` to the trusted-source allowlist to install from this repo.', $owner);

		return new DiscoveryHit(
			appId: $this->guessAppId($repoName),
			name: is_string($repo['name'] ?? null) ? $repo['name'] : $repoName,
			summary: is_string($repo['description'] ?? null) ? (string)$repo['description'] : '',
			iconUrl: null,
			sourceProviderId: self::ID,
			sourceBinding: [
				'kind' => SourceBinding::KIND_GITHUB_RELEASE,
				'owner' => $owner,
				'repo' => $repoName,
			],
			installable: $installable,
			installableReason: $reason,
			homepageUrl: is_string($repo['html_url'] ?? null) ? (string)$repo['html_url'] : null,
		);
	}

	private function guessAppId(string $repoName): string {
		// GitHub repos use kebab-case; Nextcloud appIds are typically snake_case.
		// Both lowercase + replace `-` with `_` lands on the right id for most ConductionNL apps.
		return strtolower(str_replace('-', '_', $repoName));
	}
}
