<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Discovery;

use Exception;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Searches the Nextcloud App Store catalog by case-insensitive substring match
 * across name, summary, description, and categories. The catalog payload is
 * cached for 1 hour to avoid round-tripping for every keystroke.
 */
class AppStoreDiscovery implements DiscoveryProviderInterface {
	public const ID = 'appstore';
	private const CACHE_KEY = 'cache.appstore_catalog';
	private const CACHE_TS_KEY = 'cache.appstore_catalog_ts';
	private const CACHE_TTL_SECONDS = 3600;
	private const DEFAULT_API_BASE = 'https://garm3.nextcloud.com/api/v1';

	/**
	 * How long to allow the catalogue download to take.
	 *
	 * The endpoint answers with the entire App Store — ~12.4 MB measured on
	 * 2026-07-24. A 30 s budget aborted at roughly 5 MB on an ordinary
	 * connection (`cURL error 28`), which made every search silently return no
	 * App Store results. The download only has to succeed once per
	 * `CACHE_TTL_SECONDS`, so allow enough time for a slow link to finish it.
	 */
	private const FETCH_TIMEOUT_SECONDS = 180;

	/**
	 * Fields of a catalogue entry that discovery actually reads (matching,
	 * scoring and hit construction). Everything else — chiefly the per-release
	 * metadata that dominates the payload — is dropped before caching.
	 */
	private const CACHED_FIELDS = ['id', 'name', 'summary', 'description', 'categories', 'preview', 'website'];

	/**
	 * Upper bound for the cached catalogue projection.
	 *
	 * Nextcloud loads *every* config value of an app in one go, so an oversized
	 * entry here slows down each request the app makes — caching the raw ~30 MB
	 * catalogue once made an unrelated version listing take minutes. The
	 * projection is ~2 orders of magnitude smaller; this cap is a backstop that
	 * also self-heals instances that already stored the unprojected blob.
	 */
	private const MAX_CACHE_BYTES = 4_000_000;

