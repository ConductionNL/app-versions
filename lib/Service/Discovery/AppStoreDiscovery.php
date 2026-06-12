<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Discovery;

use Exception;
use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Source\SourceBinding;
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
	private const ENDPOINT = 'https://garm3.nextcloud.com/api/v1/apps.json';

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

		try {
			$this->config->setValueString(Application::APP_ID, self::CACHE_KEY, json_encode($catalog, JSON_THROW_ON_ERROR));
			$this->config->setValueInt(Application::APP_ID, self::CACHE_TS_KEY, $now);
		} catch (\JsonException $error) {
			$this->logger->warning('AppStoreDiscovery: could not cache catalog', ['errorMessage' => $error->getMessage()]);
		}

		return $catalog;
	}

	/**
	 * @return list<array<array-key, mixed>>|null
	 */
	private function fetchCatalog(): ?array {
		try {
			$response = $this->clientService->newClient()->get(self::ENDPOINT, [
				'timeout' => 30,
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
