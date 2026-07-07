<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\AppVersions\Service\Discovery;

use Exception;
use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Opt-in public GitHub repository search restricted to repos with the
 * `nextcloud-app` topic OR matching the query in name/description. Disabled
 * by default; flips on via `app_versions.discovery.github_search_enabled`.
 *
 * Sends every search query to GitHub. Admin must opt in consciously.
 */
class GithubSearchDiscovery implements DiscoveryProviderInterface {
	public const ID = 'github-search';
	private const ENABLED_KEY = 'discovery.github_search_enabled';
	private const SEARCH_ENDPOINT = 'https://api.github.com/search/repositories';
	private const USER_AGENT = 'Nextcloud-AppVersions';

	/**
	 * @psalm-api
	 */
	public function __construct(
		private IAppConfig $config,
		private TrustedSourceList $trustedSources,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'GitHub (public search)';
	}

	public function isEnabled(): bool {
		return $this->config->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false') === 'true';
	}

	/**
	 * Opt-in public GitHub topic search with rate-limit handling; see "GitHub public search (opt-in)".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function search(string $query): DiscoveryResult {
		if (!$this->isEnabled()) {
			return DiscoveryResult::empty();
		}

		$query = trim($query);
		if ($query === '') {
			return DiscoveryResult::empty();
		}

		$q = $query . ' topic:nextcloud-app fork:false';
		$url = self::SEARCH_ENDPOINT . '?' . http_build_query(['q' => $q, 'per_page' => 30]);

		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => [
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => self::USER_AGENT,
					'X-GitHub-Api-Version' => '2022-11-28',
				],
				'timeout' => 30,
				'http_errors' => false,
			]);
		} catch (Exception $error) {
			$this->logger->warning('GithubSearchDiscovery: search failed', ['errorMessage' => $error->getMessage()]);

			return DiscoveryResult::failed('Could not reach GitHub search.');
		}

		$status = $response->getStatusCode();
		if ($status === 403) {
			return DiscoveryResult::failed('GitHub search rate limit exceeded — try again later.');
		}
		if ($status !== 200) {
			return DiscoveryResult::failed(sprintf('GitHub search returned HTTP %d.', $status));
		}

		try {
			/** @var mixed $decoded */
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return DiscoveryResult::failed('GitHub search returned malformed JSON.');
		}

		if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
			return DiscoveryResult::empty();
		}

		$hits = [];
		/** @var mixed $repo */
		foreach ($decoded['items'] as $repo) {
			if (!is_array($repo)) {
				continue;
			}
			$hit = $this->buildHit($repo);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return new DiscoveryResult($hits, null);
	}

	/**
	 * @param array<array-key, mixed> $repo
	 */
	private function buildHit(array $repo): ?DiscoveryHit {
		/** @var mixed $rawFullName */
		$rawFullName = $repo['full_name'] ?? '';
		if (!is_string($rawFullName)) {
			return null;
		}
		$fullName = $rawFullName;
		if (!str_contains($fullName, '/')) {
			return null;
		}
		$parts = explode('/', $fullName, 2);
		$owner = $parts[0];
		$repoName = $parts[1] ?? '';

		$sourceId = 'github:' . $fullName;
		$installable = $this->trustedSources->isAllowed($sourceId);
		$reason = $installable
			? null
			: sprintf('Add `%s/*` to the trusted-source allowlist to install this app.', $owner);

		/** @var mixed $repoNameValue */
		$repoNameValue = $repo['name'] ?? null;
		/** @var mixed $descriptionValue */
		$descriptionValue = $repo['description'] ?? null;
		/** @var mixed $htmlUrlValue */
		$htmlUrlValue = $repo['html_url'] ?? null;

		return new DiscoveryHit(
			appId: strtolower(str_replace('-', '_', $repoName)),
			name: is_string($repoNameValue) ? $repoNameValue : $repoName,
			summary: is_string($descriptionValue) ? $descriptionValue : '',
			iconUrl: null,
			sourceProviderId: self::ID,
			sourceBinding: [
				'kind' => SourceBinding::KIND_GITHUB_RELEASE,
				'owner' => $owner,
				'repo' => $repoName,
			],
			installable: $installable,
			installableReason: $reason,
			homepageUrl: is_string($htmlUrlValue) ? $htmlUrlValue : null,
		);
	}
}
