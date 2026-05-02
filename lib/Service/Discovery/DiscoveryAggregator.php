<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Discovery;

use Exception;
use OCP\App\IAppManager;

/**
 * Runs every enabled discovery provider for a query, de-duplicates hits by
 * `appId`, and emits a uniform result the controller turns into a JSON
 * response.
 *
 * The aggregator is intentionally simple: synchronous PHP iteration over
 * providers. If a provider becomes too slow we swap in `IClient::getMulti`
 * later — for now total wall time is acceptable for an admin-facing search.
 */
class DiscoveryAggregator {
	/** @var list<DiscoveryProviderInterface> */
	private array $providers;

	public function __construct(
		private IAppManager $appManager,
		AppStoreDiscovery $appStore,
		GithubPrivateDiscovery $githubPrivate,
		GithubSearchDiscovery $githubSearch,
	) {
		$this->providers = [$appStore, $githubPrivate, $githubSearch];
	}

	/**
	 * @return list<array{id: string, label: string, enabled: bool}>
	 */
	public function listProviders(): array {
		return array_map(
			static fn (DiscoveryProviderInterface $p): array => [
				'id' => $p->getId(),
				'label' => $p->getLabel(),
				'enabled' => $p->isEnabled(),
			],
			$this->providers
		);
	}

	/**
	 * @param list<string>|null $sourceIds when null, all enabled providers run
	 * @return array{results: list<array<string, mixed>>, providers: list<array{id: string, label: string, enabled: bool}>, errors: list<array{providerId: string, message: string}>}
	 */
	public function search(string $query, ?array $sourceIds = null, bool $installedOnly = false): array {
		$active = $this->resolveActive($sourceIds);
		$installed = $this->snapshotInstalled();

		$grouped = [];
		$errors = [];

		foreach ($active as $provider) {
			try {
				$result = $provider->search($query);
			} catch (Exception $error) {
				$errors[] = ['providerId' => $provider->getId(), 'message' => $error->getMessage()];
				continue;
			}

			if ($result->error !== null) {
				$errors[] = ['providerId' => $provider->getId(), 'message' => $result->error];
			}

			foreach ($result->hits as $hit) {
				$key = $hit->appId;
				if (!isset($grouped[$key])) {
					$grouped[$key] = [
						'appId' => $hit->appId,
						'name' => $hit->name,
						'summary' => $hit->summary,
						'iconUrl' => $hit->iconUrl,
						'homepageUrl' => $hit->homepageUrl,
						'installedVersion' => $installed[$hit->appId] ?? null,
						'sourceCandidates' => [],
					];
				}

				// Prefer App Store summary/icon when present (best metadata) but never overwrite to empty.
				if ($hit->sourceProviderId === AppStoreDiscovery::ID) {
					if ($hit->summary !== '') {
						$grouped[$key]['summary'] = $hit->summary;
					}
					if ($hit->iconUrl !== null) {
						$grouped[$key]['iconUrl'] = $hit->iconUrl;
					}
				}

				$grouped[$key]['sourceCandidates'][] = [
					'providerId' => $hit->sourceProviderId,
					'sourceBinding' => $hit->sourceBinding,
					'installable' => $hit->installable,
					'installableReason' => $hit->installableReason,
				];
			}
		}

		$results = array_values($grouped);

		if ($installedOnly) {
			$results = array_values(array_filter($results, static fn (array $r): bool => $r['installedVersion'] !== null));
		}

		usort($results, static function (array $a, array $b): int {
			$aInstalled = $a['installedVersion'] !== null ? 1 : 0;
			$bInstalled = $b['installedVersion'] !== null ? 1 : 0;
			if ($aInstalled !== $bInstalled) {
				return $bInstalled - $aInstalled;
			}

			return strcmp($a['name'], $b['name']);
		});

		return [
			'results' => $results,
			'providers' => $this->listProviders(),
			'errors' => $errors,
		];
	}

	/**
	 * @param list<string>|null $sourceIds
	 * @return list<DiscoveryProviderInterface>
	 */
	private function resolveActive(?array $sourceIds): array {
		if ($sourceIds === null || $sourceIds === []) {
			return array_values(array_filter($this->providers, static fn (DiscoveryProviderInterface $p): bool => $p->isEnabled()));
		}

		$wanted = array_flip($sourceIds);

		return array_values(array_filter(
			$this->providers,
			static fn (DiscoveryProviderInterface $p): bool => isset($wanted[$p->getId()]) && $p->isEnabled()
		));
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshotInstalled(): array {
		$installed = [];
		foreach ($this->appManager->getInstalledApps() as $appId) {
			try {
				$installed[$appId] = $this->appManager->getAppVersion($appId);
			} catch (Exception) {
				$installed[$appId] = '';
			}
		}

		return $installed;
	}
}
