<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Discovery;

use Exception;
use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
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

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
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
			if (!is_array($app)) {
				continue;
			}
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
	 * @return list<array<string, mixed>>|null
	 */
	private function loadCatalog(): ?array {
		$now = $this->timeFactory->getTime();
		$cachedTs = (int)$this->config->getAppValue(Application::APP_ID, self::CACHE_TS_KEY, '0');
		if ($cachedTs > 0 && ($now - $cachedTs) < self::CACHE_TTL_SECONDS) {
			$cached = $this->config->getAppValue(Application::APP_ID, self::CACHE_KEY, '');
			if ($cached !== '') {
				try {
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
			$this->config->setAppValue(Application::APP_ID, self::CACHE_KEY, json_encode($catalog, JSON_THROW_ON_ERROR));
			$this->config->setAppValue(Application::APP_ID, self::CACHE_TS_KEY, (string)$now);
		} catch (\JsonException $error) {
			$this->logger->warning('AppStoreDiscovery: could not cache catalog', ['errorMessage' => $error->getMessage()]);
		}

		return $catalog;
	}

	/**
	 * @return list<array<string, mixed>>|null
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
	 * @param array<string, mixed> $app
	 */
	private function matches(array $app, string $needle): bool {
		foreach (['id', 'name', 'summary', 'description'] as $field) {
			$value = $app[$field] ?? '';
			if (is_string($value) && str_contains(mb_strtolower($value), $needle)) {
				return true;
			}
		}

		$categories = $app['categories'] ?? [];
		if (is_array($categories)) {
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
	 * @param array<string, mixed> $app
	 */
	private function stringField(array $app, string $key, string $default): string {
		$value = $app[$key] ?? null;

		return is_string($value) ? trim($value) : $default;
	}
}