	/**
	 * @psalm-api
	 */
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $config,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'Nextcloud App Store';
	}

	public function isEnabled(): bool {
		return true;
	}

	/**
	 * Substring-searches the cached App Store catalog; see "App Store discovery".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function search(string $query): DiscoveryResult {
		$catalog = $this->loadCatalog();
		if ($catalog === null) {
			return DiscoveryResult::failed('Could not fetch App Store catalog.');
		}

		$needle = mb_strtolower(trim($query));
		if ($needle === '') {
			return DiscoveryResult::empty();
		}

		$hits = [];
		foreach ($catalog as $app) {
			/** @var mixed $id */
			$id = $app['id'] ?? null;
			if (!is_string($id) || $id === '') {
				continue;
			}

			if (!$this->matches($app, $needle)) {
				continue;
			}

			$hits[] = new DiscoveryHit(
				appId: $id,
				name: $this->stringField($app, 'name', $id),
				summary: $this->stringField($app, 'summary', ''),
				iconUrl: $this->stringField($app, 'preview', '') ?: null,
				sourceProviderId: self::ID,
				sourceBinding: ['kind' => SourceBinding::KIND_APPSTORE],
				installable: true,
				installableReason: null,
				homepageUrl: $this->stringField($app, 'website', '') ?: null,
			);

			if (count($hits) >= 200) {
				break;
			}
		}

		usort($hits, fn (DiscoveryHit $a, DiscoveryHit $b): int => $this->scoreHit($needle, $b) <=> $this->scoreHit($needle, $a));

		return new DiscoveryResult($hits, null);
	}

	/**
	 * @return list<array<array-key, mixed>>|null
	 */
	private function loadCatalog(): ?array {
		$now = $this->timeFactory->getTime();
		$cachedTs = $this->config->getValueInt(Application::APP_ID, self::CACHE_TS_KEY, 0);
		if ($cachedTs > 0 && ($now - $cachedTs) < self::CACHE_TTL_SECONDS) {
			$cached = $this->config->getValueString(Application::APP_ID, self::CACHE_KEY, '');
			if (strlen($cached) > self::MAX_CACHE_BYTES) {
				// Written by an older build that cached the unprojected catalogue.
				// Drop it rather than keep paying for it on every config read.
				$this->discardOversizedCache(strlen($cached));
				$cached = '';
			}
			if ($cached !== '') {
				try {
					/** @var mixed $decoded */
					$decoded = json_decode($cached, true, 32, JSON_THROW_ON_ERROR);
					if (is_array($decoded)) {
						return array_values(array_filter($decoded, 'is_array'));
					}
				} catch (\JsonException) {
					// fall through to refetch
				}
			}
		}

		$catalog = $this->fetchCatalog();
		if ($catalog === null) {
			return null;
		}

		// Only the fields discovery reads are kept — see CACHED_FIELDS.
		$projected = array_map([$this, 'projectForCache'], $catalog);

		try {
			$encoded = json_encode($projected, JSON_THROW_ON_ERROR);
			if (strlen($encoded) <= self::MAX_CACHE_BYTES) {
				$this->config->setValueString(Application::APP_ID, self::CACHE_KEY, $encoded);
				$this->config->setValueInt(Application::APP_ID, self::CACHE_TS_KEY, $now);
			} else {
				$this->logger->warning('AppStoreDiscovery: catalog projection too large to cache', [
					'bytes' => strlen($encoded),
				]);
			}
		} catch (\JsonException $error) {
			$this->logger->warning('AppStoreDiscovery: could not cache catalog', ['errorMessage' => $error->getMessage()]);
		}

		return $projected;
	}

	/**
	 * Removes a cache entry that is too large to keep, so the next call refetches
	 * and stores a projection instead.
	 */
	private function discardOversizedCache(int $bytes): void {
		$this->logger->warning('AppStoreDiscovery: discarding oversized catalog cache', ['bytes' => $bytes]);
		$this->config->deleteKey(Application::APP_ID, self::CACHE_KEY);
		$this->config->deleteKey(Application::APP_ID, self::CACHE_TS_KEY);
	}

	/**
	 * @param array<array-key, mixed> $app
	 * @return array<array-key, mixed>
	 */
	private function projectForCache(array $app): array {
		$projected = [];
		foreach (self::CACHED_FIELDS as $field) {
			if (array_key_exists($field, $app)) {
				/** @var mixed $value */
				$value = $app[$field];
				$projected[$field] = $value;
			}
		}

		return $projected;
	}

	/**
	 * @return list<array<array-key, mixed>>|null
	 */
	/**
	 * The App Store catalogue endpoint.
	 *
	 * Honours the SAME `appstore.api_base` override `AppStoreSource` reads.
	 * This class hard-coded the public store, so the two halves of "talk to the
	 * App Store" disagreed: an instance pointed at a mirror (or, in e2e, at a
	 * fixture) got its VERSION LISTINGS from the mirror and its DISCOVERY from
	 * garm3.nextcloud.com anyway. On an air-gapped or restricted network that
	 * is not a visible misconfiguration — search just returns nothing, which is
	 * indistinguishable from "no apps matched".
	 *
	 * One key should govern both paths, which is what the config's own
	 * documentation already implies.
	 */
	private function endpoint(): string {
		/** @var string|null $raw */
		$raw = $this->config->getValueString(Application::APP_ID, 'appstore.api_base', '');
		$override = trim((string)$raw);

		return rtrim($override !== '' ? $override : self::DEFAULT_API_BASE, '/') . '/apps.json';
	}

	/**
	 * @return list<array<array-key, mixed>>|null
	 */
	private function fetchCatalog(): ?array {
		try {
			$response = $this->clientService->newClient()->get($this->endpoint(), [
				'timeout' => self::FETCH_TIMEOUT_SECONDS,
				'http_errors' => false,
			]);
		} catch (Exception $error) {
			$this->logger->warning('AppStoreDiscovery: catalog fetch failed', ['errorMessage' => $error->getMessage()]);

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

		if (!is_array($decoded)) {
			return null;
		}

		// The endpoint returns either a list of apps or an envelope; flatten both shapes.
		if (array_is_list($decoded)) {
			return array_values(array_filter($decoded, 'is_array'));
		}
		if (isset($decoded['apps']) && is_array($decoded['apps'])) {
			return array_values(array_filter($decoded['apps'], 'is_array'));
		}
		if (isset($decoded['data']) && is_array($decoded['data'])) {
			return array_values(array_filter($decoded['data'], 'is_array'));
		}

		return null;
	}

	/**
	 * @param array<array-key, mixed> $app
	 */
	private function matches(array $app, string $needle): bool {
		foreach (['id', 'name', 'summary', 'description'] as $field) {
			/** @var mixed $raw */
			$raw = $app[$field] ?? '';
			if (is_string($raw) && str_contains(mb_strtolower($raw), $needle)) {
				return true;
			}
		}

		/** @var mixed $categories */
		$categories = $app['categories'] ?? [];
		if (is_array($categories)) {
			/** @var mixed $cat */
			foreach ($categories as $cat) {
				if (is_string($cat) && str_contains(mb_strtolower($cat), $needle)) {
					return true;
				}
			}
		}

		return false;
	}

	private function scoreHit(string $needle, DiscoveryHit $hit): int {
		$id = mb_strtolower($hit->appId);
		$name = mb_strtolower($hit->name);
		if ($id === $needle || $name === $needle) {
			return 100;
		}
		if (str_starts_with($id, $needle) || str_starts_with($name, $needle)) {
			return 50;
		}

		return 10;
	}

	/**
	 * @param array<array-key, mixed> $app
	 */
	private function stringField(array $app, string $key, string $default): string {
		/** @var mixed $value */
		$value = $app[$key] ?? null;

		return is_string($value) ? trim($value) : $default;
	}
}
